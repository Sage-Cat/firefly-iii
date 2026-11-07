"""Maintain dated NBU fiat rates for Firefly's UAH and EUR reporting."""

import fcntl
import json
import os
import time
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path

from firefly_api import Client, NoRedirect
from monobank_sync import Deferred, KYIV, read_json, save_json

FIAT = frozenset({"UAH", "EUR", "USD", "CHF"})
TARGETS = ("UAH", "EUR")
PRECISION = Decimal("0.000000000001")
SOURCE = "https://bank.gov.ua/NBU_Exchange/exchange_site"


def days(first, last):
    if last < first or (last - first).days > 3660:
        raise Deferred("NBU date range needs review")
    return [(first + timedelta(days=i)).isoformat() for i in range((last - first).days + 1)]


def positive(value):
    try:
        result = Decimal(str(value))
    except InvalidOperation:
        raise Deferred("Invalid NBU rate") from None
    if not result.is_finite() or result <= 0:
        raise Deferred("NBU rate must be finite and positive")
    return result


def parse_rates(rows, code, first, last):
    expected = set(days(first, last))
    result = {}
    if not isinstance(rows, list):
        raise Deferred("Unexpected NBU response")
    for row in rows:
        try:
            day = datetime.strptime(row["exchangedate"], "%d.%m.%Y").date().isoformat()
            if row["cc"] != code or day not in expected:
                raise Deferred("NBU returned another currency or date")
            if row.get("special") == "Y":
                continue
            if row.get("special") not in (None, "N"):
                raise Deferred("Unknown NBU rate conditions")
            units = positive(row["units"])
            value = positive(row["rate_per_unit"])
            if abs(positive(row["rate"]) / units - value) > PRECISION:
                raise Deferred("NBU rate units disagree")
        except (KeyError, TypeError, ValueError):
            raise Deferred("Malformed NBU rate row") from None
        if day in result and result[day] != value:
            raise Deferred("Conflicting NBU rates for one date")
        result[day] = value
    if set(result) != expected:
        raise Deferred("NBU did not return every requested calendar date")
    return result


class NbuClient:
    def __init__(self, state):
        self.state = state
        self.http = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def rates(self, code, first, last):
        if code not in FIAT - {"UAH"}:
            raise Deferred("Unsupported NBU currency")
        requested = days(first, last)
        path = self.state / f"nbu-{code}.json"
        saved = read_json(path) if path.exists() else {"source": SOURCE, "code": code, "rates": {}}
        if saved.get("source") != SOURCE or saved.get("code") != code:
            raise Deferred("NBU cache provenance differs")
        cached = {day: positive(value) for day, value in saved["rates"].items()}
        missing = [day for day in requested if day not in cached]
        # Always refresh the current requested day, including corrected publications.
        start = date.fromisoformat(min(missing + [last.isoformat()]))
        query = urllib.parse.urlencode({"start": start.strftime("%Y%m%d"),
                                       "end": last.strftime("%Y%m%d"), "valcode": code.lower(),
                                       "sort": "exchangedate", "order": "asc"})
        with self.http.open(SOURCE + "?" + query + "&json", timeout=30) as response:
            rows = json.load(response, parse_float=Decimal)
        values = parse_rates(rows, code, start, last)
        cached.update(values)
        save_json(path, {"source": SOURCE, "code": code,
                         "rates": {day: str(value) for day, value in cached.items()}})
        return {day: cached[day] for day in requested}


def identities(groups, accounts):
    keys = ("type", "date", "source_id", "destination_id", "currency_id", "amount",
            "foreign_currency_id", "foreign_amount", "external_id", "description",
            "category_id", "budget_id", "bill_id", "notes", "tags")
    journals = {str(j["transaction_journal_id"]): {k: j.get(k) for k in keys}
                for g in groups for j in g["attributes"]["transactions"]}
    balances = {a["id"]: (a["attributes"]["currency_code"], a["attributes"]["current_balance"])
                for a in accounts}
    return journals, balances


def stored_rates(rows, base, target):
    # Firefly's pair endpoint includes the reverse pair as separate rows.
    result = {}
    for row in rows:
        item = row["attributes"]
        if item["from_currency_code"] != base or item["to_currency_code"] != target:
            continue
        day = item["date"][:10]
        value = positive(item["rate"])
        if day in result and result[day] != value:
            raise Deferred("Conflicting stored rates for one currency pair and date")
        result[day] = value
    return result


