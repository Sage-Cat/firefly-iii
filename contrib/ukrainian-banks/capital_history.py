"""Import dated balance valuations into account notes without ledger mutations."""

import argparse
import copy
import fcntl
import hashlib
import json
import re
from datetime import datetime, timedelta
from decimal import Decimal, InvalidOperation
from pathlib import Path
from zoneinfo import ZoneInfo

MARKER = re.compile(r'<!-- firefly-balance-history:v1\s*\n(.*?)\n-->', re.S)
CURRENCY = re.compile(r'[A-Z][A-Z0-9]{2,11}\Z')


def restrict_history(snapshots, start):
    """Discard earlier dates, carrying their last valuation into the boundary.

    A carried valuation is an opening stock, never a ledger flow. Keeping it
    preserves all later snapshot-based balances without exposing old history.
    """
    boundary = date_value(start)
    if boundary.date().isoformat() != start:
        raise ValueError('History start requires a calendar date')
    result = {}
    for account, rows in snapshots.items():
        retained = [copy.deepcopy(row) for row in rows if row['day'] >= start]
        prior = [row for row in rows if row['day'] < start]
        if prior and not any(row['day'] == start for row in retained):
            opening = copy.deepcopy(max(prior, key=lambda row: row['day']))
            opening.update(day=start, at=boundary.isoformat(), opening_valuation=True)
            retained.insert(0, opening)
        result[account] = sorted(retained, key=lambda row: row['day'])
    return result


def digest(text):
    return hashlib.sha256(text.encode()).hexdigest()


def date_value(value):
    if isinstance(value, bool):
        raise ValueError('Invalid snapshot date')
    if isinstance(value, (int, float, Decimal)):
        seconds = int((Decimal(str(value)) * 86400).to_integral_value())
        return (datetime(1899, 12, 30) + timedelta(seconds=seconds)).replace(tzinfo=ZoneInfo('Europe/Kyiv'))
    try:
        result = datetime.fromisoformat(value)
    except (ValueError, TypeError):
        raise ValueError('Invalid snapshot date') from None
    return result.replace(tzinfo=ZoneInfo('Europe/Kyiv')) if result.tzinfo is None else result.astimezone(ZoneInfo('Europe/Kyiv'))


def journal(values, formulas, bindings, today):
    snapshots, excluded = {}, []
    for index, row in enumerate(values[1:], 2):
        if len(row) < 4 or row[3] in ('', None):
            continue
        formula = formulas[index - 1][0] if len(formulas) >= index and formulas[index - 1] else ''
        if isinstance(formula, str) and formula.startswith('='):
            excluded.append({'row': index, 'reason': 'dynamic_date', 'formula': formula})
            continue
        at = date_value(row[0])
        if at.date().isoformat() > today:
            raise ValueError('Future snapshot date')
        key = row[1] + '|' + row[2]
        if key not in bindings:
            raise ValueError('Unmapped source account: ' + key)
        if not CURRENCY.fullmatch(row[2]):
            raise ValueError('Invalid snapshot currency')
        try:
            amount = Decimal(str(row[3]))
        except InvalidOperation:
            raise ValueError('Invalid snapshot amount') from None
        if not amount.is_finite():
            raise ValueError('Invalid snapshot amount')
        account = str(bindings[key])
        item = {'day': at.date().isoformat(), 'at': at.isoformat(), 'currency': row[2],
                'amount': format(amount, 'f'), 'source_row': index}
        bucket = snapshots.setdefault(account, {})
        old = bucket.get(item['day'])
        if old is None or (at.timestamp(), index) > (datetime.fromisoformat(old['at']).timestamp(), old['source_row']):
            bucket[item['day']] = item
    return {key: [rows[day] for day in sorted(rows)] for key, rows in snapshots.items()}, excluded


