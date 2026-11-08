import tempfile
import unittest
from pathlib import Path
from xml.sax.saxutils import escape
from zipfile import ZipFile

from privat_personal_import import HEADERS, Deferred, apply_plan, build_plan, collect_sources, read_statement


def workbook(path, rows, start='01.08.2026', end='06.11.2026'):
    all_rows = [[f"Історія операцій за період {start} - {end}"], HEADERS] + rows
    content = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    for i, row in enumerate(all_rows, 1):
        content += '<row>' + ''.join(f'<c r="{chr(65+j)}{i}" t="inlineStr"><is><t>{escape(v)}</t></is></c>'
                                    for j, v in enumerate(row)) + '</row>'
    with ZipFile(path, 'w') as z:
        z.writestr('xl/worksheets/sheet1.xml', content + '</sheetData></worksheet>')


def row(date, value, balance, category='Продукти', description='Merchant'):
    return [date, category, '0000 **** **** 1234', description, value, 'UAH',
            str(abs(float(value))), 'UAH', balance, 'UAH']


class ImportTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / 'statement.xlsx'

    def config(self):
        return {'sources': [{'path': str(self.path), 'account_id': '1', 'card_suffix': '1234'}]}

    def accounts(self, opening='100', current='100'):
        return [{'id': '1', 'attributes': {'currency_code': 'UAH', 'currency_id': '2',
                'opening_balance': opening, 'current_balance': current}}]

    def test_chain_and_coverage(self):
        workbook(self.path, [row('02.08.2026 12:00:00', '-10', '80'), row('01.08.2026 12:00:00', '-10', '90')])
        self.assertEqual(2, len(read_statement(self.path)['rows']))
        workbook(self.path, [row('02.08.2026 12:00:00', '-10', '81'), row('01.08.2026 12:00:00', '-10', '90')])
        with self.assertRaises(Deferred): read_statement(self.path)

    def test_duplicate_files_are_one_source(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '-10', '90')])
        config = self.config()
        config['sources'] *= 2
        rows, cards, _ = collect_sources(config)
        self.assertEqual((1, 1), (len(rows), len(cards)))

    def overlapping_sources(self):
        shared = row('10.10.2026 12:00:00', '-10', '80')
        workbook(self.path, [shared, row('01.08.2026 12:00:00', '-10', '90')])
        other = Path(self.tmp.name) / 'newer.xlsx'
        workbook(other, [row('08.11.2026 12:00:00', '-5', '75'), shared],
                 start='01.10.2026', end='08.11.2026')
        config = self.config()
        config['sources'].append(dict(config['sources'][0], path=str(other)))
        return config, other

    def test_overlapping_extension_preserves_keys_and_earliest_opening(self):
        config, _ = self.overlapping_sources()
        old_keys = {r['key'] for r in read_statement(self.path)['rows']}
        for sources in (config['sources'], list(reversed(config['sources'])), config['sources'] * 2):
            with self.subTest(sources=len(sources), first=sources[0]['path']):
                rows, cards, coverage = collect_sources(dict(config, sources=sources))
                self.assertEqual(3, len(rows))
                self.assertTrue(old_keys.issubset({r['key'] for r in rows}))
                self.assertEqual({'account': '1', 'currency': 'UAH', 'start': '2026-08-01',
                                  'opening': '100.00', 'closing': '75.00'}, cards[rows[0]['C']])
                self.assertEqual(len(sources), len(coverage))

    def test_contained_statement_keeps_all_existing_history(self):
        config, other = self.overlapping_sources()
        workbook(other, [row('10.10.2026 12:00:00', '-10', '80')],
                 start='01.10.2026', end='01.11.2026')
        expected = collect_sources(self.config())[:2]
        self.assertEqual(expected, collect_sources(config)[:2])

    def test_boundary_overlap_keeps_same_second_transaction_order(self):
        older = row('10.10.2026 12:00:00', '-5', '85', description='First purchase')
        newer = row('10.10.2026 12:00:00', '-5', '80', description='Second purchase')
        workbook(self.path, [newer, older, row('01.08.2026 12:00:00', '-10', '90')],
                 end='10.10.2026')
        other = Path(self.tmp.name) / 'newer.xlsx'
        workbook(other, [row('08.11.2026 12:00:00', '-5', '75'), newer, older],
                 start='10.10.2026', end='08.11.2026')
        config = self.config()
        config['sources'].append(dict(config['sources'][0], path=str(other)))
        rows, cards, _ = collect_sources(config)
        self.assertEqual(4, len(rows))
        self.assertEqual('75.00', cards[rows[0]['C']]['closing'])

    def partial_final_day_sources(self, old_final_day_rows=False):
        shared = [row('05.11.2026 23:26:00', '-10', '80')]
        if old_final_day_rows:
            shared.insert(0, row('06.11.2026 09:00:00', '-5', '75'))
        workbook(self.path, shared + [row('01.08.2026 12:00:00', '-10', '90')])
        other = Path(self.tmp.name) / 'newer.xlsx'
        workbook(other, [row('08.11.2026 12:00:00', '-5', '65' if old_final_day_rows else '70'),
                         row('06.11.2026 15:00:00', '-5', '70' if old_final_day_rows else '75')] + shared,
                 start='01.10.2026', end='08.11.2026')
        config = self.config()
        config['sources'].append(dict(config['sources'][0], path=str(other)))
        return config, other

    def test_partial_final_day_allows_only_a_reconciled_tail_and_preserves_history(self):
        for old_final_day_rows in (False, True):
            config, _ = self.partial_final_day_sources(old_final_day_rows)
            old_rows = read_statement(self.path)['rows']
            for sources in (config['sources'], list(reversed(config['sources']))):
                with self.subTest(old_final_day_rows=old_final_day_rows, first=sources[0]['path']):
                    rows, cards, _ = collect_sources(dict(config, sources=sources))
                    self.assertEqual(len(old_rows) + 2, len(rows))
                    self.assertTrue({r['key'] for r in old_rows}.issubset({r['key'] for r in rows}))
                    self.assertEqual(('2026-08-01', '100.00', '65.00' if old_final_day_rows else '70.00'),
                                     tuple(cards[rows[0]['C']][field] for field in ('start', 'opening', 'closing')))

    def test_partial_final_day_in_contained_older_statement_remains_consistent(self):
        config, other = self.partial_final_day_sources(True)
        newer_rows = [list(r[column] for column in 'ABCDEFGHIJ') for r in read_statement(other)['rows']]
        workbook(other, newer_rows + [row('01.08.2026 12:00:00', '-10', '90')],
                 start='01.07.2026', end='08.11.2026')
        rows, cards, _ = collect_sources(config)
        self.assertEqual(5, len(rows))
        self.assertEqual(('2026-07-01', '100.00', '65.00'),
                         tuple(cards[rows[0]['C']][field] for field in ('start', 'opening', 'closing')))

    def test_partial_final_day_does_not_allow_prior_day_backfill(self):
        config, other = self.partial_final_day_sources()
        workbook(other, [row('08.11.2026 12:00:00', '-5', '75'),
                         row('05.11.2026 23:40:00', '5', '80'),
                         row('05.11.2026 23:30:00', '-5', '75'),
                         row('05.11.2026 23:26:00', '-10', '80')],
                 start='01.10.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'Overlapping statements disagree'):
            collect_sources(config)

    def test_partial_final_day_requires_all_known_keys_before_the_extension(self):
        config, other = self.partial_final_day_sources(True)
        workbook(other, [row('08.11.2026 12:00:00', '-5', '65'),
                         row('06.11.2026 15:00:00', '-10', '70'),
                         row('05.11.2026 23:26:00', '-10', '80')],
                 start='01.10.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'Overlapping statements disagree'):
            collect_sources(config)

    def test_partial_final_day_rejects_insertions_before_the_known_closing(self):
        config, other = self.partial_final_day_sources(True)
        workbook(other, [row('08.11.2026 12:00:00', '-5', '65'),
                         row('06.11.2026 15:00:00', '-5', '70'),
                         row('06.11.2026 09:00:00', '-5', '75'),
                         row('06.11.2026 08:30:00', '5', '80'),
                         row('06.11.2026 08:00:00', '-5', '75'),
                         row('05.11.2026 23:26:00', '-10', '80')],
                 start='01.10.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'Overlapping statements disagree'):
            collect_sources(config)

    def test_partial_final_day_rejects_an_ambiguous_same_timestamp_tail(self):
        config, other = self.partial_final_day_sources(True)
        workbook(other, [row('08.11.2026 12:00:00', '-5', '65'),
                         row('06.11.2026 09:00:00', '-5', '70'),
                         row('06.11.2026 09:00:00', '-5', '75'),
                         row('05.11.2026 23:26:00', '-10', '80')],
                 start='01.10.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'Overlapping statements disagree'):
            collect_sources(config)

    def test_partial_final_day_without_shared_rows_still_checks_boundary_money(self):
        config, other = self.partial_final_day_sources()
        workbook(other, [row('08.11.2026 12:00:00', '-5', '65'),
                         row('06.11.2026 15:00:00', '-5', '70')],
                 start='06.11.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'Merged statement balance chain'):
            collect_sources(config)

    def test_partial_final_day_does_not_replace_conflicting_equal_periods(self):
        config, other = self.partial_final_day_sources()
        workbook(other, [row('06.11.2026 15:00:00', '-5', '75'),
                         row('05.11.2026 23:26:00', '-10', '80')],
                 start='01.08.2026', end='06.11.2026')
        with self.assertRaisesRegex(Deferred, 'Overlapping statements disagree'):
            collect_sources(config)

    def test_overlapping_extension_reuses_manual_categories_and_is_idempotent(self):
        from test_monobank_sync import Ledger
        config, _ = self.overlapping_sources()
        ledger = Ledger(self.accounts())
        original = build_plan(self.config(), ledger.accounts, [])
        apply_plan(ledger, original)
        for group in ledger.groups:
            group['attributes']['transactions'][0]['category_name'] = 'Ручний вибір'
        config['categories'] = {op['key']: 'Продукти' for op in original['operations']}
        extended = build_plan(config, ledger.accounts, ledger.groups)
        self.assertEqual(original['openings'], extended['openings'])
        self.assertEqual({'1': '75.00'}, extended['targets'])
        self.assertEqual({'reuse': 2, 'create': 1}, apply_plan(ledger, extended))
        again = build_plan(config, ledger.accounts, ledger.groups)
        self.assertEqual({'reuse': 3}, apply_plan(ledger, again))
        self.assertEqual(['Ручний вибір'] * 2,
                         [g['attributes']['transactions'][0]['category_name'] for g in ledger.groups[:2]])

    def test_overlapping_changed_row_is_rejected_even_with_matching_balances(self):
        config, other = self.overlapping_sources()
        workbook(other, [row('08.11.2026 12:00:00', '-5', '75'),
                         row('10.10.2026 12:00:00', '-10', '80', description='Changed merchant')],
                 start='01.10.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'Overlapping statements disagree'):
            collect_sources(config)

    def test_overlapping_missing_or_added_net_zero_rows_are_rejected(self):
        shared = [row('06.10.2026 12:00:00', '10', '90'),
                  row('05.10.2026 12:00:00', '-10', '80')]
        for old_shared, new_shared in ((shared, []), ([], shared)):
            with self.subTest(old_rows=len(old_shared)):
                workbook(self.path, old_shared + [row('01.08.2026 12:00:00', '-10', '90')])
                other = Path(self.tmp.name) / 'newer.xlsx'
                workbook(other, [row('08.11.2026 12:00:00', '-5', '85')] + new_shared,
                         start='01.10.2026', end='08.11.2026')
                config = self.config()
                config['sources'].append(dict(config['sources'][0], path=str(other)))
                with self.assertRaisesRegex(Deferred, 'Overlapping statements disagree'):
                    collect_sources(config)

    def test_continuous_coverage_without_shared_rows_still_checks_balance_chain(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '-10', '90')])
        other = Path(self.tmp.name) / 'newer.xlsx'
        config = self.config()
        config['sources'].append(dict(config['sources'][0], path=str(other)))
        for start in ('01.10.2026', '07.11.2026'):
            with self.subTest(start=start):
                workbook(other, [row('08.11.2026 12:00:00', '-5', '85')], start=start, end='08.11.2026')
                rows, cards, _ = collect_sources(config)
                self.assertEqual((2, '85.00'), (len(rows), cards[rows[0]['C']]['closing']))
                workbook(other, [row('08.11.2026 12:00:00', '-5', '80')], start=start, end='08.11.2026')
                with self.assertRaisesRegex(Deferred, 'Merged statement balance chain'):
                    collect_sources(config)

    def test_uncovered_day_is_rejected_even_when_balances_match(self):
        config, other = self.overlapping_sources()
        workbook(other, [row('08.11.2026 12:00:00', '-5', '75')],
                 start='08.11.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'coverage has a gap'):
            collect_sources(config)

    def test_same_card_account_or_currency_remapping_is_rejected(self):
        config, other = self.overlapping_sources()
        config['sources'][1]['account_id'] = '2'
        with self.assertRaisesRegex(Deferred, 'conflicting account or currency'):
            collect_sources(config)
        config['sources'][1]['account_id'] = '1'
        foreign = row('08.11.2026 12:00:00', '-5', '75')
        foreign[5] = foreign[7] = foreign[9] = 'USD'
        workbook(other, [foreign], start='01.10.2026', end='08.11.2026')
        with self.assertRaisesRegex(Deferred, 'conflicting account or currency'):
            collect_sources(config)

    def test_opening_and_idempotent_resume(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '-10', '90')])
        first = build_plan(self.config(), self.accounts('90', '90'), [])
        self.assertEqual('100.00', first['openings']['1']['value'])
        tx = first['operations'][0]['transaction']
        groups = [{'id': '10', 'attributes': {'transactions': [tx]}}]
        again = build_plan(self.config(), self.accounts('100', '90'), groups, first)
        self.assertEqual('reuse', again['operations'][0]['action'])
        changed = dict(tx, amount='11')
        with self.assertRaises(Deferred):
            build_plan(self.config(), self.accounts(), [{'id': '10', 'attributes': {'transactions': [changed]}}], first)

    def test_unmapped_own_transfer_is_not_income(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '10', '100', 'Зарахування зі своєї картки')])
        tx = build_plan(self.config(), self.accounts(), [])['operations'][0]['transaction']
        self.assertEqual('transfer', tx['type'])
        self.assertEqual('bank-review:UAH', tx['source_id'])

    def test_unknown_bank_category_waits_for_owner_choice(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '-10', '90', 'Дозвілля', 'Unknown merchant')])
        plan = build_plan(self.config(), self.accounts('90','90'), [])
        tx = plan['operations'][0]['transaction']
        self.assertNotIn('category_name',tx)
        self.assertNotIn('Потребує уточнення',tx['tags'])

    def test_foreign_transfer_pair_and_peer_preservation(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '50', '100', 'Зарахування зі своєї картки')])
        config = self.config()
        key = read_statement(self.path)['rows'][0]['key']
        config['peers'] = {key: '3'}
        accounts = self.accounts('100', '100') + [{'id': '3', 'attributes': {
            'currency_code': 'UAH', 'currency_id': '2', 'opening_balance': '20', 'current_balance': '20'}}]
        plan = build_plan(config, accounts, [])
        self.assertEqual('transfer', plan['operations'][0]['transaction']['type'])
        self.assertEqual('70.00', plan['openings']['3']['value'])
        config['peers'][key] = '4'
        with self.assertRaises(Deferred): build_plan(config, accounts, [])

    def test_paired_destination_currency_is_checked_before_consumption(self):
        from privat_personal_import import operations
        assets = {'1': {'currency_code': 'EUR', 'currency_id': '1'},
                  '2': {'currency_code': 'USD', 'currency_id': '3'}}
        debit = dict(zip('ABCDEFGHIJ', row('01.08.2026 12:00:00', '-10', '90')))
        debit.update(F='EUR', J='EUR', key='a', account='1', date='2026-08-01T12:00:00+03:00')
        credit = dict(debit, F='UAH', J='UAH', E='500', key='b', account='2')
        config = {'pairs': {'a': 'b', 'b': 'a'}}
        with self.assertRaises(Deferred): operations(config, [debit, credit], assets)
        assets['2']['currency_code'] = 'UAH'
        tx = operations(config, [debit, credit], assets)[0]['transaction']
        self.assertEqual(('transfer', '10.00', '500.00'),
                         (tx['type'], tx['amount'], tx['foreign_amount']))

    def test_interrupted_apply_resumes_without_duplicates(self):
        from test_monobank_sync import Ledger
        workbook(self.path, [row('02.08.2026 12:00:00', '-10', '80'),
                             row('01.08.2026 12:00:00', '-10', '90')])
        ledger = Ledger(self.accounts('80', '80'))
        plan = build_plan(self.config(), ledger.accounts, [])
        partial = dict(plan, operations=plan['operations'][:1])
        with self.assertRaises(Deferred): apply_plan(ledger, partial)
        resumed = build_plan(self.config(), ledger.accounts, ledger.groups, plan)
        self.assertEqual({'create': 1, 'reuse': 1}, apply_plan(ledger, resumed))
        again = build_plan(self.config(), ledger.accounts, ledger.groups, plan)
        self.assertEqual({'reuse': 2}, apply_plan(ledger, again))
        self.assertEqual(2, len(ledger.groups))

    def test_existing_other_connector_transfer_is_reused(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '-10', '90', 'Перекази', 'переказ власних коштів')])
        config = self.config()
        key = read_statement(self.path)['rows'][0]['key']
        config['peers'] = {key: '3'}
        accounts = self.accounts('100', '90') + [{'id': '3', 'attributes': {
            'currency_code': 'UAH', 'currency_id': '2', 'opening_balance': '0', 'current_balance': '10'}}]
        existing = {'id': '10', 'attributes': {'transactions': [{
            'type': 'transfer', 'date': '2026-08-01T12:00:00+03:00', 'currency_id': '2',
            'source_id': '1', 'destination_id': '3', 'amount': '10', 'external_id': 'other-connector'}]}}
        plan = build_plan(config, accounts, [existing])
        self.assertEqual('reuse', plan['operations'][0]['action'])
        self.assertNotIn('3', plan['openings'])


if __name__ == '__main__':
    unittest.main()
