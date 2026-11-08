import unittest
import tempfile
from pathlib import Path
from unittest.mock import patch

from privat_fop_import import Deferred, exchange_pairs, normalized_source, plan_import


def tx(account, peer, currency, direction, value, description, identifier):
    return {'AUT_MY_ACC': account, 'AUT_CNTR_ACC': peer, 'CCY': currency,
            'TRANTYPE': direction, 'SUM': value, 'OSND': description, 'ID': identifier,
            'PR_PR': 'r', 'NUM_DOC': 'document', 'DATE_TIME_DAT_OD_TIM_P': '06.08.2026 12:00:00'}


def balance(account, currency, opening, closing, credit, debit):
    return {'acc': account, 'currency': currency, 'dpd': '06.08.2026',
            'balanceIn': opening, 'balanceOut': closing, 'turnoverCred': credit, 'turnoverDebt': debit}


class FopImportTests(unittest.TestCase):
    def test_unknown_fop_income_waits_for_owner_category(self):
        from test_monobank_sync import asset
        snapshot={'start_date':'2026-08-06','end_date':'2026-08-06','transactions':[
            tx('eur','outside','EUR','C','100','Unknown purpose','one')],
            'balances':[balance('eur','EUR','0','100','100','0')]}
        plan=plan_import(snapshot,{'bindings':{'eur':'10'}},[asset('10','EUR','100','100')],[])
        t=plan['operations'][0]['transaction']
        self.assertEqual('deposit',t['type'])
        self.assertNotIn('category_name',t)
        self.assertNotIn('Потребує уточнення',t['tags'])

    def snapshot(self):
        debit = tx('eur', 'bank', 'EUR', 'D', '1000.00', 'Списання валютно-обмінних операцій по курсу 50.0000000', 'debit')
        credit = tx('uah', 'bank', 'UAH', 'C', '50000.00', 'Гривні від продажу 1000.00 EUR по курсу 50.00', 'credit')
        return {'start_date': '2026-08-06', 'end_date': '2026-08-06', 'transactions': [debit, credit],
                'balances': [balance('eur','EUR','1000','0','0','1000'), balance('uah','UAH','0','50000','50000','0')]}

    def test_fx_is_one_exact_transfer_and_repeat_is_noop(self):
        from test_monobank_sync import Ledger, asset
        from privat_personal_import import apply_plan
        config = {'bindings': {'eur':'10','uah':'11'}}
        ledger = Ledger([asset('10','EUR','0','0'),asset('11','UAH','50000','50000')])
        plan = plan_import(self.snapshot(),config,ledger.accounts,[])
        self.assertEqual(1,len(plan['operations']))
        t=plan['operations'][0]['transaction']
        self.assertEqual(('1000.00','50000.00'),(t['amount'],t['foreign_amount']))
        self.assertEqual({'create':1},apply_plan(ledger,plan))
        again=plan_import(self.snapshot(),config,ledger.accounts,ledger.groups,plan)
        self.assertEqual('reuse',again['operations'][0]['action'])

    def test_wrong_actual_proceeds_fail_without_using_nbu_equivalent(self):
        snapshot=self.snapshot()
        snapshot['transactions'][1]['SUM']='49999.00'
        with self.assertRaises(Deferred):exchange_pairs(snapshot)

    def test_technical_transit_is_collapsed_only_when_reciprocal(self):
        snapshot={'start_date':'2026-08-06','end_date':'2026-08-06','transactions':[
            tx('main','transit','EUR','C','100','Move','one'),tx('transit','main','EUR','D','100','Move','two')],
            'balances':[balance('main','EUR','0','100','100','0'),balance('transit','EUR','100','0','0','100')]}
        rows,cards,coverage=normalized_source(snapshot,{'bindings':{'main':'10','transit':'10'}})
        self.assertEqual([],rows)
        self.assertEqual(2,coverage[0]['collapsed_transit_legs'])
        snapshot['transactions'][1]['NUM_DOC']='other-document'
        with self.assertRaises(Deferred):normalized_source(snapshot,{'bindings':{'main':'10','transit':'10'}})

    def test_known_own_bank_accounts_never_become_income_and_expense(self):
        snapshot={'start_date':'2026-08-06','end_date':'2026-08-06','transactions':[
            tx('main','other','EUR','D','100','Move','one'),tx('other','main','EUR','C','100','Move','two')],
            'balances':[balance('main','EUR','100','0','0','100'),balance('other','EUR','0','100','100','0')]}
        rows,_,_=normalized_source(snapshot,{'bindings':{'main':'10','other':'11'}})
        self.assertTrue(all(r['review_own'] for r in rows))

    def test_same_owner_identifier_marks_review_without_literal_description(self):
        for identity in [{'AUT_MY_CRF':'self-id','AUT_CNTR_CRF':'self-id'},
                         {'AUT_MY_NAM':'Own Name','AUT_CNTR_NAM':'Own Name'}]:
            snapshot={'start_date':'2026-08-06','end_date':'2026-08-06','transactions':[
                dict(tx('main','outside','EUR','D','100','Transfer','one'),**identity)],
                'balances':[balance('main','EUR','100','0','0','100')]}
            rows,_,_=normalized_source(snapshot,{'bindings':{'main':'10'}})
            self.assertTrue(rows[0]['review_own'])

    def test_scheduled_engine_repeats_without_new_records(self):
        from monobank_sync import save_json
        from privat_fop_import import synchronize
        from test_monobank_sync import Ledger, asset
        ledger=Ledger([asset('10','EUR','0','0'),asset('11','UAH','50000','50000')])
        with tempfile.TemporaryDirectory() as directory:
            state=Path(directory)
            save_json(state/'snapshot.json',self.snapshot())
            save_json(state/'config.json',{'bindings':{'eur':'10','uah':'11'}})
            with patch('firefly_api.Client',return_value=ledger),patch('monobank_sync.ensure_rates'):
                self.assertEqual({'create':1},synchronize('sync',state,cached=True)['counts'])
                self.assertEqual({'reuse':1},synchronize('sync',state,cached=True)['counts'])
                self.assertEqual(0,synchronize('sync',state,cached=True)['repeat_creates'])
        self.assertEqual(1,len(ledger.groups))

    def test_classification_is_opt_in_and_runs_after_verified_repeat_and_refunds(self):
        from monobank_sync import save_json
        from privat_fop_import import synchronize
        from test_monobank_sync import Ledger, asset
        ledger = Ledger([asset('10', 'EUR', '0', '0'), asset('11', 'UAH', '50000', '50000')])
        order = []
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / 'privat-fop' / 'sync'
            state.mkdir(parents=True)
            save_json(state / 'snapshot.json', self.snapshot())
            cfg = {'bindings': {'eur': '10', 'uah': '11'}}
            save_json(state / 'config.json', cfg)
            def refunds(client, path):
                order.append('refunds')
                return {'classified': 0}
            def classify(client, path):
                self.assertIs(client, ledger)
                self.assertEqual(state.parents[1] / 'bank-classification', path)
                self.assertEqual(1, len(ledger.groups))
                self.assertEqual('50000.00', ledger.accounts[1]['attributes']['current_balance'])
                self.assertEqual(['refunds'], order)
                order.append('classification')
                return {'classified': 1}
            with patch('firefly_api.Client', return_value=ledger), patch('monobank_sync.ensure_rates'), \
                 patch('bank_refunds.synchronize', side_effect=refunds), \
                 patch('bank_classification.synchronize', side_effect=classify) as hook:
                self.assertNotIn('classification', synchronize('sync', state, cached=True))
                hook.assert_not_called()
                cfg.update(auto_refunds=True, auto_classification=True)
                save_json(state / 'config.json', cfg)
                synchronize('plan', state, cached=True)
                hook.assert_not_called()
                result = synchronize('sync', state, cached=True)
                self.assertEqual(0, result['repeat_creates'])
                self.assertEqual({'classified': 1}, result['classification'])
                self.assertEqual(1, hook.call_count)


if __name__=='__main__':unittest.main()
