"""Assign expense budgets and catch up period limits without financial postings."""

import argparse
import calendar
import fcntl
import json
import os
import time
from collections import defaultdict
from datetime import date, datetime
from decimal import Decimal
from pathlib import Path

from monobank_sync import Deferred, KYIV, amount, money, read_json, save_json


def bucket_for(journal, config):
    if journal["type"] != "withdrawal" or journal.get("category_name") in config.get("excluded_categories", []):
        return None
    if journal.get("category_name") == "Потребує уточнення":
        return "pending"
    override = config.get("bucket_overrides", {}).get(str(journal.get("transaction_journal_id", "")))
    if override:
        return override
    if str(journal["source_id"]) in config["business_accounts"]:
        return "business_tax" if journal.get("category_name") == "Податки ФОП" else "business"
    if any(s.casefold() in journal["description"].casefold() for s in config["subscription_patterns"]):
        return "subscriptions"
    return config["category_buckets"].get(journal.get("category_name"))


def refunded_amounts(groups, links, refund_type_ids):
    journals = {str(j["transaction_journal_id"]): j for g in groups for j in g["attributes"]["transactions"]}
    refunded, seen_refunds = defaultdict(Decimal), set()
    for link in links:
        l = link["attributes"]
        # Other link types (related, duplicate, paid by) are not refunds.
        if str(l.get("link_type_id")) not in refund_type_ids:
            continue
        if str(l["outward_id"]) in seen_refunds:
            raise Deferred("One refund is linked to multiple expenses; allocate it explicitly")
        seen_refunds.add(str(l["outward_id"]))
        original, refund = journals.get(str(l["inward_id"])), journals.get(str(l["outward_id"]))
        if not original or not refund or original["type"] != "withdrawal" or refund["type"] != "deposit":
            raise Deferred("Refund link does not connect a withdrawal and deposit")
        if original["currency_id"] != refund["currency_id"]:
            raise Deferred("Refund currency differs; review before budgeting")
        refunded[str(l["inward_id"])] += money(refund["amount"])
        if refunded[str(l["inward_id"])] > money(original["amount"]):
            raise Deferred("Refund exceeds the original expense")
    return refunded


def spending_statistics(groups, links, config):
    refunded = refunded_amounts(groups, links, config["refund_type_ids"])
    stats = defaultdict(lambda: defaultdict(lambda: defaultdict(Decimal)))
    for group in groups:
        for journal in group["attributes"]["transactions"]:
            bucket = bucket_for(journal, config)
            if not bucket:
                continue
            month, currency = journal["date"][:7], journal["currency_code"]
            value = money(journal["amount"]) - refunded.get(str(journal["transaction_journal_id"]), Decimal(0))
            stats[month][bucket][currency] += value
    return {month: {bucket: {currency: amount(value) for currency, value in values.items()}
                    for bucket, values in buckets.items()} for month, buckets in stats.items()}


def next_month(value):
    return date(value.year + (value.month == 12), value.month % 12 + 1, 1)


