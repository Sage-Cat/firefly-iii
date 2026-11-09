"""Give uncategorized ordinary payments a real fallback while retaining review."""

import os
import stat
import time
from decimal import Decimal, InvalidOperation
from pathlib import Path

from bank_review import is_suspense, note_object
from monobank_sync import Deferred, save_json
from firefly_api import payment_fingerprint

CATEGORY = "Інше"
MARKER = "Потребує уточнення категорії"
LEGACY_CATEGORY = "Потребує уточнення"


def pending_default(journal):
    return bool(journal.get("category_id") and journal.get("category_name") == CATEGORY
                and MARKER in (journal.get("tags") or []))


def unclassified(journal):
    return ((not journal.get("category_id") and not journal.get("category_name"))
            or (journal.get("category_id") and journal.get("category_name") == LEGACY_CATEGORY))


def ordinary(journal, assets):
    kind = journal.get("type")
    if kind not in {"withdrawal", "deposit"}:
        return False
    review = note_object(journal.get("notes")).get("bank_review")
    if review and not (isinstance(review, dict) and review.get("status") == "resolved"
                       and isinstance(review.get("decision"), dict) and review["decision"].get("kind") == "external"):
        return False
    source, destination = str(journal.get("source_id", "")), str(journal.get("destination_id", ""))
    own, peer = (source, destination) if kind == "withdrawal" else (destination, source)
    try:
        amount = Decimal(str(journal.get("amount")))
    except (InvalidOperation, ValueError):
        return False
    return bool(amount.is_finite() and amount > 0 and own in assets and not is_suspense(assets[own])
                and peer.isdigit() and int(peer) > 0 and peer not in assets)


def plan(groups, accounts, categories, tags):
    """Plan only defaults; final/manual categories, transfers and money are untouched."""
    from bank_classification import _named
    from bank_refunds import indexed_journals
    assets = {str(row["id"]): row["attributes"] for row in accounts}
    updates = [{"group_id": record["group_id"], "journal_id": identifier}
               for identifier, record in indexed_journals(groups).items()
               if ordinary(record["journal"], assets) and unclassified(record["journal"])]
    return {"updates": updates,
            "category_id": _named(categories, CATEGORY, "name") if updates else None,
            "marker_id": _named(tags, MARKER, "tag") if updates else None}


def snapshot(client):
    return {"accounts": client.all("/accounts?type=asset"), "groups": client.all("/transactions?type=all"),
            "categories": client.all("/categories"), "tags": client.all("/tags")}


def synchronize(client, state_dir, dry_run=False):
    """The caller holds the bank lock; this operator requires no private config file."""
    from bank_classification import _ensure_metadata, _journal, _fingerprint, PRESERVED_FIELDS, FINANCIAL_FIELDS
    from bank_refunds import indexed_journals
    state_dir = Path(state_dir)
    state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    mode = state_dir.lstat()
    if not stat.S_ISDIR(mode.st_mode) or mode.st_uid != os.getuid() or stat.S_IMODE(mode.st_mode) != 0o700:
        raise PermissionError("Category fallback state must be a private directory")
    before = snapshot(client)
    planned = plan(**before)
    save_json(state_dir / "last-plan.json", planned)
    result = {"defaulted": 0, "stale_skipped": 0, "category_created": False, "marker_created": False}
    if dry_run:
        return dict(result, planned_defaults=len(planned["updates"]))
    if planned["updates"]:
        save_json(state_dir / f"before-{time.time_ns()}.json", before)
    originals = indexed_journals(before["groups"])
    for update in planned["updates"]:
        old = originals[update["journal_id"]]["journal"]
        current = _journal(client, update["group_id"], update["journal_id"])
        if (_fingerprint(current, PRESERVED_FIELDS + ("category_id", "category_name"))
                != _fingerprint(old, PRESERVED_FIELDS + ("category_id", "category_name"))):
            result["stale_skipped"] += 1
            continue
        if planned["category_id"] is None:
            planned["category_id"], result["category_created"] = _ensure_metadata(client, "/categories", CATEGORY, "name")
        if planned["marker_id"] is None:
            planned["marker_id"], result["marker_created"] = _ensure_metadata(client, "/tags", MARKER, "tag")
        current = _journal(client, update["group_id"], update["journal_id"])
        if (_fingerprint(current, PRESERVED_FIELDS + ("category_id", "category_name"))
                != _fingerprint(old, PRESERVED_FIELDS + ("category_id", "category_name"))):
            result["stale_skipped"] += 1
            continue
        try:
            client.request("PUT", f"/transactions/{update['group_id']}", {
                "apply_rules": False, "fire_webhooks": False,
                "transactions": [{"transaction_journal_id": update["journal_id"], "category_id": planned["category_id"]}]},
                category_match=str(current["category_id"]) if current.get("category_id") else "none", category_default=True,
                payment_hash=payment_fingerprint(update['group_id'], current))
        except RuntimeError as error:
            if "HTTP 409:" not in str(error):
                raise
            result["stale_skipped"] += 1
            continue
        actual = _journal(client, update["group_id"], update["journal_id"])
        expected_tags = set(current.get("tags") or []) | {MARKER}
        if (_fingerprint(actual, PRESERVED_FIELDS) != _fingerprint(current, PRESERVED_FIELDS)
                or not expected_tags.issubset(actual.get("tags") or []) or not pending_default(actual)
                or str(actual.get("category_id")) != planned["category_id"]):
            raise Deferred("Default category changed preserved data or failed read-back")
        result["defaulted"] += 1
    after = indexed_journals(client.all("/transactions?type=all"))
    for identifier, record in originals.items():
        if identifier not in after or (_fingerprint(after[identifier]["journal"], FINANCIAL_FIELDS)
                                      != _fingerprint(record["journal"], FINANCIAL_FIELDS)):
            raise Deferred("Financial movement changed during default categorization")
    save_json(state_dir / "last-success.json", result)
    return result
