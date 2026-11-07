import tempfile
import unittest
from pathlib import Path
from xml.sax.saxutils import escape
from zipfile import ZipFile

from privat_personal_import import HEADERS, Deferred, apply_plan, build_plan, collect_sources, read_statement


def workbook(path, rows):
    all_rows = [["Історія операцій за період 01.08.2026 - 06.11.2026"], HEADERS] + rows
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
        with self.assertRaises(Deferred): build_plan(self.config(), self.accounts(), [])

    def test_unknown_bank_category_waits_for_owner_choice(self):
        workbook(self.path, [row('01.08.2026 12:00:00', '-10', '90', 'Дозвілля', 'Unknown merchant')])
        plan = build_plan(self.config(), self.accounts('90','90'), [])
        tx = plan['operations'][0]['transaction']
        self.assertEqual('Потребує уточнення',tx['category_name'])
        self.assertIn('Потребує уточнення',tx['tags'])

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
