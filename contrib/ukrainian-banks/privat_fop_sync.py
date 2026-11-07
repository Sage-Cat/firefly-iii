"""Collect and reconcile PrivatBank FOP statements with the local ledger."""

import argparse
from collections import Counter, defaultdict
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path

from monobank_sync import KYIV, Deferred, save_json

STATE = Path(__file__).resolve().parent / ".state/privat-fop"


def bank_money(value, nonnegative=False):
    if (value is None or isinstance(value, bool) or not isinstance(value, (str, int, Decimal))
            or isinstance(value, str) and not value.strip()):
        raise Deferred("Privat FOP monetary value is missing or invalid")
    try:
        number = Decimal(str(value))
        valid = number.is_finite() and number == number.quantize(Decimal("0.01"))
    except (InvalidOperation, ValueError):
        valid = False
    if not valid or nonnegative and number < 0:
        raise Deferred("Privat FOP monetary value must be finite with at most two decimal places")
    return number


def validate_snapshot(snapshot):
    """Reconcile each account's daily movements, carry-forwards and coverage."""
    first = datetime.fromisoformat(snapshot["start_date"]).date()
    last = datetime.fromisoformat(snapshot["end_date"]).date()
    balances, account_currencies = {}, {}
    for row in snapshot["balances"]:
        account = row["acc"]
        if not isinstance(account, str) or not account:
            raise Deferred("Privat FOP account identifier is missing")
        day = datetime.strptime(row["dpd"][:10], "%d.%m.%Y").date()
        if not first <= day <= last or (account, day) in balances:
            raise Deferred("Privat FOP duplicate balance or date outside the selected period")
        currency = row["currency"]
        if (not isinstance(currency, str) or len(currency) != 3 or not currency.isascii()
                or not currency.isalpha() or not currency.isupper()):
            raise Deferred("Privat FOP currency identifier is invalid")
        for field in ("balanceIn", "balanceOut", "turnoverCred", "turnoverDebt"):
            bank_money(row.get(field), field.startswith("turnover"))
        if account in account_currencies and account_currencies[account] != currency:
            raise Deferred("Privat FOP account currency changed")
        account_currencies[account] = currency
        balances[account, day] = row
    flows = defaultdict(lambda: {"C": Decimal(0), "D": Decimal(0)})
    seen = set()
    for row in snapshot["transactions"]:
        if row["PR_PR"] in {"t", "n"}:
            continue
        if row["PR_PR"] != "r":
            raise Deferred("Privat FOP has an unsettled transaction")
        account = row["AUT_MY_ACC"]
        day = datetime.strptime(row["DATE_TIME_DAT_OD_TIM_P"][:10], "%d.%m.%Y").date()
        identity = (account, str(row["ID"]))
        if identity in seen or (account, day) not in balances:
            raise Deferred("Privat FOP duplicate transaction or missing daily balance")
        seen.add(identity)
        if row["CCY"] != account_currencies[account] or row["TRANTYPE"] not in {"C", "D"}:
            raise Deferred("Privat FOP currency or transaction direction is inconsistent")
        value = bank_money(row.get("SUM"), True)
        flows[account, day][row["TRANTYPE"]] += value
    totals = defaultdict(lambda: {"opening": Decimal(0), "closing": Decimal(0)})
    for account, currency in account_currencies.items():
        days = sorted(day for acc, day in balances if acc == account)
        if not days or days[0] != first or days[-1] != last or len(days) != (last - first).days + 1:
            raise Deferred("Privat FOP daily balance history is incomplete")
        previous = None
        for day in days:
            row, flow = balances[account, day], flows[account, day]
            opening, closing = bank_money(row["balanceIn"]), bank_money(row["balanceOut"])
            if (previous is not None and opening != previous) or (
                    opening + flow["C"] - flow["D"] != closing):
                raise Deferred("Privat FOP daily balance does not reconcile with posted movements")
            if flow["C"] != bank_money(row["turnoverCred"], True) or flow["D"] != bank_money(row["turnoverDebt"], True):
                raise Deferred("Privat FOP daily turnover differs from statement transactions")
            previous = closing
        totals[currency]["opening"] += bank_money(balances[account, days[0]]["balanceIn"])
        totals[currency]["closing"] += previous
    if not totals:
        raise Deferred("Privat FOP returned no account balances")
    return {currency: {key: str(value) for key, value in values.items()}
            for currency, values in totals.items()}


def collect(start_date, end_date=None, client=None, state_dir=STATE):
    if client is None:
        from privat_fop_api import Client
        client = Client()
    end_date = end_date or datetime.now(KYIV).date().isoformat()
    requested = datetime.now(KYIV).isoformat()
    settings = client.settings()
    controls = settings["settings"]
    if controls.get("work_balance") != "N" or controls.get("phase") != "WRK":
        raise Deferred("Privat FOP bank maintenance; collect statements after the bank is ready")
    balances = client.balances(start_date, end_date)
    transactions = client.transactions(start_date, end_date)
    snapshot = {"start_date": start_date, "end_date": end_date, "requested_at": requested,
                "finished_at": datetime.now(KYIV).isoformat(), "settings": settings,
                "balances": balances, "transactions": transactions}
    snapshot["validated_totals"] = validate_snapshot(snapshot)
    save_json(state_dir / "snapshot.json", snapshot)
    return snapshot


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("collect",))
    parser.add_argument("--from-date", required=True)
    args = parser.parse_args()
    snapshot = collect(args.from_date)
    print({"balances": len(snapshot["balances"]), "transactions": len(snapshot["transactions"]),
           "currency_counts": dict(Counter(r.get("CCY", "unknown") for r in snapshot["transactions"]))})


if __name__ == "__main__":
    main()
