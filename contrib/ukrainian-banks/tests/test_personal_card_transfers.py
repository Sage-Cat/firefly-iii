import copy
import json
import unittest
from unittest.mock import Mock, patch

from monobank_sync import Deferred
from personal_card_transfers import apply, evidence_records, plan, prepare, with_evidence
from privat_personal_import import apply_plan


class InternalCardsTest(unittest.TestCase):
    def setUp(self):
        self.rows = [
            {'key': 'old', 'C': '****1111', 'account': '1', 'F': 'UAH', 'E': '100.00', 'I': '150.00',
             'B': 'Зарплата', 'D': 'Salary', 'date': '2026-10-30T10:00:00+02:00'},
            {'key': 'credit', 'C': '****2222', 'account': '1', 'F': 'UAH', 'E': '100.00', 'I': '250.00',
             'B': 'Зарахування зі своєї картки', 'D': 'Зі своєї картки *1111', 'date': '2026-11-08T03:09:37+02:00'}]
        self.cards = {'****1111': {'account': '1', 'currency': 'UAH', 'closing': '150.00'},
                      '****2222': {'account': '1', 'currency': 'UAH', 'closing': '250.00'}}
        self.proof = {'credit_key': 'credit', 'source_suffix': '1111', 'destination_suffix': '2222',
                      'amount': '100.00', 'source_balance': '50.00', 'source_previous_key': 'old',
                      'occurred_minute': '2026-11-08T03:09+02:00', 'evidence_sha256': 'a' * 64}
        self.accounts = [
            {'id': '1', 'attributes': {'currency_code': 'UAH', 'currency_id': '1', 'notes': '{"balance_history":{"version":1}}'}},
            {'id': '2', 'attributes': {'currency_code': 'UAH', 'currency_id': '1',
                                     'notes': '{"bank_review_suspense":1,"currency":"UAH"}'}}]
        self.journal = {'transaction_journal_id': '10', 'external_id': 'credit', 'type': 'transfer',
                        'description': 'Зі своєї картки *1111',
                        'source_id': '2', 'destination_id': '1', 'amount': '100.00', 'currency_id': '1',
                        'date': self.rows[1]['date'], 'notes': json.dumps({'bank_review': {
                            'key': 'credit', 'account_id': '1', 'status': 'pending', 'account_scope': 'same_asset_cards'}})}
        self.groups = [{'id': '10', 'attributes': {'transactions': [self.journal]}}]

    def prepared(self):
        return prepare(self.rows, self.cards, [self.proof])

    def test_source_balance_and_zero_financial_movement(self):
        original = copy.deepcopy((self.rows, self.cards))
        rows, cards = self.prepared()
        self.assertEqual(cards['****1111']['closing'], '50.00')
        self.assertEqual(cards['****2222']['closing'], '250.00')
        self.assertEqual((self.rows, self.cards), original)
        result = plan(rows, self.accounts, self.groups)
        self.assertEqual(result['delta'], {'2': '100.00', '1': '-100.00'})
        self.assertEqual(len(result['retire']), 1)
        raw = result['annotations'][0]['notes']
        self.assertTrue(raw.startswith(self.accounts[0]['attributes']['notes']))
        note = evidence_records(raw)
        self.assertEqual(note['credit']['source_balance'], '50.00')
        self.assertEqual(note['credit']['occurred_minute'], self.proof['occurred_minute'])

    def test_later_real_debit_is_consumed_once(self):
        self.rows.append({'key': 'debit', 'C': '****1111', 'account': '1', 'F': 'UAH', 'E': '-100.00', 'I': '50.00',
                          'B': 'Переказ на свою картку', 'D': 'На свою картку *2222', 'date': '2026-11-08T03:09:35+02:00'})
        self.cards['****1111']['closing'] = '50.00'
        for bank_category in ['Переказ на свою картку', 'Перекази']:
            self.rows[-1]['B'] = bank_category
            rows, cards = self.prepared()
            self.assertEqual([r['key'] for r in rows if r.get('internal_card_transfer')], ['credit', 'debit'])
            self.assertEqual(cards['****1111']['closing'], '50.00')

    def test_conflicting_bank_proof_is_rejected(self):
        for field, value in [('amount', '99.00'), ('source_balance', '49.00'), ('source_previous_key', 'missing'),
                             ('source_suffix', '3333'), ('destination_suffix', '1111'),
                             ('occurred_minute', '2026-11-08T03:10+02:00'), ('evidence_sha256', 'invalid')]:
            with self.subTest(field=field):
                proof = dict(self.proof, **{field: value})
                with self.assertRaises(Deferred):
                    prepare(self.rows, self.cards, [proof])
        self.cards['****1111']['account'] = '3'
        with self.assertRaises(Deferred):
            self.prepared()

    def test_newer_source_evidence_cannot_be_overridden(self):
        self.rows[0]['date'] = '2026-11-09T10:00:00+02:00'
        with self.assertRaises(Deferred):
            self.prepared()

    def test_changed_or_categorized_staging_is_preserved(self):
        rows, _ = self.prepared()
        for field, value in [('amount', '101'), ('category_id', '5'), ('destination_id', '3'),
                             ('type', 'deposit'), ('currency_id', '2'), ('date', '2026-11-08T03:10:00+02:00')]:
            with self.subTest(field=field):
                j = dict(self.journal, **{field: value})
                with self.assertRaises(Deferred):
                    plan(rows, self.accounts, [{'id': '10', 'attributes': {'transactions': [j]}}])
        self.accounts[1]['attributes']['currency_code'] = 'EUR'
        with self.assertRaises(Deferred):
            plan(rows, self.accounts, self.groups)

    def test_repeat_preserves_history_and_creates_no_transaction(self):
        rows, _ = self.prepared()
        first = plan(rows, self.accounts, self.groups)
        self.accounts[0]['attributes']['notes'] = first['annotations'][0]['notes']
        repeat = plan(rows, self.accounts, [])
        self.assertEqual(repeat, {'retire': [], 'annotations': [], 'delta': {}})

    def test_apply_only_writes_notes_and_removes_technical_staging(self):
        calls = []
        journal = self.journal
        class Client:
            def request(self, method, path, data=None, **headers):
                calls.append((method, path, data, headers))
                if method == 'GET':
                    return {'data': {'attributes': {'transactions': [journal]}}}
        rows, _ = self.prepared()
        apply(Client(), plan(rows, self.accounts, self.groups))
        self.assertEqual([c[0] for c in calls], ['PUT', 'GET', 'DELETE'])
        self.assertEqual(set(calls[0][2]), {'notes'})
        self.assertEqual(len(calls[0][3]['notes_hash']), 64)
        self.assertEqual(len(calls[2][3]['internal_card_hash']), 64)
        self.assertFalse(any(c[0] == 'POST' for c in calls))

    def test_raw_capital_history_is_preserved_byte_for_byte(self):
        original = 'Private notes\n<!-- firefly-balance-history:v1\n' + json.dumps({
            'version': 1, 'ledger_from': '2026-08-01',
            'snapshots': [{'day': '2026-01-01', 'currency': 'EUR', 'amount': '50'}]}) + '\n-->\nTail'
        self.accounts[0]['attributes']['notes'] = original
        rows, _ = self.prepared()
        annotated = plan(rows, self.accounts, self.groups)['annotations'][0]['notes']
        self.assertTrue(annotated.startswith(original))
        self.assertEqual(annotated.count('<!-- firefly-balance-history:v1'), 1)
        records = evidence_records(annotated)
        self.assertEqual(with_evidence(annotated, records), annotated)
        records['another'] = {'amount': '5'}
        updated = with_evidence(annotated, records)
        self.assertTrue(updated.startswith(original))
        self.assertEqual(updated.count('<!-- firefly-internal-cards:v1'), 1)

    def test_duplicate_or_malformed_evidence_is_rejected(self):
        block = with_evidence('', {'credit': {'amount': '100'}})
        for raw in [block + block, '<!-- firefly-internal-cards:v1\n{bad\n-->']:
            with self.assertRaises(Deferred):
                evidence_records(raw)


class PersonalClassificationHookTest(unittest.TestCase):
    def test_opt_in_only_runs_after_closing_balance_verification(self):
        planned = {'openings': {}, 'operations': [], 'preserved_peers': {}, 'targets': {'1': '100.00'}}
        client = Mock()
        client.request.return_value = {'data': {'attributes': {'current_balance': '100.00'}}}
        with patch('bank_classification.synchronize') as classify:
            apply_plan(client, planned)
            classify.assert_not_called()
            apply_plan(client, dict(planned, auto_classification=True))
            classify.assert_called_once()
            self.assertIs(classify.call_args.args[0], client)
        client.request.return_value = {'data': {'attributes': {'current_balance': '99.00'}}}
        with patch('bank_classification.synchronize') as classify:
            with self.assertRaises(Deferred):
                apply_plan(client, dict(planned, auto_classification=True))
            classify.assert_not_called()


if __name__ == '__main__':
    unittest.main()
