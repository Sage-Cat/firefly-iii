"""Keep unconfirmed bank transfers visible without guessing income or spending."""

import copy
import json
from datetime import datetime

from monobank_sync import Deferred, money

SUSPENSE_PREFIX = "bank-review:"


def note_object(notes):
    try:
        value = json.loads(notes or "{}")
    except (ValueError, TypeError):
        return {"original_notes": notes} if notes else {}
    return value if isinstance(value, dict) else {"original_notes": notes}


def is_suspense(asset):
    value = note_object(asset.get("notes"))
    return value.get("bank_review_suspense") == 1 and value.get("currency") == asset.get("currency_code")


def suspense_map(assets):
    result = {}
    for identifier, asset in assets.items():
        if not is_suspense(asset):
            continue
        code = asset["currency_code"]
        if code in result:
            raise Deferred("Duplicate transfer-review account for one currency")
        result[code] = str(identifier)
    return result


def prepare_suspense(client, accounts, operations):
    """Provision only the technical accounts actually required by a validated write plan."""
    assets = {a["id"]: a["attributes"] for a in accounts}
    present = suspense_map(assets)
    required = set()
    for operation in operations:
        tx = operation["transaction"]
        review = note_object(tx.get("notes")).get("bank_review", {})
        if review.get("status") in {"pending", "decided"} and tx.get("type") == "transfer":
            required.add(review["currency"])
    for code in sorted(required - set(present)):
        response = client.request("POST", "/accounts", {
            "name": f"Перекази на уточненні ({code})", "type": "asset",
            "account_role": "defaultAsset",
            "currency_id": next(a["currency_id"] for a in assets.values() if a["currency_code"] == code),
            "include_net_worth": True, "active": True,
            "notes": json.dumps({"bank_review_suspense": 1, "currency": code}, ensure_ascii=False)})
        created = response["data"]
        if created["attributes"].get("currency_code") != code or not is_suspense(created["attributes"]):
            raise Deferred("Created transfer-review account failed validation")
        accounts.append(created)
        present[code] = created["id"]
    return accounts


def matching_journal(groups, key):
    result = [g for g in groups if any(key in journal_keys(j) for j in g["attributes"]["transactions"])]
    if len(result) > 1:
        raise Deferred("Duplicate external identifier while reading a review decision")
    if result and len(result[0]["attributes"]["transactions"]) != 1:
        raise Deferred("A synchronized group was manually split")
    return result[0]["attributes"]["transactions"][0] if result else None


def review_of(tx):
    value = note_object(tx.get("notes")).get("bank_review")
    return value if isinstance(value, dict) else None


def checked_review(tx, existing, own, direction, code):
    review = review_of(existing)
    if review is None:
        return None
    expected = {"version": 1, "kind": "own_transfer", "key": tx["external_id"],
                "account_id": own, "direction": direction, "currency": code}
    if any(review.get(k) != v for k, v in expected.items()):
        raise Deferred("Transfer-review decision is not bound to this bank movement")
    if review.get("status") not in {"pending", "decided", "resolved"}:
        raise Deferred("Invalid transfer-review status")
    if "amount" in review and money(review["amount"]) != money(tx["amount"]):
        raise Deferred("Transfer-review amount changed after the decision")
    if "date" in review and datetime.fromisoformat(review["date"]) != datetime.fromisoformat(tx["date"]):
        raise Deferred("Transfer-review date changed after the decision")
    return copy.deepcopy(review)


def source_binding(tx, own, direction, code):
    return {"account_id": own, "direction": direction, "currency": code,
            "amount": tx["amount"], "date": tx["date"]}


def same_movement(journal, tx, own, direction, assets, seconds=600):
    if (journal.get("type") != "transfer" or journal.get("foreign_currency_id")
            or str(journal.get("currency_id")) != str(tx["currency_id"])
            or money(journal.get("amount")) != money(tx["amount"])):
        return False
    if any(str(journal.get(k, "")) not in assets or is_suspense(assets[str(journal[k])])
           for k in ("source_id", "destination_id")):
        return False
    side = "destination_id" if direction == "in" else "source_id"
    return (str(journal.get(side)) == own
            and abs((datetime.fromisoformat(journal["date"]) - datetime.fromisoformat(tx["date"])).total_seconds()) <= seconds)


def adopt_resolved(journal, tx, own, direction, code, assets, append=False):
    if not same_movement(journal, tx, own, direction, assets):
        raise Deferred("Reviewed transfer alias no longer matches its original movement")
    notes = note_object(journal.get("notes"))
    binding = source_binding(tx, own, direction, code)
    sources = notes.setdefault("bank_review_sources", {})
    key = tx["external_id"]
    if key in sources and sources[key] != binding:
        raise Deferred("Reviewed transfer alias changed its original bank evidence")
    if not append and key not in sources:
        raise Deferred("Reviewed transfer alias has no original bank evidence")
    sources[key] = binding
    aliases = notes.setdefault("bank_review_aliases", [])
    notes["bank_review_aliases"] = sorted(set(aliases + [key, journal["external_id"]]))
    desired = copy.deepcopy(tx)
    for field in ("foreign_currency_id", "foreign_amount", "source_name", "destination_name", "category_id", "category_name"):
        desired.pop(field, None)
    desired.update(type="transfer", source_id=str(journal["source_id"]),
                   destination_id=str(journal["destination_id"]), date=journal["date"],
                   notes=json.dumps(notes, ensure_ascii=False))
    return desired


