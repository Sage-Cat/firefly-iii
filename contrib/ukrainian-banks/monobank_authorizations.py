"""Track provisional monobank spending without duplicating settlement or release."""

import copy
import json
from datetime import datetime

from bank_refunds import can_pair, cancellation_vendor
from bank_refunds import normalized
from bank_review import matching_journal, note_object
from monobank_sync import Deferred, KYIV, amount, external_key, minor, money, statement_file

PENDING_TAG = 'monobank: очікує підтвердження'


def authorization(journal):
    note = note_object(journal.get('notes'))
    data = note.get('monobank_authorization')
    if data is None:
        if PENDING_TAG in (journal.get('tags') or []):
            raise Deferred('Managed pending authorization lost its protocol notes')
        return None
    if (not isinstance(data, dict) or data.get('version') != 1
            or data.get('status') not in {'pending', 'settled'}
            or journal.get('type') != 'withdrawal' or journal.get('internal_reference') != 'monobank'
            or data.get('key') != journal.get('external_id')
            or not str(data.get('key', '')).startswith('monobank:')
            or str(data.get('account_id')) != str(journal.get('source_id'))
            or data.get('currency') != journal.get('currency_code')
            or money(data.get('amount')) != money(journal.get('amount'))
            or type(data.get('bank_time')) is not int
            or datetime.fromisoformat(journal['date']).timestamp() != data['bank_time']
            or note.get('hold') is not (data['status'] == 'pending')):
        raise Deferred('Managed monobank authorization was changed; review before synchronization')
    return data


def managed_pending(journal):
    try:
        data = authorization(journal)
        return bool(data and data['status'] == 'pending')
    except (Deferred, KeyError, TypeError, ValueError):
        return False


def cancellation_pairs(records, groups, assets):
    """Require uniqueness among all original payments, including settled competitors."""
    journals = {}
    for group in groups:
        for j in group['attributes']['transactions']:
            if j.get('internal_reference') == 'monobank' and j.get('external_id'):
                journals[j['external_id']] = copy.deepcopy(j)
    for record in records:
        item, binding = record['item'], record['binding']
        if not item['amount']:
            continue
        own = binding['firefly_id']
        journals[record['key']] = {
            'type': 'withdrawal' if item['amount'] < 0 else 'deposit',
            'date': datetime.fromtimestamp(item['time'], KYIV).isoformat(),
            'amount': amount(abs(minor(item['amount']))),
            'currency_id': assets[own]['currency_id'],
            'source_id': own if item['amount'] < 0 else 'external',
            'destination_id': own if item['amount'] > 0 else 'external',
            'description': item.get('description', ''), 'external_id': record['key'],
            'internal_reference': 'monobank', 'notes': json.dumps({'hold': bool(item.get('hold'))})}
    originals = {key: dict(j, notes='{}') for key, j in journals.items() if j['type'] == 'withdrawal'}
    refunds = {key: j for key, j in journals.items() if cancellation_vendor(j)}
    matches = {key: [other for other, refund in refunds.items()
                     if can_pair(j, refund, cancellation_vendor(refund), assets)]
               for key, j in originals.items()}
    return {key: values[0] for key, values in matches.items() if len(values) == 1
            and sum(values[0] in v for v in matches.values()) == 1}


def annotate(transaction, item, own, code, existing=None, cancelled_by=None):
    old = authorization(existing) if existing else None
    if not item.get('hold') and old is None:
        return transaction
    if transaction['type'] != 'withdrawal' or item['amount'] >= 0:
        raise Deferred('Pending own transfers and incoming authorizations need separate confirmation')
    if existing and old is None:
        raise Deferred('A settled legacy movement became pending; review its bank identity')
    if old and old['status'] == 'settled' and item.get('hold') and not cancelled_by:
        raise Deferred('A settled authorization unexpectedly became pending again')
    tx = copy.deepcopy(transaction)
    notes = note_object(existing.get('notes')) if existing else note_object(tx.get('notes'))
    pending = bool(item.get('hold')) and cancelled_by is None
    data = dict(old or {}, version=1, key=tx['external_id'], account_id=own, currency=code,
                amount=tx['amount'], status='pending' if pending else 'settled', bank_time=item['time'])
    data.setdefault('authorized_at', item['time'])
    data.setdefault('merchant', transaction['description'])
    data['reported_hold'] = bool(item.get('hold'))
    if cancelled_by:
        data['cancelled_by'] = cancelled_by
    notes.update(hold=pending, monobank_authorization=data)
    tx['notes'] = json.dumps(notes, ensure_ascii=False)
    tx['tags'] = list(existing.get('tags') or []) if existing else list(tx.get('tags') or [])
    if pending and PENDING_TAG not in tx['tags']:
        tx['tags'].append(PENDING_TAG)
    if not pending:
        tx['tags'] = [tag for tag in tx['tags'] if tag != PENDING_TAG]
    if existing:
        tx['description'] = existing['description']
    return tx


