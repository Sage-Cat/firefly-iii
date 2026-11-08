import copy
import json
import tempfile
import unittest
from datetime import datetime
from pathlib import Path
from unittest.mock import patch

import monobank_sync as sync
from monobank_authorizations import PENDING_TAG, authorization, managed_pending
from test_monobank_sync import Ledger, asset, record, timestamp


class AuthorizationLedger(Ledger):
    def __init__(self, accounts):
        super().__init__(accounts)
        self.sequence = 0
        self.preconditions = []

    def request(self, method, path, data=None, **options):
        if options:
            self.preconditions.append((method, options))
            if len(options.get('bank_authorization_hash', '')) != 64:
                raise AssertionError('Authorization mutation must have a bound precondition')
        if method == 'DELETE':
            group = next(g for g in self.groups if g['id'] == path.split('/')[-1])
            assets = {a['id']: a['attributes'] for a in self.accounts}
            for own, value in sync.effects(group['attributes']['transactions'][0], assets).items():
                assets[own]['current_balance'] = sync.amount(sync.money(assets[own]['current_balance']) - value)
            self.groups.remove(group)
            return None
        result = super().request(method, path, data)
        if method == 'POST':
            group = self.groups[-1]
            self.sequence += 1
            group['id'] = str(1000 + self.sequence)
            j = group['attributes']['transactions'][0]
            j['transaction_journal_id'] = str(2000 + self.sequence)
            j.setdefault('source_id', '900')
            j.setdefault('destination_id', '901')
            j.setdefault('currency_code', 'UAH')
            result = {'data': copy.deepcopy(group)}
        return result


class AuthorizationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.state = Path(self.temp.name)
        self.config = {'start_date': '2026-11-01', 'pending_authorizations': True,
                       'bindings': [{'bank_id': 'bank-a', 'firefly_id': '1', 'currency_code': 'UAH'}]}
        self.initial = record('initial', 10000, '2026-11-07T09:00:00', balance=10000, counterName='Customer')
        self.hold = record('hold', -1000, '2026-11-07T10:00:00', balance=9000,
                           hold=True, description='Glovo', counterName='Glovo')
        self.snapshot = {'client_info': {'request_started_at': timestamp('2026-11-08'),
                                        'data': {'accounts': [{'id': 'bank-a', 'currencyCode': 980, 'creditLimit': 0, 'balance': 10000}]}},
                         'statements': {sync.statement_file('bank-a'): {
                             'account_id': 'bank-a', 'fetched_at': timestamp('2026-11-08'),
                             'from': timestamp('2026-11-01'), 'refresh_from': timestamp('2026-11-01'),
                             'to': timestamp('2026-11-08'), 'items': [self.initial]}}}
        self.ledger = AuthorizationLedger([asset('1', 'UAH', '100.00')])
        self.apply()

    @property
    def items(self):
        return self.snapshot['statements'][sync.statement_file('bank-a')]['items']

    def balance(self, value):
        self.snapshot['client_info']['data']['accounts'][0]['balance'] = value

    def plan(self):
        frozen = sync.read_json(self.state/'baseline.json') if (self.state/'baseline.json').exists() else None
        return sync.plan_sync(self.config, self.snapshot, self.ledger.accounts, self.ledger.groups, frozen)

    def apply(self):
        planned = self.plan()
        return sync.apply_plan(self.ledger, planned, self.state, self.config)

    def pending(self):
        self.items.append(self.hold)
        self.balance(9000)
        self.assertEqual(self.apply()['create'], 1)
        return self.ledger.groups[-1]['attributes']['transactions'][0]

    def test_pending_spending_is_visible_and_repeat_does_not_duplicate(self):
        j = self.pending()
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'], '90.00')
        self.assertTrue(managed_pending(j))
        self.assertIn(PENDING_TAG, j['tags'])
        self.assertEqual(self.apply(), {'create': 0, 'update': 0, 'reuse': 2})
        self.assertEqual(len(self.ledger.groups), 2)
        self.assertFalse(self.plan()['openings'])

    def test_settlement_updates_same_row_and_clears_only_managed_status(self):
        j = self.pending()
        identifier = j['transaction_journal_id']
        self.hold['hold'] = False
        j.update(category_id='5', category_name='Chosen', budget_id='6', tags=j['tags']+['Manual'])
        notes = json.loads(j['notes']);notes['memo'] = 'Owner note';j['notes'] = json.dumps(notes)
        self.assertEqual(self.apply(), {'create': 0, 'update': 1, 'reuse': 1})
        self.assertEqual(j['transaction_journal_id'], identifier)
        self.assertEqual(authorization(j)['status'], 'settled')
        self.assertNotIn(PENDING_TAG, j['tags'])
        self.assertIn('Manual', j['tags'])
        self.assertEqual((j['category_id'], j['budget_id'], json.loads(j['notes'])['memo']), ('5','6','Owner note'))
        self.assertEqual(self.apply(), {'create': 0, 'update': 0, 'reuse': 2})

    def test_final_amount_correction_preserves_opening_and_category(self):
        j = self.pending();opening=self.ledger.accounts[0]['attributes']['opening_balance']
        self.hold.update(hold=False, amount=-950, balance=9050);self.balance(9050)
        j['category_name']='Chosen'
        self.assertEqual(self.apply()['update'], 1)
        self.assertEqual(j['amount'], '9.50')
        self.assertEqual(j['category_name'], 'Chosen')
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'], '90.50')
        self.assertEqual(self.ledger.accounts[0]['attributes']['opening_balance'], opening)

    def test_missing_pending_is_released_without_inventing_income(self):
        self.pending();self.items.remove(self.hold);self.balance(10000)
        planned=self.plan();self.assertEqual(len(planned['authorization_voids']), 1)
        self.assertEqual(self.apply()['void'], 1)
        self.assertEqual(len(self.ledger.groups), 1)
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'], '100.00')
        self.assertEqual(self.apply(), {'create':0,'update':0,'reuse':1})

    def test_zeroed_authorization_is_released(self):
        self.pending();self.hold.update(amount=0, balance=10000, hold=False);self.balance(10000)
        self.assertEqual(self.apply()['void'], 1)
        self.assertEqual(len(self.ledger.groups), 1)

    def test_missing_hold_outside_refreshed_coverage_is_not_deleted(self):
        self.pending();self.items.remove(self.hold);self.balance(10000)
        self.snapshot['statements'][sync.statement_file('bank-a')]['refresh_from']=timestamp('2026-11-07T11:00:00')
        with self.assertRaisesRegex(sync.Deferred, 'freshly covered'):
            self.plan()
        self.assertEqual(len(self.ledger.groups), 2)

    def test_settled_disappearance_is_never_automatically_deleted(self):
        self.pending();self.hold['hold']=False;self.apply()
        self.items.remove(self.hold);self.balance(10000)
        with self.assertRaisesRegex(sync.Deferred, 'predicted ledger'):
            self.plan()

    def test_missing_hold_requires_matching_account_and_source_capture(self):
        self.pending();self.items.remove(self.hold);self.balance(10000)
        envelope=self.snapshot['statements'][sync.statement_file('bank-a')]
        for field,value in [('account_id','other-bank'),('to',timestamp('2026-11-09')),('fetched_at',timestamp('2026-11-07'))]:
            old=envelope[field];envelope[field]=value
            with self.assertRaisesRegex(sync.Deferred,'coherent account-bound'):
                self.plan()
            envelope[field]=old

    def test_changed_bank_id_does_not_double_count_spending(self):
        old=self.pending();old_id=old['transaction_journal_id']
        old.update(category_id='5',category_name='Chosen',budget_id='6',bill_id='7',tags=old['tags']+['Manual'])
        notes=json.loads(old['notes']);notes['memo']='Owner note';old['notes']=json.dumps(notes)
        self.items.remove(self.hold);self.items.append(dict(self.hold,id='settled-new-id',hold=False))
        result=self.apply()
        self.assertEqual((result['void'],result['create']), (1,1))
        self.assertEqual(len(self.ledger.groups), 2)
        self.assertNotEqual(self.ledger.groups[-1]['attributes']['transactions'][0]['transaction_journal_id'],old_id)
        replacement=self.ledger.groups[-1]['attributes']['transactions'][0]
        self.assertEqual((replacement['category_name'],replacement['budget_id'],replacement['bill_id']),('Chosen','6','7'))
        self.assertEqual(json.loads(replacement['notes'])['memo'],'Owner note')
        self.assertIn('Manual',replacement['tags'])
        self.assertNotIn(PENDING_TAG,replacement['tags'])
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'],'90.00')
        self.assertEqual(self.apply(),{'create':0,'update':0,'reuse':2})

    def test_replacement_created_before_failed_delete_retains_owner_choices_on_retry(self):
        old=self.pending();old['category_name']='Chosen'
        self.items.remove(self.hold);self.items.append(dict(self.hold,id='settled-new-id',hold=False))
        native=self.ledger.request
        def fail_delete(method,path,data=None,**options):
            if method=='DELETE':raise sync.Deferred('Concurrent owner edit')
            return native(method,path,data,**options)
        with patch.object(self.ledger,'request',side_effect=fail_delete):
            with self.assertRaisesRegex(sync.Deferred,'Concurrent owner edit'):self.apply()
        self.assertEqual(len(self.ledger.groups),3)
        self.assertEqual(self.apply(),{'create':0,'update':0,'reuse':2,'void':1})
        self.assertEqual(self.ledger.groups[-1]['attributes']['transactions'][0]['category_name'],'Chosen')
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'],'90.00')

    def test_missing_release_after_a_later_settled_payment(self):
        self.pending()
        later=record('later',-500,'2026-11-07T11:00:00',balance=8500,counterName='Shop')
        self.items.append(later);self.balance(8500);self.apply()
        self.items.remove(self.hold);self.balance(9500)
        self.assertEqual(self.apply()['void'],1)
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'],'95.00')
        self.assertFalse(self.plan()['openings'])

    def test_replacement_retry_preserves_a_concurrent_owner_edit_for_review(self):
        old=self.pending();old['category_name']='First choice'
        self.items.remove(self.hold);self.items.append(dict(self.hold,id='settled-new-id',hold=False))
        native=self.ledger.request
        def fail_delete(method,path,data=None,**options):
            if method=='DELETE':raise sync.Deferred('Concurrent owner edit')
            return native(method,path,data,**options)
        with patch.object(self.ledger,'request',side_effect=fail_delete):
            with self.assertRaisesRegex(sync.Deferred,'Concurrent owner edit'):self.apply()
        old['category_name']='Latest owner choice'
        notes=json.loads(old['notes']);notes['memo']='New owner memo';old['notes']=json.dumps(notes)
        with self.assertRaisesRegex(sync.Deferred,'different owner metadata'):self.plan()
        self.assertIn(old,[g['attributes']['transactions'][0] for g in self.ledger.groups])
        self.assertEqual(json.loads(old['notes'])['memo'],'New owner memo')

    def test_release_guard_counts_only_holds_before_latest_remaining_row(self):
        self.pending()
        later=record('later',-500,'2026-11-07T11:00:00',balance=8500,counterName='Shop')
        last=dict(self.hold,id='new-hold',time=timestamp('2026-11-07T12:00:00'),amount=-200,balance=8300)
        self.items.extend([later,last]);self.balance(8300);self.apply()
        self.items.remove(self.hold);self.items.remove(last);self.balance(9500)
        for target in (9499,9501):
            self.balance(target)
            with self.assertRaisesRegex(sync.Deferred,'closing balance'):self.plan()
        self.balance(9500)
        self.assertEqual(self.apply()['void'],2)
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'],'95.00')

    def test_pending_tag_without_protocol_cannot_bypass_guard(self):
        j=self.pending();j['notes']='Owner replacement text';self.hold.update(hold=False,amount=-950,balance=9050);self.balance(9050)
        with self.assertRaisesRegex(sync.Deferred,'lost its protocol'):self.plan()
        self.assertEqual(j['amount'],'10.00')

    def test_ambiguous_id_replacement_leaves_category_for_owner_review(self):
        from bank_classification import plan
        old=self.pending();old['category_name']='Chosen'
        self.items.remove(self.hold)
        self.items.extend([dict(self.hold,id='settled-a',hold=False),dict(self.hold,id='settled-b',hold=False,balance=8000)])
        self.balance(8000)
        self.assertEqual(self.apply()['void'],1)
        replacements=[g['attributes']['transactions'][0] for g in self.ledger.groups[1:]]
        self.assertTrue(all(json.loads(j['notes'])['monobank_authorization_review'] for j in replacements))
        cfg={'version':1,'merchant_rules':[{'prefix':'Glovo','category':'Доставка їжі'}]}
        self.assertFalse(plan(cfg,self.ledger.groups,self.ledger.accounts,[],[])['updates'])

    def cancellation(self):
        return record('refund',1000,'2026-11-07T10:01:00',balance=10000,
                      description='Скасування. Glovo',counterName='Glovo')

    def test_explicit_refund_of_missing_pending_keeps_both_bank_legs(self):
        j=self.pending();self.items.remove(self.hold);self.items.append(self.cancellation());self.balance(10000)
        result=self.apply()
        self.assertEqual((result['create'],result['update']), (1,1))
        self.assertNotIn('void',result)
        self.assertEqual(len(self.ledger.groups),3)
        self.assertEqual(authorization(j)['status'],'settled')
        self.assertTrue(authorization(j)['cancelled_by'])
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'],'100.00')
        self.assertEqual(self.apply(),{'create':0,'update':0,'reuse':2})

    def test_explicit_refund_clears_pending_even_when_bank_keeps_hold_flag(self):
        j=self.pending();self.items.append(self.cancellation());self.balance(10000)
        self.assertEqual(self.apply()['update'],1)
        self.assertEqual(authorization(j)['status'],'settled')
        self.assertNotIn(PENDING_TAG,j['tags'])
        self.assertEqual(self.ledger.accounts[0]['attributes']['current_balance'],'100.00')

    def test_unexplained_bank_balance_difference_still_blocks_writes(self):
        self.pending();self.balance(9001)
        with self.assertRaisesRegex(sync.Deferred,'closing balance'):
            self.plan()

    def test_positive_pending_is_not_premature_income(self):
        row=dict(self.hold,id='income-hold',amount=1000,balance=11000)
        self.items.append(row);self.balance(11000)
        with self.assertRaisesRegex(sync.Deferred,'Incoming pending'):
            self.plan()

    def test_confirmed_merchant_rule_can_classify_a_managed_pending_payment(self):
        from bank_classification import plan
        j=self.pending()
        cfg={'version':1,'merchant_rules':[{'prefix':'Glovo','category':'Доставка їжі'}]}
        result=plan(cfg,self.ledger.groups,self.ledger.accounts,[],[])
        self.assertEqual(len(result['updates']),1)
        self.assertEqual(result['updates'][0]['journal_id'],j['transaction_journal_id'])
        self.assertEqual(result['updates'][0]['category'],'Доставка їжі')

    def test_collect_refreshes_the_oldest_authorization_beyond_seven_days(self):
        old=copy.deepcopy(self.snapshot);old['statements'][sync.statement_file('bank-a')]['items'].append(self.hold)
        old['statements'][sync.statement_file('bank-a')]['to']=timestamp('2026-11-19')
        sync.save_json(self.state/'snapshot.json',old)
        class Bank:
            def client_info(bank):return self.snapshot['client_info']['data']
            def statement(bank,account,start,end):
                bank.beginning=start
                return []
        bank=Bank()
        with patch.object(sync.time,'time',return_value=timestamp('2026-11-20')):
            result=sync.collect(bank,self.config,self.state)
        self.assertEqual(bank.beginning,self.hold['time'])
        self.assertEqual(result['statements'][sync.statement_file('bank-a')]['refresh_from'],self.hold['time'])

    def test_native_pending_retains_refresh_floor_after_source_changed_and_apply_failed(self):
        self.pending();self.items.remove(self.hold);self.balance(10000)
        old=copy.deepcopy(self.snapshot);old['statements'][sync.statement_file('bank-a')]['to']=timestamp('2026-11-19')
        sync.save_json(self.state/'snapshot.json',old)
        class Bank:
            MAX_STATEMENT_SECONDS=sync.BankClient.MAX_STATEMENT_SECONDS
            def client_info(bank):return self.snapshot['client_info']['data']
            def statement(bank,account,start,end):bank.beginning=start;return []
        bank=Bank()
        with patch.object(sync.time,'time',return_value=timestamp('2026-11-20')), \
                patch.object(sync,'BankClient',return_value=bank) as client,patch.object(sync,'FireflyClient',return_value=self.ledger):
            client.MAX_STATEMENT_SECONDS=bank.MAX_STATEMENT_SECONDS
            result=sync.run('plan',state_dir=self.state)
        self.assertEqual(bank.beginning,self.hold['time'])
        self.assertEqual(result['actions']['void'],1)
        self.assertEqual(len(self.ledger.groups),2)


if __name__=='__main__':unittest.main()
