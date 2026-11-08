"""Classify explicit bank cancellations and link uniquely matching refunds."""

import copy
import json
import os
import re
import stat
import time
from collections import defaultdict
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path

from monobank_sync import Deferred, KYIV, save_json

CATEGORY = "Повернення коштів"
LEGACY_CATEGORY = "Потребує уточнення"
MAX_PAIR_SECONDS = 10 * 60
PRESERVED_FIELDS = (
    "transaction_journal_id", "type", "date", "amount", "currency_id",
    "source_id", "destination_id", "foreign_currency_id", "foreign_amount",
    "description", "notes", "budget_id", "budget_name", "bill_id", "tags",
)
FINANCIAL_FIELDS = PRESERVED_FIELDS[:9]


def normalized(text):
    return " ".join(str(text).casefold().split())


def settled(journal):
    try:
        notes = json.loads(journal.get("notes") or "{}")
    except (TypeError, ValueError):
        notes = {}
    return not (journal.get("hold") is True or (isinstance(notes, dict) and notes.get("hold") is True))


def cancellation_vendor(journal):
    """An explicit bank cancellation is evidence; a generic incoming payment is not."""
    if journal.get("type") != "deposit" or not (
            journal.get("internal_reference") == "monobank"
            or str(journal.get("external_id", "")).startswith("monobank:")):
        return None
    if not settled(journal):
        return None
    match = re.fullmatch(r"\s*Скасування\s*[.:]\s*(.*?)\s*", str(journal.get("description", "")), re.IGNORECASE | re.DOTALL)
    return normalized(match[1]) if match else None


def indexed_journals(groups):
    result = {}
    for group in groups:
        for journal in group["attributes"]["transactions"]:
            identifier = str(journal["transaction_journal_id"])
            if identifier in result:
                raise Deferred("Duplicate journal identity while planning bank refunds")
            result[identifier] = {"group_id": str(group["id"]), "journal": journal}
    return result


def positive_amount(journal):
    try:
        value = Decimal(str(journal["amount"]))
        return value if value.is_finite() and value > 0 else None
    except (KeyError, TypeError, InvalidOperation):
        return None


def date_of(journal):
    try:
        value = datetime.fromisoformat(journal["date"])
    except (KeyError, TypeError, ValueError):
        return None
    return value.astimezone(KYIV) if value.tzinfo is not None else None


def owned_bank_side(journal, owned):
    source, destination = str(journal.get("source_id", "")), str(journal.get("destination_id", ""))
    if journal.get("type") == "withdrawal" and source in owned and destination not in owned:
        return source
    if journal.get("type") == "deposit" and destination in owned and source not in owned:
        return destination
    return None


def can_pair(original, refund, vendor, owned):
    if original.get("type") != "withdrawal" or not settled(original) or not (
            original.get("internal_reference") == "monobank"
            or str(original.get("external_id", "")).startswith("monobank:")):
        return False
    left, right = owned_bank_side(original, owned), owned_bank_side(refund, owned)
    amount, returned = positive_amount(original), positive_amount(refund)
    before, after = date_of(original), date_of(refund)
    return bool(vendor and left and left == right and amount is not None and amount == returned
                and original.get("currency_id") and original.get("currency_id") == refund.get("currency_id")
                and normalized(original.get("description", "")) == vendor and before and after
                and before.date() == after.date() and 0 <= (after - before).total_seconds() <= MAX_PAIR_SECONDS)


def refund_link_type(link_types):
    matches = [str(t["id"]) for t in link_types if t["attributes"].get("name") == "Refund"]
    if len(matches) != 1:
        raise Deferred("A unique native Refund link type is required")
    return matches[0]


def refund_category(categories):
    matches = [str(c["id"]) for c in categories if c["attributes"].get("name") == CATEGORY]
    if len(matches) > 1:
        raise Deferred("Duplicate refund category names require review")
    return matches[0] if matches else None


def link_status(links, original, refund, type_id):
    exact, conflicts = False, False
    for link in links:
        row = link["attributes"]
        left, right = str(row["inward_id"]), str(row["outward_id"])
        same_pair = {left, right} == {original, refund}
        is_refund = str(row.get("link_type_id")) == type_id
        if left == original and right == refund and is_refund:
            exact = True
        elif same_pair or (is_refund and ({left, right} & {original, refund})):
            conflicts = True
    return "conflict" if conflicts else "reuse" if exact else "create"


def plan_refunds(groups, accounts, categories, link_types, links):
    journals = indexed_journals(groups)
    owned = {str(a["id"]) for a in accounts}
    type_id, category_id = refund_link_type(link_types), refund_category(categories)
    refunds, all_cancellations, manual = {}, {}, 0
    for identifier, record in journals.items():
        journal = record["journal"]
        vendor = cancellation_vendor(journal)
        if vendor is None or owned_bank_side(journal, owned) is None or positive_amount(journal) is None:
            continue
        all_cancellations[identifier] = dict(record, vendor=vendor)
        category = journal.get("category_name")
        if (category not in (None, "", LEGACY_CATEGORY, CATEGORY)
                or (category in (None, "") and journal.get("category_id"))):
            manual += 1
            continue
        refunds[identifier] = dict(record, vendor=vendor)
    candidates, reverse = {}, defaultdict(list)
    for identifier, record in all_cancellations.items():
        candidates[identifier] = [key for key, original in journals.items()
                                  if can_pair(original["journal"], record["journal"], record["vendor"], owned)]
        for key in candidates[identifier]:
            reverse[key].append(identifier)
    pairs, ambiguous, conflicts = [], 0, 0
    for identifier, matches in candidates.items():
        if identifier not in refunds:
            continue
        if len(matches) != 1 or len(reverse[matches[0]]) != 1:
            ambiguous += bool(matches)
            continue
        status = link_status(links, matches[0], identifier, type_id)
        if status == "conflict":
            conflicts += 1
            continue
        pairs.append({"original_id": matches[0], "refund_id": identifier, "action": status})
    updates = [{"journal_id": key, "group_id": row["group_id"]} for key, row in refunds.items()
               if row["journal"].get("category_name") != CATEGORY]
    return {"category_id": category_id, "link_type_id": type_id, "updates": updates, "pairs": pairs,
            "manual_preserved": manual, "ambiguous": ambiguous, "link_conflicts": conflicts,
            "unpaired": len(refunds) - len(pairs)}