def period_bounds(today, period):
    if period == "monthly":
        first = today.replace(day=1)
        last_month = today.month
    elif period == "quarterly":
        first = date(today.year, (today.month - 1) // 3 * 3 + 1, 1)
        last_month = first.month + 2
    else:
        raise Deferred("Unsupported automatic budget period")
    return first, date(today.year, last_month, calendar.monthrange(today.year, last_month)[1])


def current_limit(budget, limits, groups, today):
    """Compute a reset allowance or catch up positive monthly rollover."""
    b = budget["attributes"]
    first, _ = period_bounds(today, b.get("auto_budget_period"))
    if b.get("currency_code") != "UAH":
        raise Deferred("Only UAH automatic budgets are supported")
    kind, base = b["auto_budget_type"], money(b["auto_budget_amount"])
    if kind == "reset":
        return amount(base)
    if kind != "rollover" or b["auto_budget_period"] != "monthly":
        raise Deferred("Unsupported auto-budget mode")
    previous = [l for l in limits if l["attributes"]["currency_code"] == "UAH"
                and l["attributes"]["start"][:10] < first.isoformat()]
    if not previous:
        return amount(base)
    latest = max(previous, key=lambda l: l["attributes"]["start"])
    month = date.fromisoformat(latest["attributes"]["start"][:10]).replace(day=1)
    if (first.year - month.year) * 12 + first.month - month.month > 120:
        raise Deferred("Budget rollover history needs review")
    available = money(latest["attributes"]["amount"])
    while month < first:
        spent = sum((money(j["amount"]) for g in groups for j in g["attributes"]["transactions"]
                     if j["type"] == "withdrawal" and str(j.get("budget_id")) == budget["id"]
                     and j["currency_code"] == "UAH" and j["date"][:7] == month.strftime("%Y-%m")), Decimal(0))
        available = base + max(Decimal(0), available - spent)
        month = next_month(month)
    return amount(available)


def synchronize(client, config, state, today=None):
    today = today or datetime.now(KYIV).date()
    excluded_categories = config.get("excluded_categories", [])
    if (not isinstance(excluded_categories, list)
            or not all(isinstance(value, str) and value.strip() == value and value for value in excluded_categories)):
        raise Deferred("Excluded budget categories must be a list of exact category names")
    groups, budgets, links = client.all("/transactions?type=all"), client.all("/budgets"), client.all("/transaction-links")
    owned = {str(value) for value in config["budget_ids"].values()}
    by_id = {b["id"]: b for b in budgets}
    missing = owned - set(by_id)
    if missing:
        raise Deferred("A managed budget was deleted; review mappings")
    limits = {identifier: client.all(f"/budgets/{identifier}/limits") for identifier in owned}
    save_json(state / (f"before-{time.time_ns()}.json"), {"budgets": budgets, "limits": limits, "transactions": groups})
    refunded = refunded_amounts(groups, links, config["refund_type_ids"])
    assigned, exclusions, manual, category_exclusions = 0, 0, 0, 0
    for group in groups:
        patches = []
        for j in group["attributes"]["transactions"]:
            bucket = bucket_for(j, config)
            excluded = j["type"] == "withdrawal" and j.get("category_name") in excluded_categories
            if not excluded and (not bucket or bucket == "pending" or bucket not in config["budget_ids"]):
                continue
            current = str(j.get("budget_id") or "")
            fully_refunded = refunded.get(str(j["transaction_journal_id"]), Decimal(0)) == money(j["amount"])
            desired = "" if excluded or fully_refunded else config["budget_ids"][bucket]
            if desired and not by_id[desired]["attributes"].get("active"):
                continue
            if current and current not in owned:
                manual += 1
                continue
            if current != desired:
                patches.append({"transaction_journal_id": j["transaction_journal_id"], "budget_id": desired or None})
                j["budget_id"] = desired or None
                assigned += 1
                category_exclusions += bool(excluded)
            if fully_refunded:
                exclusions += 1
        if patches:
            client.request("PUT", f"/transactions/{group['id']}", {
                "apply_rules": False, "fire_webhooks": False, "transactions": patches})
    created = 0
    for identifier in owned:
        budget = by_id[identifier]
        if not budget["attributes"].get("active"):
            continue
        first, last = period_bounds(today, budget["attributes"].get("auto_budget_period"))
        if any(l["attributes"]["start"][:10] == first.isoformat() and l["attributes"]["end"][:10] == last.isoformat()
               and l["attributes"]["currency_code"] == "UAH" for l in limits[identifier]):
            continue
        if any(l["attributes"]["currency_code"] == "UAH" and l["attributes"]["start"][:10] <= last.isoformat()
               and l["attributes"]["end"][:10] >= first.isoformat() for l in limits[identifier]):
            raise Deferred("An overlapping custom budget limit needs review")
        value = current_limit(budget, limits[identifier], groups, today)
        client.request("POST", f"/budgets/{identifier}/limits", {
            "start": first.isoformat(), "end": last.isoformat(), "currency_code": "UAH", "amount": value,
            "fire_webhooks": False})
        created += 1
    result = {"finished_at": datetime.now(KYIV).isoformat(), "assigned": assigned,
              "fully_refunded_excluded": exclusions, "manual_preserved": manual, "limits_created": created}
    result["category_exclusions"] = category_exclusions
    save_json(state / "last-success.json", result)
    return result


def main():
    from firefly_api import Client
    from monobank_api import _private_file
    root = Path(__file__).resolve().parent
    state = root / ".state/budgets"
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args()
    with os.fdopen(_private_file(root / ".state/monobank/sync.lock", os.O_RDWR | os.O_CREAT), "a+") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SystemExit("A bank or budget synchronization is already running") from None
        print(json.dumps(synchronize(Client(), read_json(state / "config.json"), state), ensure_ascii=False))


if __name__ == "__main__":
    main()
