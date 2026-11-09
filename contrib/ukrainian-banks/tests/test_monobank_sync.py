"""Synthetic ledger scenarios; no credentials or network access."""

import copy
import json
import tempfile
import unittest
from datetime import datetime
from pathlib import Path
from unittest.mock import patch

import monobank_sync as sync


def timestamp(date):
    return int(datetime.fromisoformat(date).replace(tzinfo=sync.KYIV).timestamp())


def asset(identifier, code, balance, opening=None):
    return {"id": identifier, "attributes": {"currency_code": code,
            "currency_id": {"EUR": "1", "UAH": "4"}[code], "current_balance": balance,
            "opening_balance": balance if opening is None else opening,
            "opening_balance_date": "2026-11-06T00:00:00+02:00", "notes": "Original history"}}


def record(identifier, value, date="2026-11-03T10:00:00", **extra):
    return dict({"id": identifier, "amount": value, "time": timestamp(date),
                 "description": "Test bank record", "counterName": "My Name", "hold": False}, **extra)


def fixture():
    config = {"start_date": "2026-11-01", "bindings": [
        {"bank_id": "bank-eur", "firefly_id": "10", "currency_code": "EUR"},
        {"bank_id": "bank-uah", "firefly_id": "11", "currency_code": "UAH"}],
        "self_names": ["My Name"], "approved_transfers": {}}
    rows = [record("first", 210000), record("second", 1500000, "2026-11-04T14:00:00")]
    rows[0]["balance"] = 230000
    rows[1]["balance"] = 1730000
    for r in rows:
        config["approved_transfers"][sync.external_key("bank-eur", r["id"])] = "12"
    snapshot = {"client_info": {"request_started_at": timestamp("2026-11-06T12:00:00"), "data": {
        "accounts": [{"id": "bank-eur", "currencyCode": 978, "balance": 1730000, "creditLimit": 0},
                     {"id": "bank-uah", "currencyCode": 980, "balance": 18020, "creditLimit": 0}]}},
        "statements": {}}
    for b in config["bindings"]:
        snapshot["statements"][sync.statement_file(b["bank_id"])] = {
            "from": timestamp("2026-10-20"), "to": timestamp("2026-11-06T12:00:00"),
            "items": rows if b["bank_id"] == "bank-eur" else []}
    accounts = [asset("10", "EUR", "17300"), asset("11", "UAH", "180"),
                asset("12", "EUR", "60"), asset("13", "UAH", "12345")]
    return config, snapshot, accounts


class Ledger:
    def __init__(self, accounts):
        self.accounts = copy.deepcopy(accounts)
        self.groups = []
        self.calls = []

    def all(self, path):
        if path == "/accounts?type=asset":
            return copy.deepcopy(self.accounts)
        if path == "/transactions?type=all":
            return copy.deepcopy(self.groups)
        raise AssertionError(path)

    def request(self, method, path, data=None):
        self.calls.append((method, path, copy.deepcopy(data)))
        assets = {a["id"]: a["attributes"] for a in self.accounts}
        if path.startswith("/accounts/"):
            a = assets[path.split("/")[-1]]
            if method == "PUT":
                delta = sync.money(data["opening_balance"]) - sync.money(a["opening_balance"])
                a["current_balance"] = sync.amount(sync.money(a["current_balance"]) + delta)
                a.update(data)
            return {"data": {"attributes": copy.deepcopy(a)}}
        if method == "POST":
            group = {"id": str(100 + len(self.groups)), "attributes": {"transactions": [
                dict(data["transactions"][0], transaction_journal_id=str(200 + len(self.groups)))]}}
            self.groups.append(group)
            old = None
        else:
            group = next(g for g in self.groups if g["id"] == path.split("/")[-1])
            old = copy.deepcopy(group["attributes"]["transactions"][0])
            group["attributes"]["transactions"][0].update(data["transactions"][0])
        new = group["attributes"]["transactions"][0]
        for transaction, sign in ((old, -1), (new, 1)):
            if transaction:
                for account, value in sync.effects(transaction, assets).items():
                    a = assets[account]
                    a["current_balance"] = sync.amount(sync.money(a["current_balance"]) + sign * value)
        return {"data": copy.deepcopy(group)}


