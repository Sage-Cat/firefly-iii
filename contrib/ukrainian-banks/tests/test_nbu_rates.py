import copy
import io
import json
import tempfile
import unittest
from datetime import date
from decimal import Decimal
from pathlib import Path
from unittest.mock import Mock
from urllib.parse import parse_qs, urlparse

from nbu_rates import Deferred, NbuClient, SOURCE, days, parse_rates, positive, synchronize


def row(day, code="EUR", value="50", units=1, special=None):
    return {"exchangedate": day.strftime("%d.%m.%Y"), "cc": code,
            "rate": str(Decimal(value) * units), "units": units,
            "rate_per_unit": value, "special": special}


def journal(identifier, code, day="2026-11-05", kind="withdrawal"):
    return {"id": identifier, "attributes": {"transactions": [{
        "transaction_journal_id": identifier, "currency_code": code,
        "currency_id": code, "amount": "100.00", "type": kind,
        "date": day + "T12:00:00+02:00", "source_id": "1", "destination_id": "2",
        "category_id": "pending", "tags": ["Потребує уточнення"], "budget_id": None}]}}


class Rates:
    def rates(self, code, first, last):
        base = {"EUR": Decimal("50"), "USD": Decimal("45"), "CHF": Decimal("52")}[code]
        return {day: base + i for i, day in enumerate(days(first, last))}


class Ledger:
    def __init__(self):
        self.groups = [journal("1", "EUR"), journal("2", "USD", kind="deposit"),
                       journal("3", "UAH", kind="transfer"), journal("4", "USDT", kind="opening balance")]
        self.accounts = [{"id": code, "attributes": {"currency_code": code, "current_balance": "100"}}
                         for code in ("UAH", "EUR", "USD", "CHF", "USDT")]
        self.rates = {("USDT", "EUR"): {"2026-11-05": "0.9"}}
        self.calls = []

    def all(self, path):
        if path == "/transactions?type=all":
            return copy.deepcopy(self.groups)
        if path == "/accounts?type=asset":
            return copy.deepcopy(self.accounts)
        _, _, base, target = path.split("/")
        return [{"attributes": {"date": day + "T00:00:00+02:00", "rate": rate,
                                 "from_currency_code": source, "to_currency_code": destination}}
                for source, destination in [(base, target), (target, base)]
                for day, rate in self.rates.get((source, destination), {}).items()]

    def request(self, method, path, data):
        self.calls.append((method, path, copy.deepcopy(data)))
        self.rates.setdefault(tuple(path.split("/")[-2:]), {}).update(data)