def apply_review(tx, own, direction, code, assets, groups, suspected=False, counterparty=None, reserved=None):
    """Apply only a bound owner decision, otherwise post an uncategorized suspense transfer."""
    reserved = reserved if reserved is not None else set()
    existing = matching_journal(groups, tx["external_id"])
    if existing and existing.get("external_id") != tx["external_id"]:
        return adopt_resolved(existing, tx, own, direction, code, assets)
    old_review = checked_review(tx, existing, own, direction, code) if existing else None
    if suspected and existing is None:
        candidates = [j for group in groups for j in group["attributes"]["transactions"]
                      if review_of(j) and review_of(j).get("status") == "resolved"
                      and review_of(j).get("decision", {}).get("kind") == "transfer"
                      and str(review_of(j).get("account_id")) != own
                      and review_of(j).get("direction") != direction
                      and (j["external_id"], own, direction) not in reserved
                      and not any(str(source.get("account_id")) == own and source.get("direction") == direction
                                  for source_key, source in note_object(j.get("notes")).get("bank_review_sources", {}).items()
                                  if source_key != tx["external_id"])
                      and same_movement(j, tx, own, direction, assets)]
        if len(candidates) == 1:
            desired = adopt_resolved(candidates[0], tx, own, direction, code, assets, append=True)
            reserved.add((candidates[0]["external_id"], own, direction))
            return desired
    if not suspected and old_review is None:
        return tx
    tx = copy.deepcopy(tx)
    review = old_review or {"version": 1, "kind": "own_transfer", "status": "pending",
                           "key": tx["external_id"], "account_id": own,
                           "direction": direction, "currency": code,
                           "amount": tx["amount"], "date": tx["date"]}
    for field in ("source_id", "destination_id", "source_name", "destination_name", "category_name", "category_id"):
        tx.pop(field, None)
    decision = review.get("decision") if review["status"] in {"decided", "resolved"} else None
    paired_pending = False
    if isinstance(decision, dict) and decision.get("kind") == "transfer":
        for group in groups:
            for journal in group["attributes"]["transactions"]:
                other = review_of(journal)
                if (journal is existing or not other or other.get("key") == tx["external_id"]
                        or other.get("status") not in {"pending", "decided"}
                        or str(other.get("account_id")) != str(decision.get("account_id"))
                        or other.get("direction") == direction or other.get("currency") != code):
                    continue
                if (money(other.get("amount", journal.get("amount"))) == money(tx["amount"])
                        and abs((datetime.fromisoformat(other.get("date", journal["date"]))
                                 - datetime.fromisoformat(tx["date"])).total_seconds()) <= 600):
                    paired_pending = True
    if paired_pending:
        review["resolution_issue"] = "paired_pending"
    else:
        review.pop("resolution_issue", None)
    if decision and not paired_pending:
        if not isinstance(decision, dict) or decision.get("kind") not in {"transfer", "external"}:
            raise Deferred("Invalid transfer-review decision")
        if decision["kind"] == "transfer":
            peer = str(decision.get("account_id", ""))
            if peer == own or peer not in assets or is_suspense(assets[peer]) or assets[peer]["currency_code"] != code:
                raise Deferred("Review needs a different owned account in the same currency")
            tx.update(type="transfer", source_id=peer if direction == "in" else own,
                      destination_id=own if direction == "in" else peer)
        else:
            category = str(decision.get("category_id", ""))
            if not category.isdigit() or int(category) <= 0:
                raise Deferred("Review needs a valid selected category")
            tx.update(type="deposit" if direction == "in" else "withdrawal",
                      source_id=None if direction == "in" else own,
                      destination_id=own if direction == "in" else None)
            tx["source_name" if direction == "in" else "destination_name"] = (counterparty or tx["description"])[:255]
            tx["category_id"] = existing.get("category_id") if review["status"] == "resolved" and existing.get("category_id") else category
        review["status"] = "resolved"
    else:
        if review["status"] != "pending" and not paired_pending:
            raise Deferred("Decided transfer review has no decision")
        peer = suspense_map(assets).get(code, SUSPENSE_PREFIX + code)
        tx.update(type="transfer", source_id=peer if direction == "in" else own,
                  destination_id=own if direction == "in" else peer)
    if tx["type"] == "transfer":
        tx.pop("foreign_currency_id", None)
        tx.pop("foreign_amount", None)
    notes = note_object(existing.get("notes")) if existing else note_object(tx.get("notes"))
    notes["bank_review"] = review
    tx["notes"] = json.dumps(notes, ensure_ascii=False)
    return tx


def review_update(existing, desired):
    """A financial reroute is permitted only for the owner's still-unapplied decision."""
    before, after = review_of(existing), review_of(desired)
    return bool(before and after and before.get("status") == "decided" and after.get("status") == "resolved"
                and before.get("key") == after.get("key") and before.get("decision") == after.get("decision"))


def review_patch(existing, desired):
    fields = ("type", "date", "currency_id", "amount", "source_id", "destination_id",
              "source_name", "destination_name", "foreign_currency_id", "foreign_amount", "notes", "category_id")
    return {**{k: desired[k] for k in fields if k in desired},
            "transaction_journal_id": existing["transaction_journal_id"]}


def review_notes_update(existing, desired):
    before, after = review_of(existing), review_of(desired)
    if not before or not after or before.get("status") not in {"resolved", "decided"}:
        return False
    left, right = dict(before), dict(after)
    left.pop("resolution_issue", None)
    right.pop("resolution_issue", None)
    return left == right and existing.get("notes") != desired.get("notes")


def journal_keys(journal):
    aliases = note_object(journal.get("notes")).get("bank_review_aliases", [])
    return set([journal.get("external_id")] + aliases) if isinstance(aliases, list) else {journal.get("external_id")}
