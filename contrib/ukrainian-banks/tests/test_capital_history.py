import copy
import io
import unittest

from capital_history import apply, date_value, digest, journal, plan, restrict_history


class HistoryTests(unittest.TestCase):
    def setUp(self):
        self.accounts = [{'id': '1', 'attributes': {'name': 'Portfolio', 'notes': 'Keep me',
                          'opening_balance_date': '2026-11-06T00:00:00+02:00'}}]
        self.rows = [['Date', 'Account', 'Currency', 'Amount'],
                     ['2026-01-01T12:00:00', 'Broker', 'EUR', 1000],
                     ['2026-02-01T12:00:00', 'Broker', 'USD', 1130],
                     ['2026-11-08T01:00:00', 'Broker', 'USD', 99999]]
        self.formulas = copy.deepcopy(self.rows)
        self.formulas[3][0] = '=NOW()'
        self.bindings = {'Broker|EUR': '1', 'Broker|USD': '1'}

    def test_original_currency_and_dynamic_date(self):
        rows, excluded = journal(self.rows, self.formulas, self.bindings, '2026-11-08')
        self.assertEqual([r['currency'] for r in rows['1']], ['EUR', 'USD'])
        self.assertEqual([r['amount'] for r in rows['1']], ['1000', '1130'])
        self.assertEqual(excluded[0]['row'], 4)
        self.assertEqual(excluded[0]['reason'], 'dynamic_date')

    def test_start_boundary_preserves_later_valuations_without_old_dates(self):
        rows, _ = journal(self.rows, self.formulas, self.bindings, '2026-11-08')
        original = copy.deepcopy(rows)
        restricted = restrict_history(rows, '2026-02-15')
        self.assertEqual(rows, original)
        self.assertEqual(len(restricted['1']), 1)
        opening = restricted['1'][0]
        self.assertEqual((opening['day'], opening['at'], opening['amount'], opening['currency']),
                         ('2026-02-15', '2026-02-15T00:00:00+02:00', '1130', 'USD'))
        self.assertTrue(opening['opening_valuation'])
        self.assertEqual(restrict_history(restricted, '2026-02-15'), restricted)

    def test_boundary_keeps_exact_snapshot_and_missing_accounts_unknown(self):
        rows, _ = journal(self.rows, self.formulas, self.bindings, '2026-11-08')
        restricted = restrict_history(dict(rows, empty=[]), '2026-02-01')
        self.assertEqual(restricted['1'], [rows['1'][1]])
        self.assertEqual(restricted['empty'], [])
        with self.assertRaises(ValueError):
            restrict_history(rows, '2026-02-30')
        with self.assertRaises(ValueError):
            restrict_history(rows, '2026-02-01T12:00:00')

    def test_latest_intraday_and_serial_date(self):
        self.rows.insert(2, ['2026-01-01T13:00:00', 'Broker', 'EUR', 1100])
        rows, _ = journal(self.rows, self.rows, self.bindings, '2026-11-08')
        self.assertEqual(rows['1'][0]['amount'], '1100')
        self.assertEqual(date_value(46008).date().isoformat(), '2025-12-17')

    def test_rejects_unknown_future_and_nonfinite(self):
        for row in [['2026-01-01', 'Missing', 'USD', 1], ['2027-01-01', 'Broker', 'USD', 1],
                    ['2026-01-01', 'Broker', 'USD', 'NaN']]:
            with self.assertRaises(ValueError):
                journal([self.rows[0], row], [self.rows[0], row], self.bindings, '2026-11-08')

    def test_repeated_kyiv_hour_uses_actual_instant(self):
        rows = [self.rows[0], ['2026-10-25T03:30:00+03:00', 'Broker', 'USD', 1000],
                ['2026-10-25T03:15:00+02:00', 'Broker', 'USD', 1200]]
        snapshots, _ = journal(rows, rows, self.bindings, '2026-11-08')
        self.assertEqual(snapshots['1'][0]['amount'], '1200')
        self.assertTrue(snapshots['1'][0]['at'].endswith('+02:00'))

    def test_notes_only_idempotent_and_preserves_bank_dates(self):
        snapshots, _ = journal(self.rows, self.formulas, self.bindings, '2026-11-08')
        changes = plan(self.accounts, snapshots, {}, 'https://example.test/source')
        change = changes[0]
        self.assertTrue(change['notes'].startswith('Keep me\n\n'))
        self.assertEqual(change['ledger_from'], '2026-11-06')
        updated = copy.deepcopy(self.accounts)
        updated[0]['attributes']['notes'] = change['notes']
        second = plan(updated, snapshots, {}, 'https://example.test/source')
        self.assertFalse(second[0]['changed'])
        self.assertEqual(second[0]['notes'], change['notes'])
        self.assertEqual(self.accounts[0]['attributes']['opening_balance_date'][:10], '2026-11-06')

    def test_zero_coverage_needs_evidence(self):
        with self.assertRaises(ValueError):
            plan(self.accounts, {}, {'1': {'day': '2026-09-01'}}, 'source')
        rows = plan(self.accounts, {}, {'1': {'day': '2026-09-01', 'proof': 'Verified zero bank baseline'}}, 'source')
        self.assertEqual(rows[0]['ledger_from'], '2026-09-01')
        with self.assertRaises(ValueError):
            plan(self.accounts, {'2': []}, {}, 'source')

    def test_api_allows_only_notes_and_detects_races(self):
        changes = plan(self.accounts, {'1': []}, {}, 'source')
        class Client:
            def __init__(self):
                self.notes = 'Keep me'
                self.puts = []
            def request(self, method, path, data=None, *, notes_hash=None):
                self.assert_path(path)
                if method == 'PUT':
                    if notes_hash != digest(self.notes):
                        raise RuntimeError('Concurrent edit')
                    if set(data) != {'notes'}:
                        raise AssertionError('Financial update')
                    self.puts.append(data)
                    self.notes = data['notes']
                return {'data': {'attributes': {'notes': self.notes}}}
            def assert_path(self, path):
                if path != '/accounts/1':
                    raise AssertionError('Wrong account')
        client = Client()
        results = []
        apply(client, changes, lambda *args: results.append(args))
        apply(client, changes, lambda *args: results.append(args))
        self.assertEqual(len(client.puts), 1)
        self.assertEqual(results, [('1', 'updated'), ('1', 'reused')])
        client.notes = 'User edit'
        with self.assertRaises(RuntimeError):
            apply(client, changes, lambda *args: None)
        self.assertEqual(len(client.puts), 1)

    def test_native_trim_and_empty_notes(self):
        for before in ['', '  \n\t']:
            accounts = copy.deepcopy(self.accounts)
            accounts[0]['attributes']['notes'] = before
            changes = plan(accounts, {'1': []}, {}, 'source')
            self.assertTrue(changes[0]['notes'].startswith('<!--'))
            class NativeTrimClient:
                def __init__(self): self.notes = before
                def request(self, method, path, data=None, *, notes_hash=None):
                    if method == 'PUT':
                        if notes_hash != digest(self.notes): raise RuntimeError('Concurrent edit')
                        self.notes = data['notes'].strip(' \t\n\r\0\x0b')
                    return {'data': {'attributes': {'notes': self.notes}}}
            client = NativeTrimClient()
            apply(client, changes, lambda *args: None)
            apply(client, changes, lambda *args: None)
            self.assertEqual(digest(client.notes), changes[0]['after_hash'])

    def test_atomic_guard_preserves_edit_between_get_and_put(self):
        changes = plan(self.accounts, {'1': []}, {}, 'source')
        class ConcurrentClient:
            def __init__(self): self.notes = 'Keep me'
            def request(self, method, path, data=None, *, notes_hash=None):
                if method == 'GET':
                    observed = self.notes
                    self.notes = 'Concurrent user edit'
                    return {'data': {'attributes': {'notes': observed}}}
                if notes_hash != digest(self.notes): raise RuntimeError('Concurrent edit')
                self.notes = data['notes']
        client = ConcurrentClient()
        with self.assertRaisesRegex(RuntimeError, 'Concurrent edit'):
            apply(client, changes, lambda *args: self.fail('Must not save after conflict'))
        self.assertEqual(client.notes, 'Concurrent user edit')

    def test_api_client_sends_atomic_header(self):
        from firefly_api import Client
        class Http:
            def open(self, request, timeout):
                self.request = request
                return io.BytesIO(b'{}')
        client = Client.__new__(Client)
        client.base = 'http://127.0.0.1:8080/api/v1'
        client._token = 'synthetic-test-token'
        client._http = Http()
        expected = digest('Existing notes')
        client.request('PUT', '/accounts/1', {'notes': 'History'}, notes_hash=expected)
        self.assertEqual(client._http.request.get_header('If-match-notes'), expected)
        client.request('GET', '/about')
        self.assertIsNone(client._http.request.get_header('If-match-notes'))
        with self.assertRaises(ValueError):
            client.request('PUT', '/accounts/1', {'notes': 'History'}, notes_hash='invalid')


if __name__ == '__main__':
    unittest.main()