def synchronize(client, nbu, state, today=None):
    today = today or datetime.now(KYIV).date()
    groups = client.all("/transactions?type=all")
    accounts = client.all("/accounts?type=asset")
    before = identities(groups, accounts)
    journal_days = [date.fromisoformat(j["date"][:10])
                    for g in groups for j in g["attributes"]["transactions"]]
    if any(day > today for day in journal_days):
        raise Deferred("Future transaction rates are not yet available from NBU")
    first = min(journal_days, default=today)
    calendar = days(first, today)
    codes = {a["attributes"]["currency_code"] for a in accounts}
    for g in groups:
        for j in g["attributes"]["transactions"]:
            codes.update(code for code in (j["currency_code"], j.get("foreign_currency_code")) if code)
    supported = (codes & FIAT) | set(TARGETS)
    per_uah = {"UAH": {day: Decimal(1) for day in calendar}}
    # Validate every source response before the first write to Firefly.
    for code in sorted(supported - {"UAH"}):
        per_uah[code] = nbu.rates(code, first, today)
    plan, originals = {}, {}
    for base in sorted(supported):
        for target in TARGETS:
            if base == target:
                continue
            pair = (base, target)
            originals[base + "/" + target] = client.all(f"/exchange-rates/{base}/{target}")
            existing = stored_rates(originals[base + "/" + target], base, target)
            required = {day: (per_uah[base][day] / per_uah[target][day]).quantize(PRECISION, rounding=ROUND_HALF_UP)
                        for day in calendar}
            if any(value < Decimal("0.0000000001") for value in required.values()):
                raise Deferred("NBU cross rate is below Firefly's supported precision")
            changes = {day: str(value) for day, value in required.items() if existing.get(day) != value}
            plan[pair] = (required, changes)
    manual_derived = 0
    if "USDT" in codes:
        # NBU has no USDT quote. Preserve the owner's EUR valuation and only
        # translate that existing valuation into UAH using the dated NBU EUR rate.
        originals["USDT/EUR"] = client.all("/exchange-rates/USDT/EUR")
        manual = stored_rates(originals["USDT/EUR"], "USDT", "EUR")
        usdt_days = [date.fromisoformat(j["date"][:10])
                     for g in groups for j in g["attributes"]["transactions"]
                     if "USDT" in (j["currency_code"], j.get("foreign_currency_code"))]
        required = {}
        for day in days(min(usdt_days, default=today), today):
            eligible = [d for d in manual if d <= day]
            if not eligible:
                raise Deferred("The existing manual USDT/EUR valuation does not cover its history")
            required[day] = (manual[max(eligible)] * per_uah["EUR"][day]).quantize(PRECISION, rounding=ROUND_HALF_UP)
        originals["USDT/UAH"] = client.all("/exchange-rates/USDT/UAH")
        existing = stored_rates(originals["USDT/UAH"], "USDT", "UAH")
        changes = {day: str(value) for day, value in required.items() if existing.get(day) != value}
        if any(value < Decimal("0.0000000001") for value in required.values()):
            raise Deferred("Manual valuation is below Firefly's supported precision")
        plan["USDT", "UAH"] = (required, changes)
        manual_derived = len(required)
    count = sum(len(changes) for _, changes in plan.values())
    if count:
        save_json(state / f"before-{time.time_ns()}.json", {
            "transactions": groups, "accounts": accounts, "rates": originals,
            "changes": {"/".join(pair): changes for pair, (_, changes) in plan.items()}})
    for (base, target), (_, changes) in plan.items():
        if changes:
            client.request("POST", f"/exchange-rates/by-currencies/{base}/{target}", changes)
    for (base, target), (required, changes) in plan.items():
        actual = stored_rates(client.all(f"/exchange-rates/{base}/{target}"), base, target)
        if any(actual.get(day) != value for day, value in required.items()):
            raise Deferred("Stored Firefly rates differ from the prepared dated rates")
    if "USDT/EUR" in originals and client.all("/exchange-rates/USDT/EUR") != originals["USDT/EUR"]:
        raise Deferred("The original manual USDT/EUR valuation changed")
    if identities(client.all("/transactions?type=all"), client.all("/accounts?type=asset")) != before:
        raise Deferred("Financial data or classification changed during rate synchronization")
    result = {"finished_at": datetime.now(KYIV).isoformat(), "first": first.isoformat(),
              "last": today.isoformat(), "changed_rates": count,
              "verified_rates": sum(len(required) for required, _ in plan.values()),
              "verified_nbu_rates": sum(len(required) for required, _ in plan.values()) - manual_derived,
              "manual_derived_usdt_uah_rates": manual_derived,
              "unsupported_currencies": sorted(codes - FIAT), "source": SOURCE}
    save_json(state / "last-success.json", result)
    return result


def main():
    from monobank_api import _private_file
    root = Path(__file__).resolve().parent
    state = root / ".state/nbu-rates"
    state.mkdir(mode=0o700, exist_ok=True)
    with os.fdopen(_private_file(root / ".state/monobank/sync.lock", os.O_RDWR | os.O_CREAT), "a+") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SystemExit("A bank or budget synchronization is already running") from None
        print(json.dumps(synchronize(Client(), NbuClient(state), state), ensure_ascii=False))


if __name__ == "__main__":
    main()