def snapshot(client):
    return {"accounts": client.all("/accounts?type=asset"), "groups": client.all("/transactions?type=all"),
            "categories": client.all("/categories"), "link_types": client.all("/link-types"),
            "links": client.all("/transaction-links")}


def _plan(state):
    return plan_refunds(state["groups"], state["accounts"], state["categories"], state["link_types"], state["links"])


def _fingerprint(journal, fields):
    return {key: copy.deepcopy(journal.get(key)) for key in fields}


def _group_journal(client, group_id, journal_id):
    group = client.request("GET", f"/transactions/{group_id}")["data"]
    matches = [j for j in group["attributes"]["transactions"] if str(j["transaction_journal_id"]) == journal_id]
    if len(matches) != 1:
        raise Deferred("Refund journal changed identity during classification")
    return matches[0]


def synchronize(client, state_dir, dry_run=False):
    """The caller holds the shared bank lock; all external calls use its configured local client."""
    state_dir = Path(state_dir)
    state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    mode = state_dir.lstat()
    if not stat.S_ISDIR(mode.st_mode) or mode.st_uid != os.getuid() or stat.S_IMODE(mode.st_mode) != 0o700:
        raise PermissionError("Bank refund state must be a private directory")
    before = snapshot(client)
    plan = _plan(before)
    save_json(state_dir / "last-plan.json", plan)
    result = {"category_created": False, "classified": 0, "links_created": 0, "links_reused": 0,
              "manual_preserved": plan["manual_preserved"], "ambiguous": plan["ambiguous"],
              "unpaired": plan["unpaired"], "link_conflicts": plan["link_conflicts"], "stale_skipped": 0}
    if dry_run:
        return dict(result, planned_classifications=len(plan["updates"]),
                    planned_links=sum(p["action"] == "create" for p in plan["pairs"]))
    save_json(state_dir / f"before-{time.time_ns()}.json", before)
    if plan["category_id"] is None:
        response = client.request("POST", "/categories", {"name": CATEGORY})
        plan["category_id"] = str(response["data"]["id"])
        if response["data"]["attributes"].get("name") != CATEGORY:
            raise Deferred("Created refund category failed validation")
        result["category_created"] = True
    originals = indexed_journals(before["groups"])
    for update in plan["updates"]:
        identifier = update["journal_id"]
        old = originals[identifier]["journal"]
        current = _group_journal(client, update["group_id"], identifier)
        if (_fingerprint(current, PRESERVED_FIELDS) != _fingerprint(old, PRESERVED_FIELDS)
                or current.get("category_id") != old.get("category_id")
                or current.get("category_name") != old.get("category_name")):
            result["stale_skipped"] += 1
            continue
        try:
            client.request("PUT", f"/transactions/{update['group_id']}", {
                "apply_rules": False, "fire_webhooks": False,
                "transactions": [{"transaction_journal_id": identifier, "category_id": plan["category_id"]}]},
                category_match=str(current["category_id"]) if current.get("category_id") else "none")
        except RuntimeError as error:
            if "HTTP 409:" not in str(error):
                raise
            result["stale_skipped"] += 1
            continue
        actual = _group_journal(client, update["group_id"], identifier)
        if (_fingerprint(actual, PRESERVED_FIELDS) != _fingerprint(old, PRESERVED_FIELDS)
                or str(actual.get("category_id")) != plan["category_id"]):
            raise Deferred("Refund classification changed preserved data or failed read-back")
        result["classified"] += 1
    for pair in plan["pairs"]:
        left, right = pair["original_id"], pair["refund_id"]
        original = _group_journal(client, originals[left]["group_id"], left)
        refund = _group_journal(client, originals[right]["group_id"], right)
        if (_fingerprint(original, FINANCIAL_FIELDS) != _fingerprint(originals[left]["journal"], FINANCIAL_FIELDS)
                or _fingerprint(refund, FINANCIAL_FIELDS) != _fingerprint(originals[right]["journal"], FINANCIAL_FIELDS)
                or str(refund.get("category_id")) != plan["category_id"]):
            result["stale_skipped"] += 1
            continue
        status = link_status(client.all("/transaction-links"), left, right, plan["link_type_id"])
        if status == "conflict":
            result["link_conflicts"] += 1
            continue
        if status == "reuse":
            result["links_reused"] += 1
            continue
        client.request("POST", "/transaction-links", {"link_type_id": plan["link_type_id"],
                       "inward_id": left, "outward_id": right})
        if link_status(client.all("/transaction-links"), left, right, plan["link_type_id"]) != "reuse":
            raise Deferred("Native refund link failed read-back")
        result["links_created"] += 1
    after = indexed_journals(client.all("/transactions?type=all"))
    for identifier, old in originals.items():
        if identifier not in after or (_fingerprint(after[identifier]["journal"], FINANCIAL_FIELDS)
                                      != _fingerprint(old["journal"], FINANCIAL_FIELDS)):
            raise Deferred("Original financial movement changed during refund synchronization")
    save_json(state_dir / "last-success.json", result)
    return result
