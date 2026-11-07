"""Synthetic source reconciliation without bank credentials or network."""

import copy
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

from monobank_sync import Deferred, read_json, save_json
from privat_fop_sync import collect, validate_snapshot


def source():
    balances = []
    for account, currency in (("primary", "UAH"), ("foreign", "EUR")):
        for day in (1, 2):
            values = ("0", "100", "100", "0") if day == 1 else ("100", "80", "0", "20")
            if account == "foreign":
                values = ("0", "0", "0", "0")
            balances.append(dict(zip(("balanceIn", "balanceOut", "turnoverCred", "turnoverDebt"), values),
                                 acc=account, currency=currency, dpd=f"0{day}.09.2026 00:00:00"))
    transactions = [{"ID": "receipt", "AUT_MY_ACC": "primary", "CCY": "UAH", "PR_PR": "r",
                     "TRANTYPE": "C", "SUM": "100.00", "DATE_TIME_DAT_OD_TIM_P": "01.09.2026 12:00:00"},
                    {"ID": "expense", "AUT_MY_ACC": "primary", "CCY": "UAH", "PR_PR": "r",
                     "TRANTYPE": "D", "SUM": "20.00", "DATE_TIME_DAT_OD_TIM_P": "02.09.2026 12:00:00"}]
    return {"start_date": "2026-09-01", "end_date": "2026-09-02", "balances": balances,
            "transactions": transactions}


class SourceTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name)

    def test_daily_source_reconciles_without_changing_input(self):
        data = source()
        old = copy.deepcopy(data)
        self.assertEqual(validate_snapshot(data), {"UAH": {"opening": "0", "closing": "80"},
                                                  "EUR": {"opening": "0", "closing": "0"}})
        self.assertEqual(data, old)

    def test_missing_days_duplicate_transactions_and_wrong_currency_are_rejected(self):
        for mutate in (lambda s: s["balances"].pop(),
                       lambda s: s["transactions"].append(copy.deepcopy(s["transactions"][0])),
                       lambda s: s["transactions"][0].update(CCY="EUR")):
            data = source()
            mutate(data)
            with self.assertRaises(Deferred):
                validate_snapshot(data)

    def test_balance_drift_and_turnover_disagreement_are_rejected(self):
        for key, value in (("balanceOut", "90"), ("balanceIn", "101"), ("turnoverDebt", "21")):
            data = source()
            data["balances"][1][key] = value
            with self.assertRaises(Deferred):
                validate_snapshot(data)

    def test_missing_nonfinite_and_overprecise_bank_money_cannot_become_zero(self):
        for value in (None, "", " ", True, False, "NaN", "Infinity", "-Infinity", "1.001", 0.0):
            for field in ("balanceIn", "balanceOut", "turnoverCred", "turnoverDebt"):
                data = source()
                data["balances"][2][field] = value
                with self.subTest(value=value, field=field), self.assertRaises(Deferred):
                    validate_snapshot(data)
        for value in (None, "", True, "NaN", "Infinity", "1.001", "-1"):
            data = source()
            data["transactions"][0]["SUM"] = value
            with self.subTest(transaction=value), self.assertRaises(Deferred):
                validate_snapshot(data)

    def test_pending_is_deferred_and_cancelled_rows_are_not_posted_twice(self):
        data = source()
        data["transactions"][0]["PR_PR"] = "p"
        with self.assertRaisesRegex(Deferred, "unsettled"):
            validate_snapshot(data)
        data = source()
        cancelled = dict(data["transactions"][0], ID="cancelled", PR_PR="t", SUM="999")
        data["transactions"].append(cancelled)
        self.assertEqual(validate_snapshot(data)["UAH"]["closing"], "80")

    def test_maintenance_blocks_bulk_get_and_preserves_previous_snapshot(self):
        path = self.state / "snapshot.json"
        save_json(path, {"previous": True})
        previous = path.read_bytes()
        for settings in ({"phase": "WRK", "work_balance": "Y"},
                         {"phase": "REG", "work_balance": "N"}, {"phase": "WRK"}):
            client = Mock()
            client.settings.return_value = {"settings": settings}
            with self.assertRaises(Deferred):
                collect("2026-09-01", "2026-09-02", client, self.state)
            client.balances.assert_not_called()
            client.transactions.assert_not_called()
            self.assertEqual(path.read_bytes(), previous)

    def test_successful_collect_persists_exact_rows_with_reconciled_totals(self):
        data = source()
        client = Mock()
        client.settings.return_value = {"settings": {"phase": "WRK", "work_balance": "N"}}
        client.balances.return_value = data["balances"]
        client.transactions.return_value = data["transactions"]
        result = collect("2026-09-01", "2026-09-02", client, self.state)
        self.assertEqual(result["transactions"], data["transactions"])
        self.assertEqual(result["validated_totals"]["UAH"]["closing"], "80")
        self.assertEqual(read_json(self.state / "snapshot.json"), result)

    def test_inconsistent_source_does_not_replace_previous_snapshot(self):
        save_json(self.state / "snapshot.json", {"previous": True})
        client = Mock()
        data = source()
        client.settings.return_value = {"settings": {"phase": "WRK", "work_balance": "N"}}
        client.balances.return_value = data["balances"]
        client.transactions.return_value = []
        with self.assertRaises(Deferred):
            collect("2026-09-01", "2026-09-02", client, self.state)
        self.assertEqual(read_json(self.state / "snapshot.json"), {"previous": True})


if __name__ == "__main__":
    unittest.main()
