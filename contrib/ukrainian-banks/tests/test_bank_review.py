"""Synthetic review decisions and bank reconciliation; no credentials or network."""
import copy
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

import bank_review as review
import monobank_sync as mono
from privat_fop_import import bank_key, plan_import
from privat_personal_import import apply_plan as apply_privat
from test_monobank_sync import Ledger, asset, fixture, record
from test_privat_fop_import import balance, tx


def technical(identifier='90', code='EUR'):
    result=asset(identifier,code,'0','0')
    result['attributes']['notes']=json.dumps({'bank_review_suspense':1,'currency':code})
    return result


def group(transaction,identifier='100'):
    return {'id':identifier,'attributes':{'transactions':[dict(transaction,transaction_journal_id='journal-'+identifier)]}}


def choice(journal,kind,**fields):
    notes=json.loads(journal['notes']);notes['bank_review'].update(status='decided',decision=dict(kind=kind,**fields))
    journal['notes']=json.dumps(notes)


class ReviewTests(unittest.TestCase):
    def setUp(self):
        self.accounts=[asset('10','EUR','100'),asset('12','EUR','1000'),asset('11','UAH','100'),technical()]
        self.assets={a['id']:a['attributes'] for a in self.accounts}
        self.tx={'external_id':'bank-one:key','date':'2026-11-08T03:00:00+02:00','currency_id':'1',
                 'amount':'100.00','description':'Original bank purpose','notes':json.dumps({'original':'proof'}),
                 'type':'deposit','source_name':'My name','destination_id':'10'}

    def pending(self,direction='in',transaction=None,groups=None):
        return review.apply_review(transaction or self.tx,'10',direction,'EUR',self.assets,groups or [],True,'My name')

    def test_pending_directions_preserve_proof_and_have_no_category(self):
        for direction,source,destination in [('in','90','10'),('out','10','90')]:
            t=self.pending(direction)
            self.assertEqual(('transfer',source,destination,'100.00'),(t['type'],t['source_id'],t['destination_id'],t['amount']))
            self.assertNotIn('category_name',t);self.assertNotIn('category_id',t)
            n=json.loads(t['notes']);self.assertEqual('proof',n['original']);self.assertEqual('pending',n['bank_review']['status'])

    def test_foreign_merchant_amount_is_evidence_not_suspense_destination_currency(self):
        incoming=dict(self.tx,foreign_currency_id='4',foreign_amount='5000.00')
        incoming['notes']=json.dumps({'transaction_currency':'UAH','transaction_amount':'5000.00'})
        t=self.pending(transaction=incoming)
        self.assertNotIn('foreign_currency_id',t);self.assertNotIn('foreign_amount',t)
        self.assertEqual('5000.00',json.loads(t['notes'])['transaction_amount'])

    def test_confirmed_transfer_and_external_decisions_are_bound_and_resolved(self):
        for kind,fields in [('transfer',{'account_id':'12'}),('external',{'category_id':'7'})]:
            old=self.pending();choice(old,kind,**fields)
            t=self.pending(groups=[group(old)])
            self.assertEqual('resolved',json.loads(t['notes'])['bank_review']['status'])
            self.assertEqual('100.00',t['amount']);self.assertEqual(self.tx['date'],t['date'])
            if kind=='transfer':self.assertEqual('12',t['source_id'])
            else:self.assertEqual(('deposit',None,'7'),(t['type'],t['source_id'],t['category_id']))
            self.assertEqual(t,self.pending(groups=[group(t)]))

    def test_resolved_external_manual_category_and_user_notes_survive(self):
        old=self.pending();choice(old,'external',category_id='7');done=self.pending(groups=[group(old)])
        done['category_id']='8';notes=json.loads(done['notes']);notes['user_note']='Keep this';done['notes']=json.dumps(notes)
        again=self.pending(groups=[group(done)])
        self.assertEqual('8',again['category_id']);self.assertEqual('Keep this',json.loads(again['notes'])['user_note'])

    def test_changed_source_amount_or_date_cannot_apply_old_choice(self):
        old=self.pending();choice(old,'transfer',account_id='12')
        for changes in [{'amount':'101.00'},{'date':'2026-11-08T03:01:00+02:00'}]:
            with self.assertRaises(mono.Deferred):self.pending(transaction=dict(self.tx,**changes),groups=[group(old)])

    def test_wrong_currency_suspense_or_same_account_decision_is_rejected(self):
        for identifier in ['10','11','90','missing']:
            old=self.pending();choice(old,'transfer',account_id=identifier)
            with self.assertRaises(mono.Deferred):self.pending(groups=[group(old)])

    def test_opposing_raw_bank_leg_reuses_resolved_transfer_and_saves_alias(self):
        old=self.pending();choice(old,'transfer',account_id='12');done=self.pending(groups=[group(old)])
        opposite=dict(self.tx,external_id='bank-two:other',date='2026-11-08T03:01:00+02:00')
        matched=review.apply_review(opposite,'12','out','EUR',self.assets,[group(done)],True)
        self.assertEqual(done['date'],matched['date'])
        self.assertEqual(('12','10'),(matched['source_id'],matched['destination_id']))
        notes=json.loads(matched['notes']);self.assertIn(opposite['external_id'],notes['bank_review_aliases'])
        self.assertEqual(opposite['date'],notes['bank_review_sources'][opposite['external_id']]['date'])
        persisted=dict(matched,external_id=done['external_id'])
        self.assertEqual(matched,review.apply_review(opposite,'12','out','EUR',self.assets,[group(persisted)],True))

    def test_new_equal_same_side_payment_is_not_alias_of_resolved_payment(self):
        old=self.pending();choice(old,'transfer',account_id='12');done=self.pending(groups=[group(old)])
        later=dict(self.tx,external_id='bank-one:second',date='2026-11-08T03:01:00+02:00')
        t=self.pending(transaction=later,groups=[group(done)])
        self.assertEqual('90',t['source_id'])
        self.assertEqual('pending',json.loads(t['notes'])['bank_review']['status'])
        self.assertEqual('bank-one:second',t['external_id'])

    def test_second_opposing_source_key_is_a_new_pending_payment(self):
        old=self.pending();choice(old,'transfer',account_id='12');done=self.pending(groups=[group(old)])
        opposite=dict(self.tx,external_id='bank-two:first',date='2026-11-08T03:01:00+02:00')
        adopted=review.apply_review(opposite,'12','out','EUR',self.assets,[group(done)],True)
        persisted=dict(adopted,external_id=done['external_id'])
        another=dict(opposite,external_id='bank-two:second')
        t=review.apply_review(another,'12','out','EUR',self.assets,[group(persisted)],True)
        self.assertEqual('90',t['destination_id'])
        self.assertEqual('pending',review.review_of(t)['status'])

    def test_unequal_ambiguous_or_old_opposite_candidate_remains_pending(self):
        old=self.pending();choice(old,'transfer',account_id='12');done=self.pending(groups=[group(old)])
        for source,groups in [(dict(self.tx,amount='99.00'),[group(done)]),
                              (dict(self.tx,date='2026-11-08T03:11:00+02:00'),[group(done)]),
                              (self.tx,[group(done),group(dict(done,external_id='another:key'),'101')])]:
            source=dict(source,external_id='other-bank:next')
            t=review.apply_review(source,'12','out','EUR',self.assets,groups,True)
            self.assertEqual('pending',json.loads(t['notes'])['bank_review']['status'])
            self.assertEqual('90',t['destination_id'])

    def test_two_pending_legs_do_not_create_duplicate_transfer_after_one_choice(self):
        incoming=self.pending();outgoing=review.apply_review(dict(self.tx,external_id='bank-two:other'),'12','out','EUR',self.assets,[],True)
        choice(incoming,'transfer',account_id='12')
        t=self.pending(groups=[group(incoming),group(outgoing,'101')])
        self.assertEqual(('90','10'),(t['source_id'],t['destination_id']))
        state=json.loads(t['notes'])['bank_review'];self.assertEqual('decided',state['status']);self.assertEqual('paired_pending',state['resolution_issue'])
        self.assertFalse(review.review_update(incoming,t));self.assertTrue(review.review_notes_update(incoming,t))

    def test_provision_only_needed_currencies_and_reuse_marked_account(self):
        pending=self.pending();client=Mock()
        self.assertEqual(self.accounts,review.prepare_suspense(client,copy.deepcopy(self.accounts),[{'transaction':pending}]))
        client.request.assert_not_called()
        accounts=self.accounts[:-1];pending=self.pending();client.request.return_value={'data':technical()}
        result=review.prepare_suspense(client,accounts,[{'transaction':pending}])
        self.assertEqual(5-1,len(result));self.assertEqual('POST',client.request.call_args.args[0]);self.assertEqual('/accounts',client.request.call_args.args[1])
        self.assertEqual('Перекази на уточненні (EUR)',client.request.call_args.args[2]['name'])
        self.assertNotIn('opening_balance',client.request.call_args.args[2])


