"""Reconcile confirmed movements between physical cards of one asset account."""

import copy
import hashlib
import json
import re
from collections import defaultdict
from datetime import datetime
from decimal import Decimal

from bank_review import is_suspense, journal_keys, review_of
from monobank_sync import Deferred, amount, effects, money

EVIDENCE_BLOCK = re.compile(r'<!-- firefly-internal-cards:v1\n(.*?)\n-->', re.DOTALL)


def evidence_records(notes):
    matches = list(EVIDENCE_BLOCK.finditer(notes))
    if len(matches) > 1:
        raise Deferred('Duplicate internal-card evidence blocks')
    if not matches:
        return {}
    try:
        data = json.loads(matches[0][1])
    except (ValueError, TypeError):
        raise Deferred('Invalid internal-card evidence block') from None
    if not isinstance(data, dict) or data.get('version') != 1 or not isinstance(data.get('records'), dict):
        raise Deferred('Unknown internal-card evidence structure')
    return data['records']


def with_evidence(notes, records):
    if evidence_records(notes) == records:
        return notes
    body = json.dumps({'version': 1, 'records': records}, ensure_ascii=False)
    # Keep unrelated raw notes and the dated capital-history block byte-for-byte.
    block = '<!-- firefly-internal-cards:v1\n' + body + '\n-->'
    matches = list(EVIDENCE_BLOCK.finditer(notes))
    if matches:
        match = matches[0]
        return notes[:match.start()] + block + notes[match.end():]
    return notes + ('\n\n' if notes else '') + block


def prepare(rows, cards, confirmations):
    rows, cards = copy.deepcopy(rows), copy.deepcopy(cards)
    for proof in confirmations:
        credit = [r for r in rows if r['key'] == proof['credit_key']]
        if len(credit) != 1 or not re.fullmatch(r'[0-9a-f]{64}', proof.get('evidence_sha256', '')):
            raise Deferred('Internal-card confirmation lacks unique bank evidence')
        credit = credit[0]
        source = [c for c in cards if c[-4:] == proof['source_suffix']]
        destination = [c for c in cards if c[-4:] == proof['destination_suffix']]
        if len(source) != 1 or len(destination) != 1 or source == destination:
            raise Deferred('Internal-card confirmation needs two distinct physical cards')
        source, destination = source[0], destination[0]
        left, right = cards[source], cards[destination]
        minute = datetime.fromisoformat(credit['date']).isoformat(timespec='minutes')
        if (credit['C'] != destination or credit['account'] != right['account']
                or left['account'] != right['account'] or left['currency'] != right['currency']
                or credit['F'] != left['currency'] or money(credit['E']) <= 0
                or amount(money(credit['E'])) != proof['amount']
                or minute != proof['occurred_minute']
                or credit['B'] != 'Зарахування зі своєї картки'
                or not re.search(r'\*\s*' + re.escape(proof['source_suffix']) + r'\b', credit['D'])):
            raise Deferred('Internal-card confirmation does not match the incoming bank record')
        debits = [r for r in rows if r['C'] == source and money(r['E']) == -money(credit['E'])
                  and datetime.fromisoformat(r['date']).isoformat(timespec='minutes') == minute
                  and r['B'] == 'Переказ на свою картку'
                  and re.search(r'\*\s*' + re.escape(proof['destination_suffix']) + r'\b', r['D'])]
        if len(debits) > 1:
            raise Deferred('Internal-card debit is ambiguous')
        if debits:
            if money(debits[0]['I']) != money(proof['source_balance']):
                raise Deferred('Internal-card debit disagrees with the confirmed balance')
            debits[0]['internal_card_transfer'] = proof['credit_key']
        else:
            previous = sorted((r for r in rows if r['C'] == source), key=lambda r: r['date'])[-1]
            if (previous['key'] != proof['source_previous_key'] or previous['date'] >= credit['date']
                    or money(left['closing']) - money(credit['E']) != money(proof['source_balance'])):
                raise Deferred('Internal-card source needs a newer statement before reconciliation')
            left['closing'] = amount(money(proof['source_balance']))
        credit['internal_card_transfer'] = proof['credit_key']
        credit['internal_card_proof'] = dict(proof, account_id=left['account'], currency=left['currency'])
    return rows, cards


def plan(rows, accounts, groups):
    assets = {a['id']: a['attributes'] for a in accounts}
    changes, annotations, retire = defaultdict(Decimal), {}, []
    for row in rows:
        proof = row.get('internal_card_proof')
        if not proof:
            continue
        own = proof['account_id']
        if own not in assets or assets[own].get('currency_code') != proof['currency']:
            raise Deferred('Internal-card evidence does not match the owned account currency')
        matches = [(g, j) for g in groups for j in g['attributes']['transactions']
                   if proof['credit_key'] in journal_keys(j)]
        if len(matches) > 1:
            raise Deferred('Duplicate staged internal-card movement')
        if matches:
            group, journal = matches[0]
            review = review_of(journal) or {}
            source = str(journal.get('source_id', ''))
            if (len(group['attributes']['transactions']) != 1 or journal['type'] != 'transfer'
                    or str(journal.get('destination_id')) != own or source not in assets
                    or not is_suspense(assets[source]) or journal.get('foreign_currency_id')
                    or str(journal.get('currency_id')) != str(assets[own].get('currency_id'))
                    or assets[source].get('currency_code') != proof['currency']
                    or str(assets[source].get('currency_id')) != str(assets[own].get('currency_id'))
                    or money(journal['amount']) != money(proof['amount'])
                    or datetime.fromisoformat(journal['date']) != datetime.fromisoformat(row['date'])
                    or review.get('status') != 'pending' or review.get('account_scope') != 'same_asset_cards'
                    or review.get('key') != proof['credit_key'] or review.get('account_id') != own
                    or journal.get('category_id') or journal.get('budget_id') or journal.get('bill_id')):
                raise Deferred('Internal-card staging changed; preserve it for manual reconciliation')
            retire.append({'group_id': group['id'], 'journal': journal})
            for account, value in effects(journal, assets).items():
                changes[account] -= value
        entry = dict(proof)
        entry['credit_record'] = {k: row[k] for k in ['key', 'C', 'D', 'E', 'F', 'I', 'date']}
        records = annotations.setdefault(own, evidence_records(assets[own].get('notes') or ''))
        if proof['credit_key'] in records and records[proof['credit_key']] != entry:
            raise Deferred('Previously confirmed internal-card evidence changed')
        records[proof['credit_key']] = entry
    writes = []
    for own, records in annotations.items():
        original = assets[own].get('notes') or ''
        updated = with_evidence(original, records)
        if original == updated:
            continue
        writes.append({'account_id': own, 'notes': updated,
                       'notes_hash': hashlib.sha256(original.encode()).hexdigest()})
    return {'retire': retire, 'annotations': writes,
            'delta': {a: amount(v) for a, v in changes.items()}}


def apply(client, planned):
    from firefly_api import internal_card_fingerprint
    for row in planned.get('annotations', []):
        client.request('PUT', '/accounts/' + row['account_id'], {'notes': row['notes']}, notes_hash=row['notes_hash'])
    for row in planned.get('retire', []):
        # The API soft-deletes only the earlier technical staging record.
        current = client.request('GET', '/transactions/' + row['group_id'])['data']
        if current['attributes']['transactions'] != [row['journal']]:
            raise Deferred('Staged internal-card movement changed after planning')
        client.request('DELETE', '/transactions/' + row['group_id'],
                       internal_card_hash=internal_card_fingerprint(row['group_id'], row['journal']))
