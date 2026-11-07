import copy
import tempfile
import unittest
from datetime import date
from pathlib import Path

from budget_sync import Deferred, bucket_for, current_limit, period_bounds, refunded_amounts, spending_statistics, synchronize


def journal(identifier,kind='withdrawal',value='100',currency='UAH',**extra):
    return dict({'transaction_journal_id':identifier,'type':kind,'source_id':'1','description':'Merchant',
                 'category_name':'Продукти','amount':value,'currency_code':currency,
                 'currency_id':{'UAH':'4','EUR':'1'}[currency],'date':'2026-11-05T10:00:00+02:00'},**extra)


def group(j):return {'id':j['transaction_journal_id'],'attributes':{'transactions':[j]}}


def config():return {'business_accounts':['3'],'subscription_patterns':['Example subscription'],
                     'category_buckets':{'Продукти':'food'},'budget_ids':{'food':'1','business':'2','business_tax':'3'},
                     'refund_type_ids':['refund']}


def budget(identifier='1',kind='reset',value='1000'):
    return {'id':identifier,'attributes':{'active':True,'auto_budget_period':'monthly',
            'auto_budget_type':kind,'auto_budget_amount':value,'currency_code':'UAH'}}


class BudgetTests(unittest.TestCase):
    def test_refunds_do_not_become_expenses_and_only_refund_links_count(self):
        groups=[group(journal('1')),group(journal('2','deposit','75'))]
        links=[{'attributes':{'inward_id':'1','outward_id':'2','link_type_id':'refund'}}]
        self.assertEqual({'1':75},refunded_amounts(groups,links,['refund']))
        self.assertEqual('25.00',spending_statistics(groups,links,config())['2026-11']['food']['UAH'])
        self.assertEqual({},dict(refunded_amounts(groups,links,['other'])))
        groups[1]['attributes']['transactions'][0]['amount']='101'
        with self.assertRaises(Deferred):refunded_amounts(groups,links,['refund'])

    def test_shared_refund_is_not_counted_twice(self):
        groups=[group(journal('1')),group(journal('2')),group(journal('3','deposit'))]
        links=[{'attributes':{'inward_id':key,'outward_id':'3','link_type_id':'refund'}} for key in ['1','2']]
        with self.assertRaises(Deferred):refunded_amounts(groups,links,['refund'])

    def test_business_and_subscription_precedence(self):
        self.assertEqual('business',bucket_for(journal('1',source_id='3'),config()))
        self.assertEqual('subscriptions',bucket_for(journal('1',description='Example subscription'),config()))
        self.assertIsNone(bucket_for(journal('1','transfer'),config()))

    def test_owner_can_confirm_business_purpose_from_personal_account(self):
        cfg=config();cfg['bucket_overrides']={'1':'business'}
        self.assertEqual('business',bucket_for(journal('1',category_name='Послуги'),cfg))
        self.assertEqual('pending',bucket_for(journal('1',category_name='Потребує уточнення'),cfg))

    def test_rollover_catches_missed_months_and_ignores_foreign_currency(self):
        limits=[{'id':'1','attributes':{'currency_code':'UAH','start':'2026-11-01','end':'2026-11-30','amount':'25000'}}]
        groups=[group(journal('1',value='18000',budget_id='1')),
                group(journal('2',value='800',currency='EUR',budget_id='1'))]
        self.assertEqual('15000.00',current_limit(budget(kind='rollover',value='8000'),limits,groups,date(2026,12,2)))
        self.assertEqual('23000.00',current_limit(budget(kind='rollover',value='8000'),limits,groups,date(2027,1,2)))
        groups.append(group(journal('3',value='20000',budget_id='1',date='2026-12-02T12:00:00+02:00')))
        self.assertEqual('8000.00',current_limit(budget(kind='rollover',value='8000'),limits,groups,date(2027,1,2)))

    def test_existing_manual_budgets_preserved_and_reversals_excluded(self):
        groups=[group(journal('1')),group(journal('2',budget_id='manual')),group(journal('3',budget_id='1')),
                group(journal('4','deposit'))]
        links=[{'attributes':{'inward_id':'3','outward_id':'4','link_type_id':'refund'}}]
        class Client:
            def __init__(self):self.calls=[]
            def all(self,path):
                if path=='/transactions?type=all':return groups
                if path=='/transaction-links':return links
                if path=='/budgets':return [budget('1'),budget('2'),budget('3')]
                return [{'attributes':{'currency_code':'UAH','start':'2026-11-01','end':'2026-11-30','amount':'1000'}}]
            def request(self,method,path,data):self.calls.append((method,path,data))
        client=Client()
        before=copy.deepcopy(groups)
        with tempfile.TemporaryDirectory() as d:
            result=synchronize(client,config(),Path(d),date(2026,11,6))
        self.assertEqual((2,1,1),(result['assigned'],result['manual_preserved'],result['fully_refunded_excluded']))
        for method,path,data in client.calls:
            self.assertEqual('PUT',method)
            for patch in data['transactions']:self.assertEqual({'transaction_journal_id','budget_id'},set(patch))
        for old,new in zip(before,groups):
            a,b=old['attributes']['transactions'][0],new['attributes']['transactions'][0]
            self.assertEqual({k:v for k,v in a.items() if k!='budget_id'},{k:v for k,v in b.items() if k!='budget_id'})

    def test_removed_business_budget_does_not_break_synchronization(self):
        cfg=config()
        cfg['budget_ids'].pop('business')
        groups=[group(journal('1',source_id='3'))]
        class Client:
            def all(self,path):
                if path=='/transactions?type=all':return groups
                if path=='/transaction-links':return []
                if path=='/budgets':return [budget('1'),budget('3')]
                return [{'attributes':{'currency_code':'UAH','start':'2026-11-01','end':'2026-11-30','amount':'1000'}}]
            def request(self,*args):raise AssertionError('Removed budget must not cause API mutations')
        with tempfile.TemporaryDirectory() as d:
            result=synchronize(Client(),cfg,Path(d),date(2026,11,6))
        self.assertEqual(0,result['assigned'])
        self.assertEqual(0,result['limits_created'])

    def test_quarterly_bounds_and_reset_cross_year_and_leap_day(self):
        self.assertEqual((date(2026,10,1),date(2026,12,31)),period_bounds(date(2026,11,6),'quarterly'))
        self.assertEqual((date(2027,1,1),date(2027,3,31)),period_bounds(date(2027,1,1),'quarterly'))
        self.assertEqual((date(2028,1,1),date(2028,3,31)),period_bounds(date(2028,2,29),'quarterly'))
        q=budget(value='24000')
        q['attributes']['auto_budget_period']='quarterly'
        self.assertEqual('24000.00',current_limit(q,[],[],date(2026,11,6)))

    def test_pending_subscription_never_gets_a_budget(self):
        cfg=config();cfg['budget_ids']['subscriptions']='2'
        groups=[group(journal('1',description='Example subscription',category_name='Потребує уточнення'))]
        self.assertEqual('pending',bucket_for(groups[0]['attributes']['transactions'][0],cfg))
        self.assertEqual({'pending':{'UAH':'100.00'}},spending_statistics(groups,[],cfg)['2026-11'])
        self.assertEqual('pending',bucket_for(journal('2',source_id='3',category_name='Потребує уточнення'),cfg))
        class Client:
            def all(self,path):
                if path=='/transactions?type=all':return groups
                if path=='/transaction-links':return []
                if path=='/budgets':return [budget('1'),budget('2'),budget('3')]
                return [{'attributes':{'currency_code':'UAH','start':'2026-11-01','end':'2026-11-30','amount':'1000'}}]
            def request(self,*args):raise AssertionError('Pending payment must remain unbudgeted')
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(0,synchronize(Client(),cfg,Path(d),date(2026,11,6))['assigned'])

    def test_quarterly_limit_is_not_recreated_monthly(self):
        cfg=config();cfg['budget_ids']={'food':'1'}
        q=budget(value='24000');q['attributes']['auto_budget_period']='quarterly'
        class Client:
            def __init__(self):self.limits=[];self.calls=[]
            def all(self,path):
                if path in ['/transactions?type=all','/transaction-links']:return []
                if path=='/budgets':return [q]
                return self.limits
            def request(self,method,path,data):
                self.calls.append(data)
                self.limits.append({'attributes':dict(data)})
        client=Client()
        with tempfile.TemporaryDirectory() as d:
            state=Path(d)
            self.assertEqual(1,synchronize(client,cfg,state,date(2026,11,6))['limits_created'])
            self.assertEqual(0,synchronize(client,cfg,state,date(2026,12,2))['limits_created'])
            self.assertEqual(1,synchronize(client,cfg,state,date(2027,1,2))['limits_created'])
        self.assertEqual([('2026-10-01','2026-12-31'),('2027-01-01','2027-03-31')],[(x['start'],x['end']) for x in client.calls])


if __name__=='__main__':unittest.main()