class ConnectorReviewTests(unittest.TestCase):
    def mono(self):
        config,snapshot,accounts=fixture();config['approved_transfers']={}
        accounts.append(technical());accounts[2]=asset('12','EUR','5000')
        return config,snapshot,Ledger(accounts)

    def test_monobank_pending_batch_decision_and_repeat_reconcile_without_changed_bank_balance(self):
        config,snapshot,ledger=self.mono()
        with tempfile.TemporaryDirectory() as tmp:
            state=Path(tmp);first=mono.plan_sync(config,snapshot,ledger.accounts,[])
            self.assertNotIn('90',first['baseline']['accounts'])
            mono.apply_plan(ledger,first,state)
            original=copy.deepcopy(ledger.accounts[0]['attributes']['current_balance'])
            bank=ledger.groups[0]['attributes']['transactions'][0];choice(bank,'transfer',account_id='12')
            plan=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            self.assertEqual(1,sum(o['action']=='update' for o in plan['operations']))
            mono.apply_plan(ledger,plan,state)
            self.assertEqual(original,ledger.accounts[0]['attributes']['current_balance'])
            self.assertEqual('2900.00',ledger.accounts[2]['attributes']['current_balance'])
            again=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            self.assertTrue(all(o['action']=='reuse' for o in again['operations']))
            self.assertEqual(2,len(ledger.groups))

    def test_privat_fop_new_own_transfer_stages_then_resolves_with_frozen_openings(self):
        snapshot={'start_date':'2026-08-06','end_date':'2026-08-06','transactions':[
            tx('eur','unknown','EUR','D','100','Переказ власних коштів','pending'),
            tx('eur','merchant','EUR','D','5','Ordinary purchase','ordinary')],
            'balances':[balance('eur','EUR','200','95','0','105')]}
        config={'bindings':{'eur':'10'}}
        ledger=Ledger([asset('10','EUR','95','95'),asset('12','EUR','1000'),technical()])
        first=plan_import(snapshot,config,ledger.accounts,[])
        apply_privat(ledger,first)
        pending=next(j for g in ledger.groups for j in g['attributes']['transactions'] if j.get('type')=='transfer')
        choice(pending,'transfer',account_id='12')
        following=plan_import(snapshot,config,ledger.accounts,ledger.groups,first)
        self.assertEqual({'reuse':1,'update':1},apply_privat(ledger,following))
        self.assertEqual('95.00',ledger.accounts[0]['attributes']['current_balance'])
        self.assertEqual('1100.00',ledger.accounts[1]['attributes']['current_balance'])
        self.assertEqual('0.00',ledger.accounts[2]['attributes']['current_balance'])
        again=plan_import(snapshot,config,ledger.accounts,ledger.groups,following)
        self.assertTrue(all(o['action']=='reuse' for o in again['operations']))
        ordinary=next(j for g in ledger.groups for j in g['attributes']['transactions'] if j.get('type')=='withdrawal')
        self.assertFalse(ordinary.get('category_name'))

    def test_known_fop_peer_and_fx_pair_remain_confirmed_without_review(self):
        snapshot={'start_date':'2026-08-06','end_date':'2026-08-06','transactions':[
            tx('eur','outside','EUR','D','100','Переказ власних коштів','known')],
            'balances':[balance('eur','EUR','200','100','0','100')]}
        config={'bindings':{'eur':'10'},'peers':{bank_key(snapshot['transactions'][0]):'12'}}
        ledger=Ledger([asset('10','EUR','100'),asset('12','EUR','1000')])
        first=plan_import(snapshot,config,ledger.accounts,[])
        self.assertIsNone(review.review_of(first['operations'][0]['transaction']))
        apply_privat(ledger,first)
        again=plan_import(snapshot,config,ledger.accounts,ledger.groups,first)
        self.assertEqual('reuse',again['operations'][0]['action'])

    def test_initial_fop_replan_before_apply_keeps_peer_opening_compensation(self):
        snapshot={'start_date':'2026-08-06','end_date':'2026-08-06','transactions':[
            tx('eur','outside','EUR','D','100','Переказ власних коштів','known')],
            'balances':[balance('eur','EUR','200','100','0','100')]}
        config={'bindings':{'eur':'10'},'peers':{bank_key(snapshot['transactions'][0]):'12'}}
        ledger=Ledger([asset('10','EUR','100'),asset('12','EUR','1000')])
        first=plan_import(snapshot,config,ledger.accounts,[])
        second=plan_import(snapshot,config,ledger.accounts,[],first)
        self.assertEqual({k:mono.money(v) for k,v in first['preserved_peers'].items()},
                         {k:mono.money(v) for k,v in second['preserved_peers'].items()})
        self.assertEqual({'create':1},apply_privat(ledger,second))
        self.assertEqual('1000.00',ledger.accounts[1]['attributes']['current_balance'])

    def resolved_other_bank_transfer(self):
        accounts=[asset('10','EUR','100','0'),asset('12','EUR','1000','1100'),technical()]
        assets={a['id']:a['attributes'] for a in accounts}
        raw={'external_id':'first-bank:incoming','date':'2026-11-08T03:00:00+02:00',
             'currency_id':'1','amount':'100.00','description':'Confirmed first-bank leg','notes':'{}'}
        pending=review.apply_review(raw,'10','in','EUR',assets,[],True)
        choice(pending,'transfer',account_id='12')
        resolved=review.apply_review(raw,'10','in','EUR',assets,[group(pending)],True)
        ledger=Ledger(accounts);ledger.groups=[group(resolved)]
        return ledger

    def test_personal_plan_reserves_resolved_counterpart_once_per_batch_and_repeats(self):
        from privat_personal_import import build_plan
        ledger=self.resolved_other_bank_transfer()
        rows=[]
        for number,minute in [('one','00'),('two','01')]:
            rows.append({'A':'08.11.2026 03:'+minute+':00','B':'Переказ на свою картку','C':'bank-card',
                         'D':'Переказ власних коштів','E':'-100','F':'EUR','G':'100','H':'EUR','I':'','J':'EUR',
                         'account':'12','key':'second-bank:'+number,'date':'2026-11-08T03:'+minute+':00+02:00'})
        source=(rows,{'bank-card':{'account':'12','currency':'EUR','start':'2026-11-08','opening':'1100','closing':'900'}},[])
        first=build_plan({},ledger.accounts,ledger.groups,source_data=source)
        self.assertEqual(['update','create'],[o['action'] for o in first['operations']])
        self.assertEqual({'update':1,'create':1},apply_privat(ledger,first))
        balances={a['id']:mono.money(a['attributes']['current_balance']) for a in ledger.accounts}
        self.assertEqual({'10':mono.money('100'),'12':mono.money('900'),'90':mono.money('100')},balances)
        self.assertEqual(mono.money('1100'),sum(balances.values()))
        self.assertEqual(2,len(ledger.groups))
        again=build_plan({},ledger.accounts,ledger.groups,first,source_data=source)
        self.assertEqual(['reuse','reuse'],[o['action'] for o in again['operations']])
        self.assertEqual({'reuse':2},apply_privat(ledger,again))
        self.assertEqual(2,len(ledger.groups))

    def test_fop_plan_reserves_resolved_counterpart_once_per_batch_and_repeats(self):
        ledger=self.resolved_other_bank_transfer()
        payments=[]
        for identifier,minute in [('one','00'),('two','01')]:
            item=tx('eur','outside','EUR','D','100','Переказ власних коштів',identifier)
            item['DATE_TIME_DAT_OD_TIM_P']='08.11.2026 03:'+minute+':00'
            payments.append(item)
        daily=balance('eur','EUR','1100','900','0','200');daily['dpd']='08.11.2026'
        snapshot={'start_date':'2026-11-08','end_date':'2026-11-08','transactions':payments,'balances':[daily]}
        config={'bindings':{'eur':'12'}}
        first=plan_import(snapshot,config,ledger.accounts,ledger.groups)
        self.assertEqual(['update','create'],[o['action'] for o in first['operations']])
        self.assertEqual({'update':1,'create':1},apply_privat(ledger,first))
        balances={a['id']:mono.money(a['attributes']['current_balance']) for a in ledger.accounts}
        self.assertEqual({'10':mono.money('100'),'12':mono.money('900'),'90':mono.money('100')},balances)
        self.assertEqual(mono.money('1100'),sum(balances.values()))
        again=plan_import(snapshot,config,ledger.accounts,ledger.groups,first)
        self.assertEqual(['reuse','reuse'],[o['action'] for o in again['operations']])
        self.assertEqual({'reuse':2},apply_privat(ledger,again))
        self.assertEqual(2,len(ledger.groups))

    def test_monobank_plan_reserves_resolved_counterpart_once_per_batch_and_repeats(self):
        ledger=self.resolved_other_bank_transfer()
        config={'start_date':'2026-11-08','bindings':[{'bank_id':'eur-bank','firefly_id':'12','currency_code':'EUR'}],
                'self_names':['My Name']}
        snapshot={'client_info':{'request_started_at':mono.start_timestamp(config)+43200,'data':{
            'accounts':[{'id':'eur-bank','currencyCode':978,'balance':90000,'creditLimit':0}]}},
            'statements':{mono.statement_file('eur-bank'):{'from':mono.start_timestamp(config),
                'to':mono.start_timestamp(config)+43200,'items':[
                    record('one',-10000,'2026-11-08T03:00:00',balance=100000),
                    record('two',-10000,'2026-11-08T03:01:00',balance=90000)]}}}
        baseline={'start_date':'2026-11-08','accounts':{'12':'1100.00'},'established':True,
                  'opening_dates':{'12':'2026-11-06'}}
        with tempfile.TemporaryDirectory() as tmp:
            state=Path(tmp)
            first=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,baseline)
            self.assertEqual(['update','create'],[o['action'] for o in first['operations']])
            self.assertEqual({'update':1,'create':1,'reuse':0},mono.apply_plan(ledger,first,state))
            balances={a['id']:mono.money(a['attributes']['current_balance']) for a in ledger.accounts}
            self.assertEqual({'10':mono.money('100'),'12':mono.money('900'),'90':mono.money('100')},balances)
            self.assertEqual(mono.money('1100'),sum(balances.values()))
            again=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,baseline)
            self.assertTrue(all(o['action']=='reuse' for o in again['operations']))
            self.assertEqual({'create':0,'update':0,'reuse':2},mono.apply_plan(ledger,again,state))
            self.assertEqual(2,len(ledger.groups))

    def fx_then_internal_transfer(self):
        from test_monobank_sync import timestamp
        config={'start_date':'2026-11-04','self_names':['My Name'],'bindings':[
            {'bank_id':'fop-eur','firefly_id':'10','currency_code':'EUR'},
            {'bank_id':'fop-uah','firefly_id':'11','currency_code':'UAH','ibans':['UA-FOP']},
            {'bank_id':'personal-uah','firefly_id':'12','currency_code':'UAH','ibans':['UA-PERSONAL']}]}
        rows={'fop-eur':[record('sell',-12500,'2026-11-04T12:00:00',balance=87500,counterName='',
                              operationAmount=-632000,currencyCode=980,description='Обмін валюти')],
              'fop-uah':[record('exchange',632000,'2026-11-04T12:00:00',balance=632000,counterName='',
                               operationAmount=12500,currencyCode=978,description='Обмін валюти'),
                         record('send',-632000,'2026-11-04T12:00:00',balance=0,
                                operationAmount=-632000,currencyCode=980,counterIban='UA-PERSONAL',description='Переказ на свою картку')],
              'personal-uah':[record('receive',632000,'2026-11-04T12:00:01',balance=632000,counterName='',
                                    operationAmount=632000,currencyCode=980,description='З гривневого рахунку ФОП')]}
        snapshot={'client_info':{'request_started_at':timestamp('2026-11-04T12:00:00'),'data':{'accounts':[
            {'id':'fop-eur','currencyCode':978,'balance':87500,'creditLimit':0},
            {'id':'fop-uah','currencyCode':980,'balance':0,'creditLimit':0},
            {'id':'personal-uah','currencyCode':980,'balance':632000,'creditLimit':0}]}},
            'statements':{mono.statement_file(k):{'from':timestamp('2026-11-04'),'to':timestamp('2026-11-04T12:00:00'),'items':v}
                          for k,v in rows.items()}}
        ledger=Ledger([asset('10','EUR','1000'),asset('11','UAH','0'),asset('12','UAH','0'),technical('90','UAH')])
        return config,snapshot,ledger

    def test_fx_followed_by_internal_transfer_accepts_one_sided_own_iban_and_repeats(self):
        config,snapshot,ledger=self.fx_then_internal_transfer()
        with tempfile.TemporaryDirectory() as tmp:
            state=Path(tmp);plan=mono.plan_sync(config,snapshot,ledger.accounts,[])
            self.assertEqual(2,len(plan['operations']))
            self.assertTrue(all(o['transaction']['type']=='transfer' and not review.review_of(o['transaction']) for o in plan['operations']))
            self.assertEqual({('10','11','125.00','6320.00'),('11','12','6320.00',None)},
                             {(o['transaction']['source_id'],o['transaction']['destination_id'],o['transaction']['amount'],o['transaction'].get('foreign_amount')) for o in plan['operations']})
            self.assertEqual(4,sum(len(o['aliases']) for o in plan['operations']))
            _,records=mono.bank_records(config,snapshot)
            reversed_ops=mono.make_operations(config,list(reversed(records)),{a['id']:a['attributes'] for a in ledger.accounts})
            self.assertEqual({tuple(sorted(o['aliases'])) for o in plan['operations']},{tuple(sorted(o['aliases'])) for o in reversed_ops})
            mono.apply_plan(ledger,plan,state)
            self.assertEqual(['875.00','0.00','6320.00','0'],[a['attributes']['current_balance'] for a in ledger.accounts])
            again=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            self.assertTrue(all(o['action']=='reuse' for o in again['operations']))
            self.assertEqual(2,len(ledger.groups))

    def test_contradictory_or_multiple_reciprocal_metadata_stages_without_stopping_fx(self):
        for kind in ['contradictory','multiple']:
            config,snapshot,ledger=self.fx_then_internal_transfer()
            incoming=snapshot['statements'][mono.statement_file('personal-uah')]['items']
            if kind=='contradictory':incoming[0]['counterIban']='UA-OTHER'
            else:
                incoming.append(dict(incoming[0],id='second',time=incoming[0]['time']+1,balance=2022400))
                snapshot['client_info']['data']['accounts'][2]['balance']=2022400
            with tempfile.TemporaryDirectory() as tmp:
                state=Path(tmp);plan=mono.plan_sync(config,snapshot,ledger.accounts,[])
                self.assertEqual(1,sum(bool(o['transaction'].get('foreign_amount')) for o in plan['operations']))
                pending=[o for o in plan['operations'] if review.review_of(o['transaction'])]
                self.assertEqual(2 if kind=='contradictory' else 3,len(pending))
                self.assertTrue(all(review.review_of(o['transaction'])['status']=='pending' for o in pending))
                _,records=mono.bank_records(config,snapshot)
                reverse=mono.make_operations(config,list(reversed(records)),{a['id']:a['attributes'] for a in ledger.accounts})
                self.assertEqual(len(pending),sum(bool(review.review_of(o['transaction'])) for o in reverse))
                mono.apply_plan(ledger,plan,state)
                again=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
                self.assertTrue(all(o['action']=='reuse' for o in again['operations']))

    def test_ambiguous_receipts_before_and_after_outgoing_remain_pending(self):
        config,snapshot,ledger=self.fx_then_internal_transfer()
        incoming=snapshot['statements'][mono.statement_file('personal-uah')]['items']
        incoming[0]['time']-=2
        incoming.append(dict(incoming[0],id='second',time=incoming[0]['time']+2,balance=2022400))
        snapshot['client_info']['data']['accounts'][2]['balance']=2022400
        with tempfile.TemporaryDirectory() as tmp:
            state=Path(tmp);plan=mono.plan_sync(config,snapshot,ledger.accounts,[])
            self.assertEqual(3,sum(bool(review.review_of(o['transaction'])) for o in plan['operations']))
            mono.apply_plan(ledger,plan,state)
            again=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            self.assertTrue(all(o['action']=='reuse' for o in again['operations']))

    def test_existing_pending_fx_leg_is_not_auto_merged_with_new_counterpart(self):
        config,snapshot,ledger=self.fx_then_internal_transfer()
        ledger.accounts.append(technical('91','EUR'))
        ledger.accounts[0]['attributes']['current_balance']='875.00'
        ledger.accounts[4]['attributes']['current_balance']='125.00'
        raw={'external_id':mono.external_key('fop-eur','sell'),'date':'2026-11-04T12:00:00+02:00',
             'currency_id':'1','amount':'125.00','description':'Обмін валюти','notes':'{}'}
        pending=review.apply_review(raw,'10','out','EUR',{a['id']:a['attributes'] for a in ledger.accounts},[],True)
        ledger.groups=[group(pending)]
        with tempfile.TemporaryDirectory() as tmp:
            state=Path(tmp);plan=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups)
            self.assertEqual(1,sum(o['action']=='reuse' for o in plan['operations']))
            self.assertEqual(2,sum(o['action']=='create' for o in plan['operations']))
            mono.apply_plan(ledger,plan,state)
            self.assertEqual(['875.00','0.00','6320.00','-6320.00','125.00'],
                             [a['attributes']['current_balance'] for a in ledger.accounts])
            again=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            self.assertTrue(all(o['action']=='reuse' for o in again['operations']))

    def test_delayed_internal_counterpart_keeps_existing_pending_and_imports_new_side(self):
        config,snapshot,ledger=self.fx_then_internal_transfer()
        later=copy.deepcopy(snapshot)
        snapshot['statements'][mono.statement_file('personal-uah')]['items']=[]
        snapshot['client_info']['data']['accounts'][2]['balance']=0
        with tempfile.TemporaryDirectory() as tmp:
            state=Path(tmp);first=mono.plan_sync(config,snapshot,ledger.accounts,[]);mono.apply_plan(ledger,first,state)
            self.assertEqual(2,len(ledger.groups))
            self.assertEqual('6320.00',ledger.accounts[3]['attributes']['current_balance'])
            plan=mono.plan_sync(config,later,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            self.assertEqual(1,sum(o['action']=='create' for o in plan['operations']))
            mono.apply_plan(ledger,plan,state)
            self.assertEqual(['875.00','0.00','6320.00','0.00'],[a['attributes']['current_balance'] for a in ledger.accounts])
            self.assertEqual(3,len(ledger.groups))
            again=mono.plan_sync(config,later,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            self.assertTrue(all(o['action']=='reuse' for o in again['operations']))

    def test_paired_pending_issue_does_not_block_other_valid_monobank_records(self):
        config,snapshot,ledger=self.mono()
        with tempfile.TemporaryDirectory() as tmp:
            state=Path(tmp);first=mono.plan_sync(config,snapshot,ledger.accounts,[]);mono.apply_plan(ledger,first,state)
            incoming=ledger.groups[0]['attributes']['transactions'][0]
            opposite=review.apply_review(dict(incoming,external_id='another:opposite'),'12','out','EUR',
                {a['id']:a['attributes'] for a in ledger.accounts},[],True)
            ledger.request('POST','/transactions',{'transactions':[opposite]})
            before=copy.deepcopy([a['attributes']['current_balance'] for a in ledger.accounts])
            choice(incoming,'transfer',account_id='12')
            plan=mono.plan_sync(config,snapshot,ledger.accounts,ledger.groups,mono.read_json(state/'baseline.json'))
            mono.apply_plan(ledger,plan,state)
            self.assertEqual(before,[a['attributes']['current_balance'] for a in ledger.accounts])
            self.assertEqual('paired_pending',review.review_of(ledger.groups[0]['attributes']['transactions'][0])['resolution_issue'])
            self.assertEqual('reuse',plan['operations'][1]['action'])


if __name__=='__main__':unittest.main()