def preserve_replacements(operations, retired, groups):
    """Carry owner choices only across a unique, exact bank-event replacement."""
    old_by_key = {r['journal']['external_id']: r['journal'] for r in retired}
    for op in operations:
        existing = matching_journal(groups, op['key'])
        data = authorization(existing) if existing else None
        previous = old_by_key.get((data or {}).get('replaces_key'))
        if previous:
            def owner_state(journal):
                notes = note_object(journal.get('notes'))
                notes.pop('hold', None)
                notes.pop('monobank_authorization', None)
                return (notes, sorted(t for t in journal.get('tags') or [] if t != PENDING_TAG),
                        tuple(journal.get(f) or '' for f in ('description', 'category_id', 'category_name',
                                                           'budget_id', 'budget_name', 'bill_id')))
            if owner_state(previous) != owner_state(existing):
                raise Deferred('Replacement and pending authorization have different owner metadata; review before release')
    candidates = {}
    for op in operations:
        tx = op['transaction']
        if tx['type'] != 'withdrawal' or note_object(tx.get('notes')).get('monobank_authorization'):
            continue
        matches = []
        for old in retired:
            j = old['journal']
            data = authorization(j)
            if (str(tx.get('source_id')) == data['account_id']
                    and str(tx.get('currency_id')) == str(j.get('currency_id'))
                    and money(tx['amount']) == money(j['amount'])
                    and datetime.fromisoformat(tx['date']).timestamp() == data['bank_time']
                    and normalized(tx['description']) == normalized(data.get('merchant', j['description']))):
                matches.append(old)
        candidates[op['key']] = matches
    for op in operations:
        matches = candidates.get(op['key'], [])
        if not matches:
            continue
        if len(matches) != 1 or sum(matches[0] in values for values in candidates.values()) != 1:
            notes = note_object(op['transaction'].get('notes'))
            notes['monobank_authorization_review'] = {'previous_journals': [m['journal']['transaction_journal_id'] for m in matches]}
            op['transaction']['notes'] = json.dumps(notes, ensure_ascii=False)
            continue
        old = matches[0]['journal']
        tx = op['transaction']
        data = authorization(old)
        item = {'time': int(datetime.fromisoformat(tx['date']).timestamp()),
                'amount': -int(money(tx['amount']) * 100), 'hold': False}
        tx = annotate(tx, item, data['account_id'], data['currency'], old)
        notes = note_object(tx['notes'])
        notes['monobank_authorization']['replaces_key'] = data['key']
        tx['notes'] = json.dumps(notes, ensure_ascii=False)
        for field in ('category_id', 'category_name', 'budget_id', 'budget_name', 'bill_id'):
            if old.get(field) is not None:
                tx[field] = old[field]
        op['transaction'] = tx
        op['replaces_authorization'] = matches[0]['group_id']


def missing(config, snapshot, records, groups, assets, cancellations):
    """Release only missing pending expenses covered by an explicit fresh bank window."""
    bindings = {b['firefly_id']: b for b in config['bindings']}
    present = {r['key'] for r in records}
    extra, retire = [], []
    for group in groups:
        for journal in group['attributes']['transactions']:
            data = authorization(journal)
            if not data or data['status'] != 'pending' or data['account_id'] not in bindings or data['key'] in present:
                continue
            if len(group['attributes']['transactions']) != 1:
                raise Deferred('Pending authorization was manually split')
            binding = bindings[data['account_id']]
            if data['currency'] != binding['currency_code']:
                raise Deferred('Pending authorization currency changed')
            envelope = snapshot['statements'][statement_file(binding['bank_id'])]
            cutoff = snapshot['client_info'].get('as_of', snapshot['client_info']['request_started_at'])
            if (envelope.get('account_id') != binding['bank_id'] or envelope['to'] != cutoff
                    or type(envelope.get('fetched_at')) is not int or envelope['fetched_at'] < cutoff):
                raise Deferred('Authorization release needs a coherent account-bound bank snapshot')
            bank_items = {external_key(binding['bank_id'], i['id']): i for i in envelope['items']}
            zeroed = data['key'] in bank_items and bank_items[data['key']]['amount'] == 0
            if not zeroed and not (type(envelope.get('refresh_from')) is int
                                   and envelope['refresh_from'] <= data['bank_time'] <= envelope['to']):
                raise Deferred('Missing pending authorization needs freshly covered statement evidence')
            if data['key'] in bank_items and not zeroed:
                raise Deferred('Pending authorization is unexpectedly absent from the normalized source')
            if data['key'] in cancellations:
                item = {'time': data['bank_time'], 'amount': -int(money(data['amount']) * 100), 'hold': False}
                desired = annotate(journal, item, data['account_id'], data['currency'], journal, cancellations[data['key']])
                extra.append({'key': data['key'], 'aliases': [data['key']], 'transaction': desired})
            else:
                retire.append({'group_id': group['id'], 'journal': copy.deepcopy(journal),
                               'reason': 'zeroed' if zeroed else 'absent_from_refreshed_statement',
                               'refresh_from': envelope.get('refresh_from'), 'to': envelope['to']})
    return extra, retire