def plan(accounts, snapshots, coverage, source):
    owned = {str(row['id']): row['attributes'] for row in accounts}
    if (set(snapshots) | set(coverage)) - set(owned):
        raise ValueError('History target is not an owned asset account')
    changes = []
    for account in sorted(set(snapshots) | set(coverage), key=int):
        data = owned[account]
        evidence = coverage.get(account)
        native = data.get('opening_balance_date')
        ledger_from = evidence['day'] if evidence else native[:10] if native else None
        if ledger_from:
            datetime.strptime(ledger_from, '%Y-%m-%d')
        metadata = {'version': 1, 'source': source, 'snapshots': snapshots.get(account, [])}
        if ledger_from:
            metadata['ledger_from'] = ledger_from
        if evidence:
            if not evidence.get('proof'):
                raise ValueError('Coverage date requires evidence')
            metadata['coverage_evidence'] = evidence['proof']
        notes = data.get('notes') or ''
        if len(MARKER.findall(notes)) > 1:
            raise ValueError('Duplicate balance history metadata')
        block = '<!-- firefly-balance-history:v1\n' + json.dumps(metadata, ensure_ascii=False, separators=(',', ':')) + '\n-->'
        base = MARKER.sub('', notes).strip(' \t\n\r\0\x0b')
        after = base + ('\n\n' if base else '') + block
        if len(after) > 32768:
            raise ValueError('Account notes exceed the native API limit')
        changes.append({'account_id': account, 'name': data['name'], 'before_hash': digest(notes),
                        'after_hash': digest(after), 'notes': after, 'snapshot_count': len(metadata['snapshots']),
                        'ledger_from': ledger_from, 'changed': notes != after})
    return changes


def apply(client, changes, save):
    for change in changes:
        account = change['account_id']
        if not isinstance(account, str) or not account.isdecimal():
            raise ValueError('Invalid target account ID')
        current = client.request('GET', '/accounts/' + account)['data']['attributes']
        before = current.get('notes') or ''
        if digest(before) == change['after_hash']:
            save(account, 'reused')
            continue
        if digest(before) != change['before_hash']:
            raise RuntimeError('Account notes changed after planning: ' + account)
        client.request('PUT', '/accounts/' + account, {'notes': change['notes']}, notes_hash=digest(before))
        verify = client.request('GET', '/accounts/' + account)['data']['attributes']
        if digest(verify.get('notes') or '') != change['after_hash']:
            raise RuntimeError('History notes failed readback: ' + account)
        save(account, 'updated')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['plan', 'apply'])
    parser.add_argument('--state', type=Path, default=Path('.state/capital-history'))
    args = parser.parse_args()
    from firefly_api import Client
    from monobank_sync import read_json, save_json
    client = Client()
    if args.action == 'plan':
        values = read_json(args.state / 'values.json')['valueRanges'][0]['values']
        formulas = read_json(args.state / 'formulas.json')['values']
        config = read_json(args.state / 'config.json')
        snapshots, excluded = journal(values, formulas, config['bindings'], config['today'])
        if config.get('start_date'):
            snapshots = restrict_history(snapshots, config['start_date'])
        accounts = client.all('/accounts?type=asset')
        changes = plan(accounts, snapshots, config['coverage'], config['source'])
        save_json(args.state / 'plan.json', {'changes': changes, 'excluded': excluded})
        print(json.dumps({'accounts': len(changes), 'snapshots': sum(x['snapshot_count'] for x in changes), 'excluded': excluded}, ensure_ascii=False))
    else:
        changes = read_json(args.state / 'plan.json')['changes']
        results = read_json(args.state / 'apply.json') if (args.state / 'apply.json').exists() else {}
        def save(account, status):
            results[account] = status
            save_json(args.state / 'apply.json', results)
        lock_path = args.state.parent / 'monobank/sync.lock'
        lock_path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        with lock_path.open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            apply(client, changes, save)
        print('History metadata verified for', len(results), 'accounts')


if __name__ == '__main__':
    main()