class SyncTests(unittest.TestCase):
    def setUp(self):
        self.config, self.snapshot, self.accounts = fixture()
        self.temporary = tempfile.TemporaryDirectory()
        self.state = Path(self.temporary.name)
        self.addCleanup(self.temporary.cleanup)

    def plan(self, ledger=None, baseline=None, references=None):
        ledger = ledger or Ledger(self.accounts)
        return sync.plan_sync(self.config, self.snapshot, ledger.accounts, ledger.groups,
                              baseline, references)

    def test_backfill_preserves_capital_and_unrelated_assets(self):
        ledger = Ledger(self.accounts)
        plan = self.plan(ledger)
        self.assertEqual(plan["baseline"]["accounts"], {"10": "200.00", "11": "180.20", "12": "17160.00"})
        self.assertEqual([o["transaction"]["type"] for o in plan["operations"]], ["transfer", "transfer"])
        counts = sync.apply_plan(ledger, plan, self.state)
        self.assertEqual(counts, {"create": 2, "update": 0, "reuse": 0})
        self.assertEqual([a["attributes"]["current_balance"] for a in ledger.accounts],
                         ["17300.00", "180.20", "60.00", "12345"])
        self.assertTrue(all("Original history" in a["attributes"]["notes"] for a in ledger.accounts))
        self.assertTrue(all(o["date"].startswith("2026-11-01") for o in plan["openings"]))

    def test_shared_peer_can_keep_earlier_reconciled_history_date(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        baseline = sync.read_json(self.state / "baseline.json")
        baseline["opening_dates"] = {"12": "2026-08-06"}
        ledger.accounts[2]["attributes"]["opening_balance_date"] = "2026-08-06T00:00:00+03:00"
        plan = self.plan(ledger, baseline)
        self.assertEqual([], plan["openings"])

    def test_repeat_is_noop_and_does_not_touch_manual_categories(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        ledger.groups[0]["attributes"]["transactions"][0].update(category_name="Manual category", notes="My notes")
        calls = len(ledger.calls)
        plan = self.plan(ledger, sync.read_json(self.state / "baseline.json"),
                         sync.read_json(self.state / "references.json"))
        self.assertFalse(plan["openings"])
        self.assertEqual(sync.apply_plan(ledger, plan, self.state), {"create": 0, "update": 0, "reuse": 2})
        self.assertFalse(any(m != "GET" for m, _, _ in ledger.calls[calls:]))
        self.assertEqual(ledger.groups[0]["attributes"]["transactions"][0]["category_name"], "Manual category")

    def test_crash_after_creation_before_local_reference_write_is_idempotent(self):
        ledger = Ledger(self.accounts)
        real_save = sync.save_json
        def fail_refs(path, value):
            if path.name == "references.json":
                raise OSError("Synthetic interruption")
            real_save(path, value)
        with patch.object(sync, "save_json", fail_refs), self.assertRaises(OSError):
            sync.apply_plan(ledger, self.plan(ledger), self.state)
        self.assertEqual(len(ledger.groups), 1)
        plan = self.plan(ledger, sync.read_json(self.state / "baseline.json"))
        self.assertEqual(sync.apply_plan(ledger, plan, self.state), {"create": 1, "update": 0, "reuse": 1})
        self.assertEqual(len(ledger.groups), 2)

    def test_bank_correction_updates_amount_and_preserves_manual_fields(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        journal = ledger.groups[0]["attributes"]["transactions"][0]
        journal.update(category_name="Chosen", notes="Do not replace", tags=["Custom"])
        rows = self.snapshot["statements"][sync.statement_file("bank-eur")]["items"]
        rows[0]["amount"] += 100
        rows[-1]["balance"] += 100
        self.snapshot["client_info"]["data"]["accounts"][0]["balance"] += 100
        plan = self.plan(ledger, sync.read_json(self.state / "baseline.json"))
        self.assertEqual(plan["operations"][0]["action"], "update")
        sync.apply_plan(ledger, plan, self.state)
        self.assertEqual(journal["amount"], "2101.00")
        self.assertEqual((journal["category_name"], journal["notes"], journal["tags"]),
                         ("Chosen", "Do not replace", ["Custom"]))

    def test_existing_manual_transfer_reused_without_duplicate_or_external_id_replacement(self):
        ledger = Ledger(self.accounts)
        initial = self.plan(ledger)
        sync.apply_plan(ledger, initial, self.state)
        for group in ledger.groups:
            group["attributes"]["transactions"][0]["external_id"] = "Other provider"
        plan = self.plan(ledger, sync.read_json(self.state / "baseline.json"))
        self.assertEqual([o["action"] for o in plan["operations"]], ["reuse", "reuse"])
        sync.apply_plan(ledger, plan, self.state)
        self.assertEqual(len(ledger.groups), 2)
        self.assertTrue(all(g["attributes"]["transactions"][0]["external_id"] == "Other provider" for g in ledger.groups))

    def test_unexplained_drift_is_rejected_without_rebasing(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        self.snapshot["client_info"]["data"]["accounts"][0]["balance"] += 100
        self.snapshot["statements"][sync.statement_file("bank-eur")]["items"][-1]["balance"] += 100
        with self.assertRaisesRegex(sync.Deferred, "predicted"):
            self.plan(ledger, sync.read_json(self.state / "baseline.json"))

    def test_manual_opening_change_is_not_overwritten_after_initialization(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        ledger.accounts[0]["attributes"]["opening_balance"] = "201"
        with self.assertRaisesRegex(sync.Deferred, "opening balance was changed"):
            self.plan(ledger, sync.read_json(self.state / "baseline.json"))

    def test_opted_in_refunds_run_after_bank_balance_verification(self):
        self.config["auto_refunds"] = True
        ledger = Ledger(self.accounts)
        sync.save_json(self.state / "config.json", self.config)
        sync.save_json(self.state / "snapshot.json", self.snapshot)
        def reconcile(client, state):
            self.assertIs(client, ledger)
            self.assertEqual("17300.00", ledger.accounts[0]["attributes"]["current_balance"])
            self.assertEqual(2, len(ledger.groups))
            return {"classified": 0, "links_created": 0}
        with patch.object(sync, "FireflyClient", return_value=ledger), patch.object(sync, "ensure_rates"), \
             patch("bank_refunds.synchronize", side_effect=reconcile) as hook:
            result = sync.run("sync", True, self.state)
        self.assertEqual(1, hook.call_count)
        self.assertEqual({"classified": 0, "links_created": 0}, result["refunds"])
        self.assertTrue(sync.read_json(self.state / "active-state.json")["config"]["auto_refunds"])

    def test_opted_in_classification_runs_after_verified_import_and_refunds(self):
        self.config.update(auto_refunds=True, auto_classification=True, auto_category_fallback=True)
        ledger = Ledger(self.accounts)
        sync.save_json(self.state / "config.json", self.config)
        sync.save_json(self.state / "snapshot.json", self.snapshot)
        order = []
        def refunds(client, state):
            order.append("refunds")
            return {"classified": 0}
        def classify(client, state):
            self.assertIs(client, ledger)
            self.assertEqual(self.state.parent / "bank-classification", state)
            self.assertEqual("17300.00", ledger.accounts[0]["attributes"]["current_balance"])
            self.assertEqual(2, len(ledger.groups))
            self.assertEqual(["refunds"], order)
            order.append("classification")
            return {"classified": 2}
        def defaults(client, state):
            self.assertIs(client, ledger)
            self.assertEqual(self.state.parent / "category-fallback", state)
            self.assertEqual(["refunds", "classification"], order)
            return {"defaulted": 2}
        with patch.object(sync, "FireflyClient", return_value=ledger), patch.object(sync, "ensure_rates"), \
             patch("bank_refunds.synchronize", side_effect=refunds), \
             patch("bank_classification.synchronize", side_effect=classify) as hook, \
             patch("category_fallback.synchronize", side_effect=defaults) as fallback_hook:
            result = sync.run("sync", True, self.state)
        self.assertEqual(1, hook.call_count)
        self.assertEqual({"classified": 2}, result["classification"])
        self.assertEqual({"defaulted": 2}, result["category_fallback"])
        fallback_hook.assert_called_once()

    def test_classification_is_opt_in_and_never_runs_for_an_import_plan(self):
        ledger = Ledger(self.accounts)
        sync.save_json(self.state / "config.json", self.config)
        sync.save_json(self.state / "snapshot.json", self.snapshot)
        with patch.object(sync, "FireflyClient", return_value=ledger), patch.object(sync, "ensure_rates"), \
             patch("bank_classification.synchronize") as hook, patch("category_fallback.synchronize") as fallback_hook:
            self.assertNotIn("classification", sync.run("sync", True, self.state))
            self.config.update(auto_classification=True, auto_category_fallback=True)
            sync.save_json(self.state / "active-state.json", {
                "config": self.config, "baseline": sync.read_json(self.state / "baseline.json")})
            sync.run("plan", True, self.state)
        hook.assert_not_called()
        fallback_hook.assert_not_called()

    def test_unknown_own_transfer_becomes_uncategorized_review(self):
        import json
        self.config["approved_transfers"] = {}
        plan = self.plan()
        self.assertTrue(all(o["transaction"]["type"] == "transfer" for o in plan["operations"]))
        self.assertTrue(all(json.loads(o["transaction"]["notes"])["bank_review"]["status"] == "pending" for o in plan["operations"]))
        self.config["self_names"] = []
        rows = self.snapshot["statements"][sync.statement_file("bank-eur")]["items"]
        rows[0].update(counterName="", description="Переказ між власними рахунками")
        self.assertEqual("bank-review:EUR", self.plan()["operations"][0]["transaction"]["source_id"])

    def test_account_bindings_are_one_to_one(self):
        original = copy.deepcopy(self.config)
        for field in ("bank_id", "firefly_id"):
            self.config = copy.deepcopy(original)
            self.config["bindings"][1][field] = self.config["bindings"][0][field]
            with self.assertRaisesRegex(sync.Deferred, "one-to-one"):
                self.plan()

    def test_statement_and_account_snapshot_race_is_deferred(self):
        self.snapshot["client_info"]["data"]["accounts"][0]["balance"] += 1
        with self.assertRaisesRegex(sync.Deferred, "closing balance"):
            self.plan()

    def test_pending_records_and_credit_limits_are_deferred(self):
        rows = self.snapshot["statements"][sync.statement_file("bank-eur")]["items"]
        rows[0]["hold"] = True
        with self.assertRaisesRegex(sync.Deferred, "Pending"):
            self.plan()
        rows[0]["hold"] = False
        self.snapshot["client_info"]["data"]["accounts"][0]["creditLimit"] = 10000
        with self.assertRaisesRegex(sync.Deferred, "Credit"):
            self.plan()

    def test_expenses_and_income_have_correct_direction_and_do_not_double_count_fees(self):
        rows = self.snapshot["statements"][sync.statement_file("bank-uah")]["items"]
        rows.extend([record("expense", -1025, counterName="Shop", commissionRate=25),
                     record("income", 2000, counterName="Client")])
        for row in rows:
            row["balance"] = 18020
        plan = self.plan()
        ordinary = [o["transaction"] for o in plan["operations"] if o["transaction"]["type"] != "transfer"]
        self.assertEqual({(t["type"], t["amount"]) for t in ordinary}, {("withdrawal", "10.25"), ("deposit", "20.00")})
        self.assertTrue(all(t.get("source_id") == "11" if t["type"] == "withdrawal" else
                            t.get("destination_id") == "11" for t in ordinary))

    def test_zero_opening_is_cleared_using_null_date_and_empty_amount(self):
        self.snapshot["client_info"]["data"]["accounts"][1]["balance"] = 0
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        payload = next(d for m, p, d in ledger.calls if m == "PUT" and p == "/accounts/11")
        self.assertEqual(payload["opening_balance"], "")
        self.assertIsNone(payload["opening_balance_date"])

    def test_duplicate_external_id_or_manually_split_group_is_rejected(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        ledger.groups.append(copy.deepcopy(ledger.groups[0]))
        with self.assertRaisesRegex(sync.Deferred, "Duplicate external"):
            self.plan(ledger)
        ledger.groups.pop()
        ledger.groups[0]["attributes"]["transactions"].append(copy.deepcopy(ledger.groups[0]["attributes"]["transactions"][0]))
        with self.assertRaisesRegex(sync.Deferred, "manually split"):
            self.plan(ledger)

    def test_two_bank_legs_form_one_transfer(self):
        b = self.config["bindings"][1]
        b.update(currency_code="EUR", ibans=["UA-OWN"])
        self.accounts[1] = asset("11", "EUR", "0")
        self.config["bindings"][0]["ibans"] = ["UA-SOURCE"]
        self.snapshot["client_info"]["data"]["accounts"][1].update(currencyCode=978, balance=10000)
        self.snapshot["client_info"]["data"]["accounts"][0]["balance"] = 10000
        self.snapshot["statements"][sync.statement_file("bank-eur")]["items"] = [
            record("outgoing", -10000, counterIban="UA-OWN", balance=10000)]
        self.snapshot["statements"][sync.statement_file("bank-uah")]["items"] = [
            record("incoming", 10000, counterIban="UA-SOURCE", balance=10000)]
        plan = self.plan()
        self.assertEqual(len(plan["operations"]), 1)
        op = plan["operations"][0]
        self.assertEqual((op["transaction"]["source_id"], op["transaction"]["destination_id"]), ("10", "11"))
        self.assertEqual(len(op["aliases"]), 2)
        self.snapshot["statements"][sync.statement_file("bank-uah")]["items"][0]["counterIban"] = "UA-THIRD-PARTY"
        unresolved = self.plan()
        self.assertEqual(2, len(unresolved["operations"]))
        self.assertTrue(all("bank_review" in json.loads(o["transaction"]["notes"]) for o in unresolved["operations"]))
        self.snapshot["statements"][sync.statement_file("bank-uah")]["items"][0].pop("counterIban")
        self.assertEqual(1, len(self.plan()["operations"]))

    def test_integer_minor_units_are_required(self):
        with self.assertRaises(sync.Deferred):
            sync.minor(10.01)
        self.assertEqual(sync.minor(18020), sync.Decimal("180.20"))

    def test_provider_namespace_is_preserved_in_ids_and_tags(self):
        self.config["provider"] = "privat-fop"
        self.config["approved_transfers"] = {
            key.replace("monobank:", "privat-fop:", 1): value
            for key, value in self.config["approved_transfers"].items()}
        plan = self.plan()
        self.assertTrue(all(o["key"].startswith("privat-fop:") and
                            o["transaction"]["tags"] == ["privat-fop"] and
                            o["transaction"]["internal_reference"] == "privat-fop"
                            for o in plan["operations"]))

    def test_collect_persists_coherent_snapshot_and_reuses_previous_bundle(self):
        # The previous bundle, rather than stale original individual files, is authoritative.
        old = copy.deepcopy(self.snapshot)
        old["statements"][sync.statement_file("bank-eur")]["to"] = timestamp("2026-11-05")
        sync.save_json(self.state / "snapshot.json", old)
        class Bank:
            def __init__(self): self.windows = []
            def client_info(bank): return self.snapshot["client_info"]["data"]
            def statement(bank, account, start, end):
                bank.windows.append((account, start, end))
                return [i for i in old["statements"][sync.statement_file(account)]["items"]
                        if start <= i["time"] <= end]
        bank = Bank()
        with patch.object(sync.time, "time", return_value=timestamp("2026-11-15")):
            result = sync.collect(bank, self.config, self.state)
        self.assertEqual(bank.windows[0][1], timestamp("2026-11-01"))
        self.assertEqual(len(result["statements"][sync.statement_file("bank-eur")]["items"]), 2)
        self.assertEqual(result["statements"][sync.statement_file("bank-eur")]["from"],
                         old["statements"][sync.statement_file("bank-eur")]["from"])
        self.assertEqual(sync.cached_snapshot(self.config, self.state), result)

    def test_truncated_fetch_does_not_replace_coherent_snapshot(self):
        sync.save_json(self.state / "snapshot.json", self.snapshot)
        bank = unittest.mock.Mock()
        bank.client_info.return_value = self.snapshot["client_info"]["data"]
        bank.statement.return_value = [{}] * 500
        with self.assertRaisesRegex(sync.Deferred, "truncated"):
            sync.collect(bank, self.config, self.state)
        self.assertEqual(sync.read_json(self.state / "snapshot.json"), self.snapshot)

    def test_first_payment_during_client_info_is_not_absorbed_into_opening(self):
        self.config["bindings"] = self.config["bindings"][:1]
        clock = [timestamp("2026-11-06T12:00:00")]
        payment = record("new", 10000, counterName="Customer", balance=10000)
        payment["time"] = clock[0] + 4
        class Bank:
            def client_info(bank):
                clock[0] += 5
                return {"accounts": [{"id": "bank-eur", "currencyCode": 978, "balance": 10000}]}
            def statement(bank, account, start, end):
                return [payment] if start <= payment["time"] <= end else []
        with patch.object(sync.time, "time", side_effect=lambda: clock[0]):
            snapshot = sync.collect(Bank(), self.config, self.state)
        plan = sync.plan_sync(self.config, snapshot, [asset("10", "EUR", "0")], [])
        self.assertEqual(plan["baseline"]["accounts"]["10"], "0.00")
        self.assertEqual(plan["operations"][0]["transaction"]["amount"], "100.00")

    def test_existing_inverse_rate_is_preserved(self):
        ledger = unittest.mock.Mock()
        ledger.all.return_value = [{"attributes": {"date": "2026-11-01T00:00:00+02:00",
                                   "from_currency_code": "EUR", "to_currency_code": "UAH", "rate": "50"}}]
        plan = self.plan()
        sync.ensure_rates(ledger, plan, {a["id"]: a["attributes"] for a in self.accounts}, self.state)
        ledger.request.assert_not_called()

    def test_missing_historical_rate_uses_exact_nbu_date(self):
        ledger = unittest.mock.Mock()
        ledger.all.return_value = []
        sync.save_json(self.state / "nbu-2026-11-01.json", [
            {"cc": "EUR", "rate": 50, "exchangedate": "01.11.2026"}])
        sync.ensure_rates(ledger, self.plan(), {a["id"]: a["attributes"] for a in self.accounts}, self.state)
        ledger.request.assert_called_once_with("POST", "/exchange-rates", {
            "date": "2026-11-01", "from": "UAH", "to": "EUR", "rate": "0.02"})

    def exchange_fixture(self):
        self.config["approved_transfers"] = {}
        self.snapshot["client_info"]["data"]["accounts"][0]["balance"] = 5500
        self.accounts[0] = asset("10", "EUR", "55")
        euro, hryvnia = [], []
        for identifier, value, cost, date, eur_balance, uah_balance in (
                ("one", 5000, 256345, "2026-11-03T17:05:27", 5000, 43655),
                ("two", 500, 25635, "2026-11-03T17:05:45", 5500, 18020)):
            euro.append(record(identifier + "-in", value, date, operationAmount=cost,
                               currencyCode=980, balance=eur_balance, counterName="", description="З Чорної картки"))
            hryvnia.append(record(identifier + "-out", -cost, date, operationAmount=-value,
                                  currencyCode=978, balance=uah_balance, counterName="", description="Переказ на картку"))
        self.snapshot["statements"][sync.statement_file("bank-eur")]["items"] = euro
        self.snapshot["statements"][sync.statement_file("bank-uah")]["items"] = hryvnia

    def test_exchange_records_become_two_transfers_with_exact_dual_amounts(self):
        self.exchange_fixture()
        ledger = Ledger(self.accounts)
        plan = self.plan(ledger)
        transfers = [o["transaction"] for o in plan["operations"]]
        self.assertEqual(len(transfers), 2)
        self.assertEqual({(t["amount"], t["foreign_amount"]) for t in transfers},
                         {("2563.45", "50.00"), ("256.35", "5.00")})
        self.assertTrue(all(t["type"] == "transfer" and t["source_id"] == "11" and
                            t["destination_id"] == "10" and t["currency_id"] == "4" and
                            t["foreign_currency_id"] == "1" for t in transfers))
        _, records = sync.bank_records(self.config, self.snapshot)
        assets = {a["id"]: a["attributes"] for a in self.accounts}
        reversed_ops = sync.make_operations(self.config, list(reversed(records)), assets)
        self.assertEqual({(o["key"], o["transaction"]["amount"]) for o in reversed_ops},
                         {(o["key"], o["transaction"]["amount"]) for o in plan["operations"]})
        sync.apply_plan(ledger, plan, self.state)
        self.assertEqual(ledger.accounts[0]["attributes"]["current_balance"], "55.00")
        self.assertEqual(ledger.accounts[1]["attributes"]["current_balance"], "180.20")
        again = self.plan(ledger, sync.read_json(self.state / "baseline.json"))
        self.assertEqual([o["action"] for o in again["operations"]], ["reuse", "reuse"])

    def test_exchange_rejects_contradictory_counterparty(self):
        self.exchange_fixture()
        row = self.snapshot["statements"][sync.statement_file("bank-eur")]["items"][0]
        row["counterIban"] = "UA-THIRD-PARTY"
        with self.assertRaisesRegex(sync.Deferred, "exchange"):
            self.plan()
        row.pop("counterIban")
        row["counterName"] = "Other Person"
        with self.assertRaisesRegex(sync.Deferred, "exchange"):
            self.plan()

    def test_explicit_exchange_pairs_resolve_identical_trade_legs(self):
        self.exchange_fixture()
        euro = self.snapshot["statements"][sync.statement_file("bank-eur")]["items"]
        hryvnia = self.snapshot["statements"][sync.statement_file("bank-uah")]["items"]
        euro[1].update(amount=euro[0]["amount"], operationAmount=euro[0]["operationAmount"],
                       time=euro[0]["time"], balance=10000)
        hryvnia[1].update(amount=hryvnia[0]["amount"], operationAmount=hryvnia[0]["operationAmount"],
                          time=hryvnia[0]["time"])
        self.snapshot["client_info"]["data"]["accounts"][0]["balance"] = 10000
        self.accounts[0] = asset("10", "EUR", "100")
        with self.assertRaisesRegex(sync.Deferred, "uniquely"):
            self.plan()
        pairs = {}
        for incoming, outgoing in zip(euro, hryvnia):
            left, right = sync.external_key("bank-eur", incoming["id"]), sync.external_key("bank-uah", outgoing["id"])
            pairs[left], pairs[right] = right, left
        self.config["approved_exchange_pairs"] = pairs
        plan = self.plan()
        self.assertEqual(len(plan["operations"]), 2)
        self.assertTrue(all(o["transaction"]["foreign_amount"] == "50.00" for o in plan["operations"]))
        pairs.pop(next(iter(pairs)))
        with self.assertRaises(sync.Deferred):
            self.plan()

    def test_primary_eur_exchange_still_gets_foreign_uah_historical_rate(self):
        ledger = unittest.mock.Mock()
        ledger.all.return_value = []
        sync.save_json(self.state / "nbu-2026-11-01.json", [
            {"cc": "EUR", "rate": 50, "exchangedate": "01.11.2026"}])
        plan = {"openings": [], "operations": [{"action": "create", "transaction": {
            "currency_id": "1", "foreign_currency_id": "4", "date": "2026-11-01"}}]}
        sync.ensure_rates(ledger, plan, {a["id"]: a["attributes"] for a in self.accounts}, self.state)
        ledger.request.assert_called_once_with("POST", "/exchange-rates", {
            "date": "2026-11-01", "from": "UAH", "to": "EUR", "rate": "0.02"})

    def test_later_exchange_leg_cannot_hide_one_sided_or_conflicting_mapping(self):
        self.exchange_fixture()
        _, records = sync.bank_records(self.config, self.snapshot)
        first = records[0]
        later = next(r for r in records if r["key"] != first["key"] and
                     r["item"]["time"] == first["item"]["time"])
        for pairs in ({later["key"]: first["key"]},
                      {later["key"]: "not-recorded-counterpart", "not-recorded-counterpart": later["key"]}):
            self.config["approved_exchange_pairs"] = pairs
            with self.assertRaises(sync.Deferred):
                self.plan()
        self.config["approved_exchange_pairs"] = {first["key"]: later["key"], later["key"]: first["key"]}
        self.assertEqual(len(self.plan()["operations"]), 2)

    def test_usd_historical_conversion_uses_two_nbu_rates(self):
        ledger = unittest.mock.Mock()
        ledger.all.return_value = []
        for code, rate, filename in (("EUR", 50, "nbu-2026-11-01.json"),
                                     ("USD", 45, "nbu-USD-2026-11-01.json")):
            sync.save_json(self.state / filename, [{"cc": code, "rate": rate, "exchangedate": "01.11.2026"}])
        plan = {"openings": [{"account_id": "usd", "date": "2026-11-01", "value": "100"}], "operations": []}
        sync.ensure_rates(ledger, plan, {"usd": {"currency_id": "9", "currency_code": "USD"}}, self.state)
        ledger.request.assert_called_once_with("POST", "/exchange-rates", {
            "date": "2026-11-01", "from": "USD", "to": "EUR", "rate": "0.9"})

    def test_reversed_self_name_is_not_new_income(self):
        self.config["approved_transfers"] = {}
        self.snapshot["statements"][sync.statement_file("bank-eur")]["items"][0]["counterName"] = "Name My"
        self.assertEqual("transfer", self.plan()["operations"][0]["transaction"]["type"])

    def test_older_history_fetch_preserves_newer_revisions_and_asof(self):
        original = copy.deepcopy(self.snapshot)
        for b in self.config["bindings"]:
            name = sync.statement_file(b["bank_id"])
            legacy = copy.deepcopy(original["statements"][name])
            legacy["from"] = timestamp("2026-10-06")
            sync.save_json(self.state / name, legacy)
        bank = unittest.mock.Mock()
        bank.statement.return_value = []
        self.config["start_date"] = "2026-09-01"
        result = sync.extend_snapshot(bank, self.config, original, self.state)
        self.assertEqual(bank.statement.call_count, 4)
        bank.client_info.assert_not_called()
        self.assertEqual(result["client_info"], original["client_info"])
        for name, envelope in result["statements"].items():
            self.assertEqual(envelope["from"], timestamp("2026-09-01"))
            self.assertEqual(envelope["items"], original["statements"][name]["items"])

    def test_backfill_atomically_checkpoints_date_and_baseline_and_resumes_after_crash(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        original_baseline = sync.read_json(self.state / "baseline.json")
        old_ids = {g["id"] for g in ledger.groups}
        older = record("older-topup", 20000, "2026-10-30T17:00:00", balance=20000)
        self.config["approved_transfers"][sync.external_key("bank-eur", older["id"])] = "12"
        self.snapshot["statements"][sync.statement_file("bank-eur")]["items"].append(older)
        sync.save_json(self.state / "config.json", self.config)
        for envelope in self.snapshot["statements"].values():
            envelope["from"] = timestamp("2026-09-01")
        sync.save_json(self.state / "snapshot.json", self.snapshot)
        actual = ledger.request
        fail_once = [True]
        def interrupted(method, path, data=None):
            response = actual(method, path, data)
            if method == "PUT" and path.startswith("/accounts/") and fail_once[0]:
                fail_once[0] = False
                raise OSError("Synthetic crash after first opening update")
            return response
        with patch.object(sync, "FireflyClient", return_value=ledger), \
             patch.object(sync, "ensure_rates"), patch.object(ledger, "request", side_effect=interrupted):
            with self.assertRaises(OSError):
                sync.run("backfill", True, self.state, "2026-09-01")
        config, pending = sync.load_active_state(self.state)
        self.assertEqual(config["start_date"], "2026-09-01")
        self.assertFalse(pending.get("established"))
        self.assertEqual(sync.read_json(self.state / "config.json")["start_date"], "2026-11-01")
        self.assertEqual(sync.read_json(self.state / "baseline.json"), original_baseline)
        with patch.object(sync, "FireflyClient", return_value=ledger), patch.object(sync, "ensure_rates"):
            result = sync.run("sync", True, self.state)
        self.assertEqual(result["counts"], {"create": 1, "update": 0, "reuse": 2})
        config, completed = sync.load_active_state(self.state)
        self.assertTrue(completed["established"])
        self.assertEqual(len(ledger.groups), 3)
        self.assertTrue(old_ids.issubset({g["id"] for g in ledger.groups}))
        self.assertEqual(ledger.accounts[0]["attributes"]["current_balance"], "17300.00")
        self.assertEqual(ledger.accounts[2]["attributes"]["current_balance"], "60.00")
        self.assertTrue(all(a["attributes"]["opening_balance_date"].startswith("2026-09-01")
                            for a in ledger.accounts if a["id"] != "13" and
                            sync.money(a["attributes"]["opening_balance"])))

    def test_backfill_does_not_absorb_manual_changes_to_old_frozen_opening(self):
        ledger = Ledger(self.accounts)
        sync.apply_plan(ledger, self.plan(ledger), self.state)
        ledger.accounts[0]["attributes"].update(opening_balance="201.00", current_balance="17301.00")
        sync.save_json(self.state / "config.json", self.config)
        for envelope in self.snapshot["statements"].values():
            envelope["from"] = timestamp("2026-09-01")
        sync.save_json(self.state / "snapshot.json", self.snapshot)
        with patch.object(sync, "FireflyClient", return_value=ledger), self.assertRaisesRegex(
                sync.Deferred, "opening balance was changed"):
            sync.run("backfill-plan", True, self.state, "2026-09-01")
        self.assertFalse((self.state / "active-state.json").exists())

    def test_backfill_cannot_shrink_history_and_normal_sync_cannot_change_date(self):
        sync.save_json(self.state / "config.json", self.config)
        for command, date in (("backfill", "2026-11-02"), ("sync", "2026-09-01")):
            with self.assertRaises(sync.Deferred):
                sync.run(command, True, self.state, date)


if __name__ == "__main__":
    unittest.main()