class NbuTests(unittest.TestCase):
    def test_calendar_includes_weekends_and_rejects_reversed_or_unbounded_range(self):
        self.assertEqual(["2026-11-06", "2026-11-07", "2026-11-08"], days(date(2026, 11, 6), date(2026, 11, 8)))
        for first, last in [(date(2026, 2, 1), date(2026, 1, 1)), (date(2000, 1, 1), date(2026, 1, 1))]:
            with self.assertRaises(Deferred):
                days(first, last)

    def test_units_and_establishment_date_are_used_instead_of_calculation_date(self):
        day = date(2026, 11, 7)
        item = row(day, value="1.25", units=100)
        item["calcdate"] = "06.11.2026"
        self.assertEqual({"2026-11-07": Decimal("1.25")}, parse_rates([item], "EUR", day, day))
        item["rate_per_unit"] = "125"
        with self.assertRaises(Deferred):
            parse_rates([item], "EUR", day, day)

    def test_missing_dates_wrong_currency_and_conflicts_fail(self):
        first, last = date(2026, 11, 6), date(2026, 11, 7)
        for rows in ([row(first)], [row(first), row(last, code="USD")],
                     [row(first), row(last), row(last, value="51")]):
            with self.assertRaises(Deferred):
                parse_rates(rows, "EUR", first, last)

    def test_special_condition_rate_does_not_replace_normal_rate(self):
        day = date(2026, 11, 7)
        rows = [row(day, special="Y", value="100"), row(day, special="N", value="45")]
        self.assertEqual(Decimal("45"), parse_rates(rows, "EUR", day, day)[day.isoformat()])
        with self.assertRaises(Deferred):
            parse_rates(rows[:1], "EUR", day, day)

    def test_nonfinite_and_nonpositive_rates_are_rejected(self):
        for value in ["NaN", "Infinity", "-1", "0", "not-a-rate"]:
            with self.assertRaises(Deferred):
                positive(value)

    def test_cache_keeps_history_but_refreshes_requested_current_day(self):
        first, last = date(2026, 11, 5), date(2026, 11, 7)
        urls = []
        def respond(url, timeout):
            urls.append(url)
            query = parse_qs(urlparse(url).query)
            start = date.fromisoformat(query["start"][0][:4] + "-" + query["start"][0][4:6] + "-" + query["start"][0][6:])
            records = [row(date.fromisoformat(day), value="51" if len(urls) > 1 else "50") for day in days(start, last)]
            return io.StringIO(json.dumps(records))
        with tempfile.TemporaryDirectory() as directory:
            client = NbuClient(Path(directory)); client.http = Mock()
            client.http.open.side_effect = respond
            self.assertEqual(Decimal("50"), client.rates("EUR", first, last)[last.isoformat()])
            values = client.rates("EUR", first, last)
            self.assertEqual(Decimal("50"), values[first.isoformat()])
            self.assertEqual(Decimal("51"), values[last.isoformat()])
            self.assertEqual(["20261107"], parse_qs(urlparse(urls[-1]).query)["start"])
            cache = Path(directory) / "nbu-EUR.json"
            self.assertEqual(0o600, cache.stat().st_mode & 0o777)
            saved = json.loads(cache.read_text()); saved["source"] = "https://example.invalid"
            cache.write_text(json.dumps(saved))
            with self.assertRaises(Deferred):
                client.rates("EUR", first, last)

    def test_both_targets_use_dated_cross_rates_and_repeat_is_idempotent(self):
        ledger = Ledger(); before = copy.deepcopy((ledger.groups, ledger.accounts))
        with tempfile.TemporaryDirectory() as directory:
            result = synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
            self.assertEqual(23, result["changed_rates"])
            self.assertEqual(6, result["usd_proxy_usdt_rates"])
            self.assertEqual([], result["unsupported_currencies"])
            self.assertEqual("50.000000000000", ledger.rates["EUR", "UAH"]["2026-11-05"])
            self.assertEqual("52.000000000000", ledger.rates["EUR", "UAH"]["2026-11-07"])
            self.assertEqual("0.900000000000", ledger.rates["USD", "EUR"]["2026-11-05"])
            self.assertEqual({d: Decimal(v) for d, v in ledger.rates["USD", "EUR"].items()}, {d: Decimal(v) for d, v in ledger.rates["USDT", "EUR"].items()})
            self.assertEqual("45.000000000000", ledger.rates["USDT", "UAH"]["2026-11-05"])
            self.assertEqual("47.000000000000", ledger.rates["USDT", "UAH"]["2026-11-07"])
            self.assertEqual(before, (ledger.groups, ledger.accounts))
            ledger.calls.clear()
            again = synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
            self.assertEqual(0, again["changed_rates"])
            self.assertEqual([], ledger.calls)

    def test_existing_fiat_rate_is_corrected_to_owner_selected_nbu_source(self):
        ledger = Ledger(); ledger.rates["EUR", "UAH"] = {"2026-11-05": "1"}
        with tempfile.TemporaryDirectory() as directory:
            synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
            self.assertTrue(list(Path(directory).glob("before-*.json")))
        self.assertEqual("50.000000000000", ledger.rates["EUR", "UAH"]["2026-11-05"])

    def test_native_pair_response_keeps_opposite_directions_separate(self):
        ledger = Ledger()
        ledger.rates["EUR", "UAH"] = {"2026-11-05": "50.000000000000"}
        ledger.rates["UAH", "EUR"] = {"2026-11-05": "0.020000000000"}
        self.assertEqual(2, len(ledger.all("/exchange-rates/EUR/UAH")))
        with tempfile.TemporaryDirectory() as directory:
            synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
            self.assertEqual(0, synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))["changed_rates"])
        self.assertEqual("50.000000000000", ledger.rates["EUR", "UAH"]["2026-11-05"])
        self.assertEqual("0.020000000000", ledger.rates["UAH", "EUR"]["2026-11-05"])

    def test_missing_source_rate_prevents_all_firefly_writes(self):
        ledger = Ledger(); nbu = Rates()
        nbu.rates = Mock(side_effect=Deferred("NBU unavailable"))
        with tempfile.TemporaryDirectory() as directory, self.assertRaises(Deferred):
            synchronize(ledger, nbu, Path(directory), date(2026, 11, 7))
        self.assertEqual([], ledger.calls)

    def test_usdt_uses_usd_without_a_manual_rate_or_an_earlier_manual_anchor(self):
        for old in [{}, {"2026-11-06": "0.1"}]:
            ledger = Ledger(); ledger.rates["USDT", "EUR"] = old
            with tempfile.TemporaryDirectory() as directory:
                synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
            self.assertEqual({d: Decimal(v) for d, v in ledger.rates["USD", "EUR"].items()}, {d: Decimal(v) for d, v in ledger.rates["USDT", "EUR"].items()})
            self.assertEqual(ledger.rates["USD", "UAH"], ledger.rates["USDT", "UAH"])

    def test_old_manual_values_are_replaced_by_dated_usd_proxy(self):
        ledger = Ledger(); ledger.rates["USDT", "EUR"]["2026-11-06"] = "0.8"
        with tempfile.TemporaryDirectory() as directory:
            synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
        self.assertEqual("45.000000000000", ledger.rates["USDT", "UAH"]["2026-11-05"])
        self.assertEqual("46.000000000000", ledger.rates["USDT", "UAH"]["2026-11-06"])
        self.assertEqual("47.000000000000", ledger.rates["USDT", "UAH"]["2026-11-07"])
        self.assertEqual({d: Decimal(v) for d, v in ledger.rates["USD", "EUR"].items()}, {d: Decimal(v) for d, v in ledger.rates["USDT", "EUR"].items()})

    def test_usdt_only_account_still_fetches_official_usd_rates(self):
        ledger = Ledger(); ledger.groups = [journal("usdt", "USDT", kind="opening balance")]
        ledger.accounts = [a for a in ledger.accounts if a["attributes"]["currency_code"] == "USDT"]
        with tempfile.TemporaryDirectory() as directory:
            result = synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
        self.assertEqual([], result["unsupported_currencies"])
        self.assertEqual(ledger.rates["USD", "UAH"], ledger.rates["USDT", "UAH"])

    def test_large_history_is_written_in_bounded_batches(self):
        ledger = Ledger()
        with tempfile.TemporaryDirectory() as directory:
            synchronize(ledger, Rates(), Path(directory), date(2026, 11, 25))
        self.assertTrue(ledger.calls)
        self.assertTrue(all(len(body) <= 10 for _, _, body in ledger.calls))
        self.assertEqual(21, len(ledger.rates["USDT", "UAH"]))

    def test_future_journal_is_not_valued_at_another_date(self):
        ledger = Ledger(); ledger.groups.append(journal("future", "EUR", day="2026-11-08"))
        with tempfile.TemporaryDirectory() as directory, self.assertRaises(Deferred):
            synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))
        self.assertEqual([], ledger.calls)

    def test_backend_readback_and_financial_identity_are_checked(self):
        for changed in [False, True]:
            ledger = Ledger(); original = ledger.request
            def store(method, path, data):
                if changed:
                    original(method, path, data)
                    ledger.groups[0]["attributes"]["transactions"][0]["amount"] = "999"
            ledger.request = store
            with tempfile.TemporaryDirectory() as directory, self.assertRaises(Deferred):
                synchronize(ledger, Rates(), Path(directory), date(2026, 11, 7))


if __name__ == "__main__":
    unittest.main()
