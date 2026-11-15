"""Apply confirmed bank metadata rules without creating financial movements.

Private config.json schema: version=1, merchant_rules=[{prefix, category, tag (optional)}],
income_rules=[{destination_id OR description_prefix, category, tag (optional)}],
learning=bool (optional), learning_exclusions=[exact descriptions] (optional).
Merchant rules only match withdrawals; income rules only match external deposits.
Prefixes start at the first word and must end at a word boundary, ignoring case
and repeated whitespace. No rules or account identifiers are built into this module.
The caller opts in and holds the common bank synchronization lock.
"""

import copy
import os
import stat
import time
from pathlib import Path

from bank_refunds import date_of, indexed_journals, positive_amount, settled
from bank_review import is_suspense, note_object
from monobank_sync import Deferred, OWN_TRANSFER_HINTS, read_json, save_json
from category_fallback import pending_default, MARKER
from firefly_api import payment_fingerprint

PROVIDERS = {"monobank", "privat-personal", "privat-fop"}
LEGACY_CATEGORY = "Потребує уточнення"
REFUND_PREFIXES = ("скасування", "повернення", "refund", "reversal", "cancellation", "cancelled")
FINANCIAL_FIELDS = (
    "transaction_journal_id", "type", "date", "amount", "currency_id", "currency_code",
    "source_id", "destination_id", "foreign_currency_id", "foreign_currency_code", "foreign_amount",
)
PRESERVED_FIELDS = FINANCIAL_FIELDS + (
    "description", "notes", "hold", "source_name", "destination_name", "external_id", "internal_reference",
    "original_source", "import_hash_v2",
    "budget_id", "budget_name", "bill_id", "bill_name",
)


def normalized(value):
    return " ".join(str(value or "").casefold().split())


def prefix_matches(value, prefix):
    value, prefix = normalized(value), normalized(prefix)
    return bool(prefix and value.startswith(prefix) and (len(value) == len(prefix)
                or not (value[len(prefix)].isalnum() or value[len(prefix)] == "_")))


def _text(value):
    return isinstance(value, str) and bool(value.strip()) and value == value.strip()


def validate_config(config, assets):
    if (not isinstance(config, dict) or type(config.get("version")) is not int or config["version"] != 1
            or set(config) - {"version", "merchant_rules", "income_rules", "learning", "learning_exclusions"}):
        raise Deferred("Invalid confirmed bank classification configuration")
    if "learning" in config and type(config["learning"]) is not bool:
        raise Deferred("Category learning must be explicitly boolean")
    if ("learning_exclusions" in config and (not isinstance(config["learning_exclusions"], list)
            or not all(_text(value) for value in config["learning_exclusions"]))):
        raise Deferred("Category learning exclusions must be exact nonempty descriptions")
    for name in ("merchant_rules", "income_rules"):
        rows = config.get(name, [])
        if not isinstance(rows, list):
            raise Deferred("Confirmed classification rules must be lists")
        for row in rows:
            if not isinstance(row, dict) or not _text(row.get("category")):
                raise Deferred("Classification rule needs an approved category name")
            if name == "merchant_rules":
                valid = set(row) - {"tag"} == {"prefix", "category"} and _text(row.get("prefix"))
            else:
                selectors = set(row) & {"destination_id", "description_prefix"}
                valid = (len(selectors) == 1 and not set(row) - {
                    "destination_id", "description_prefix", "category", "tag"}
                    and _text(row.get(next(iter(selectors))))) if selectors else False
                if "destination_id" in row and (not _text(row["destination_id"]) or row["destination_id"] not in assets
                        or is_suspense(assets[row["destination_id"]])):
                    valid = False
            if "tag" in row and not _text(row["tag"]):
                valid = False
            if not valid:
                raise Deferred("Invalid or unowned confirmed bank rule selector")


def _refund_journals(link_types, links):
    kinds = {str(row["id"]) for row in link_types if row["attributes"].get("name") == "Refund"}
    return {str(row["attributes"][side]) for row in links
            if str(row["attributes"].get("link_type_id")) in kinds
            for side in ("inward_id", "outward_id")}


def _ordinary(journal, assets, refund_ids, require_bank=True):
    from monobank_authorizations import managed_pending
    if note_object(journal.get('notes')).get('monobank_authorization_review'):
        return False
    kind = journal.get("type")
    provider = journal.get("internal_reference")
    if (kind not in {"deposit", "withdrawal"}
            or (require_bank and (provider not in PROVIDERS
                                  or not str(journal.get("external_id", "")).startswith(provider + ":")))
            or not (settled(journal) or managed_pending(journal)) or positive_amount(journal) is None or date_of(journal) is None
            or str(journal.get("transaction_journal_id")) in refund_ids
            or journal.get("category_name") == "Повернення коштів"):
        return False
    notes = note_object(journal.get("notes"))
    review = notes.get("bank_review")
    if review and not (isinstance(review, dict) and review.get("status") == "resolved"
                       and isinstance(review.get("decision"), dict)
                       and review["decision"].get("kind") == "external"):
        return False
    if (any(notes.get(key) is True for key in ("refund", "cancelled", "reversed"))
            or any(prefix_matches(journal.get("description"), prefix) for prefix in REFUND_PREFIXES)
            or any(hint in normalized(journal.get("description")) for hint in OWN_TRANSFER_HINTS)):
        return False
    source, destination = str(journal.get("source_id", "")), str(journal.get("destination_id", ""))
    own, outside = (source, destination) if kind == "withdrawal" else (destination, source)
    return bool(own in assets and outside.isdigit() and int(outside) > 0
                and outside not in assets and not is_suspense(assets[own])
                and journal.get("currency_id") and str(journal["currency_id"]) == str(assets[own].get("currency_id")))


def _target(config, journal, learned=()):
    if journal["type"] == "withdrawal":
        matches = [row for row in config.get("merchant_rules", []) if any(
            prefix_matches(journal.get(field), row["prefix"]) for field in ("description", "destination_name"))]
    else:
        matches = [row for row in config.get("income_rules", []) if (
            str(journal.get("destination_id")) == row["destination_id"] if "destination_id" in row
            else prefix_matches(journal.get("description"), row["description_prefix"]))]
    # Explicit user rules take priority; learned rules match raw descriptions only.
    if not matches:
        matches = [row for row in learned if row["kind"] == journal["type"]
                   and row["description"] == journal.get("description")]
    categories = {row["category"] for row in matches}
    if len(categories) > 1:
        return None, True
    return ({"category": next(iter(categories)), "tags": sorted({row["tag"] for row in matches if "tag" in row})}
            if categories else None), False


def _named(rows, name, field):
    matches = [row for row in rows if normalized(row["attributes"].get(field)) == normalized(name)]
    if len(matches) > 1 or (matches and matches[0]["attributes"].get(field) != name):
        raise Deferred("Classification metadata name is not unique and exact")
    return str(matches[0]["id"]) if matches else None


def _category_conflict(journal, target, categories):
    category_id, name = journal.get("category_id"), journal.get("category_name")
    if name == target and category_id and str(category_id) == _named(categories, target, "name"):
        return False
    return not ((not category_id and not name) or (category_id and name == LEGACY_CATEGORY) or pending_default(journal))


def plan(config, groups, accounts, categories, tags, link_types=(), links=(), learned=()):
    """Return a pure metadata-only plan from a validated local ledger snapshot."""
    assets = {str(row["id"]): row["attributes"] for row in accounts}
    validate_config(config, assets)
    refunds = _refund_journals(link_types, links)
    updates, conflicts, ambiguous = [], 0, 0
    for identifier, record in indexed_journals(groups).items():
        journal = record["journal"]
        if not _ordinary(journal, assets, refunds):
            continue
        target, conflict = _target(config, journal, learned)
        ambiguous += conflict
        if target is None:
            continue
        category_id = _named(categories, target["category"], "name")
        for tag in target["tags"]:
            _named(tags, tag, "tag")
        if _category_conflict(journal, target["category"], categories):
            conflicts += 1
            continue
        category_change = str(journal.get("category_id")) != category_id or pending_default(journal)
        missing_tags = [tag for tag in target["tags"] if tag not in (journal.get("tags") or [])]
        if category_change or missing_tags:
            updates.append({"journal_id": identifier, "group_id": record["group_id"], **target,
                            "category_change": category_change, "missing_tags": missing_tags})
    return {"updates": updates, "category_conflicts": conflicts, "ambiguous_rules": ambiguous}


def snapshot(client):
    return {"accounts": client.all("/accounts?type=asset"), "groups": client.all("/transactions?type=all"),
            "categories": client.all("/categories"), "tags": client.all("/tags"),
            "link_types": client.all("/link-types"), "links": client.all("/transaction-links")}


def _fingerprint(journal, fields):
    return {key: copy.deepcopy(journal.get(key)) for key in fields}


def _journal(client, group_id, identifier):
    group = client.request("GET", f"/transactions/{group_id}")["data"]
    matches = [j for j in group["attributes"]["transactions"] if str(j["transaction_journal_id"]) == identifier]
    if len(matches) != 1:
        raise Deferred("Classification journal identity changed")
    return matches[0]


def _ensure_metadata(client, path, name, field):
    identifier = _named(client.all(path), name, field)
    if identifier:
        return identifier, False
    response = client.request("POST", path, {field: name})["data"]
    if response["attributes"].get(field) != name or not str(response["id"]).isdigit():
        raise Deferred("Created classification metadata failed validation")
    identifier = _named(client.all(path), name, field)
    if identifier != str(response["id"]):
        raise Deferred("Created classification metadata failed read-back")
    return identifier, True


def _put(client, update, patch, **options):
    return client.request("PUT", f"/transactions/{update['group_id']}", {
        "apply_rules": False, "fire_webhooks": False,
        "transactions": [{"transaction_journal_id": update["journal_id"], **patch}]}, **options)


def synchronize(client, state_dir, dry_run=False):
    """Read private confirmed rules; preserve manual categories and merge fresh tags."""
    state_dir = Path(state_dir)
    mode = state_dir.lstat()
    if not stat.S_ISDIR(mode.st_mode) or mode.st_uid != os.getuid() or stat.S_IMODE(mode.st_mode) != 0o700:
        raise PermissionError("Bank classification state must be a private directory")
    config = read_json(state_dir / "config.json")
    before = snapshot(client)
    validate_config(config, {str(row["id"]): row["attributes"] for row in before["accounts"]})
    learning_result = None
    matchers = []
    if config.get("learning"):
        try:
            from category_learning import learn, synchronize_rules
            learned = learn(before, config)
            save_json(state_dir / "learned-plan.json", learned)
            learning_result = synchronize_rules(client, state_dir, learned, dry_run)
            matchers = learning_result.pop("matchers")
        except Exception as error:
            # Financial imports already completed; a learning failure must not block them.
            learning_result = {"error": type(error).__name__, "status": "deferred"}
            save_json(state_dir / "learning-error.json", learning_result)
    planned = plan(config, **before, learned=matchers)
    save_json(state_dir / "last-plan.json", planned)
    result = {"classified": 0, "tagged": 0, "categories_created": 0, "tags_created": 0,
              "category_conflicts": planned["category_conflicts"], "ambiguous_rules": planned["ambiguous_rules"],
              "stale_skipped": 0}
    if learning_result is not None:
        result["learning"] = learning_result
    if dry_run:
        return dict(result, planned_classifications=sum(u["category_change"] for u in planned["updates"]),
                    planned_tags=sum(bool(u["missing_tags"]) for u in planned["updates"]))
    if planned["updates"]:
        save_json(state_dir / f"before-{time.time_ns()}.json", {"config": config, **before})
    originals = indexed_journals(before["groups"])
    for update in planned["updates"]:
        identifier = update["journal_id"]
        old = originals[identifier]["journal"]
        current = _journal(client, update["group_id"], identifier)
        if (_fingerprint(current, PRESERVED_FIELDS) != _fingerprint(old, PRESERVED_FIELDS)
                or any(current.get(key) != old.get(key) for key in ("category_id", "category_name"))):
            result["category_conflicts"] += any(current.get(key) != old.get(key)
                                                 for key in ("category_id", "category_name"))
            result["stale_skipped"] += 1
            continue
        if update["category_change"]:
            category_id, created = _ensure_metadata(client, "/categories", update["category"], "name")
            result["categories_created"] += created
            fresh = _journal(client, update["group_id"], identifier)
            if (_fingerprint(fresh, PRESERVED_FIELDS + ("category_id", "category_name"))
                    != _fingerprint(current, PRESERVED_FIELDS + ("category_id", "category_name"))):
                result["category_conflicts"] += any(fresh.get(key) != current.get(key)
                                                     for key in ("category_id", "category_name"))
                result["stale_skipped"] += 1
                continue
            current = fresh
            try:
                _put(client, update, {"category_id": category_id},
                     category_match=str(current["category_id"]) if current.get("category_id") else "none",
                     payment_hash=payment_fingerprint(update['group_id'], current))
            except RuntimeError as error:
                if "HTTP 409:" not in str(error):
                    raise
                result["category_conflicts"] += 1
                result["stale_skipped"] += 1
                continue
            actual = _journal(client, update["group_id"], identifier)
            expected_tags = [tag for tag in (current.get("tags") or []) if not (pending_default(current) and tag == MARKER)]
            if (_fingerprint(actual, PRESERVED_FIELDS) != _fingerprint(current, PRESERVED_FIELDS)
                    or sorted(actual.get("tags") or []) != sorted(expected_tags)
                    or str(actual.get("category_id")) != category_id or actual.get("category_name") != update["category"]):
                raise Deferred("Category update changed preserved data or failed read-back")
            current = actual
            result["classified"] += 1
        if update["tags"]:
            for tag in update["tags"]:
                if tag not in (current.get("tags") or []):
                    _, created = _ensure_metadata(client, "/tags", tag, "tag")
                    result["tags_created"] += created
            # Native tag updates replace the full list: merge immediately before writing.
            fresh = _journal(client, update["group_id"], identifier)
            if (_fingerprint(fresh, PRESERVED_FIELDS + ("category_id", "category_name"))
                    != _fingerprint(current, PRESERVED_FIELDS + ("category_id", "category_name"))):
                result["category_conflicts"] += any(fresh.get(key) != current.get(key)
                                                     for key in ("category_id", "category_name"))
                result["stale_skipped"] += 1
                continue
            merged = list(fresh.get("tags") or [])
            for tag in update["tags"]:
                if tag not in merged:
                    merged.append(tag)
            if merged != list(fresh.get("tags") or []):
                from firefly_api import tag_fingerprint
                try:
                    _put(client, update, {"tags": merged}, tags_hash=tag_fingerprint(fresh.get("tags") or []),
                         category_match=str(fresh["category_id"]) if fresh.get("category_id") else "none",
                         payment_hash=payment_fingerprint(update['group_id'], fresh))
                except RuntimeError as error:
                    if "HTTP 409:" not in str(error):
                        raise
                    actual = _journal(client, update["group_id"], identifier)
                    result["category_conflicts"] += any(actual.get(key) != fresh.get(key)
                                                         for key in ("category_id", "category_name"))
                    result["stale_skipped"] += 1
                    continue
                actual = _journal(client, update["group_id"], identifier)
                if (_fingerprint(actual, PRESERVED_FIELDS + ("category_id", "category_name"))
                        != _fingerprint(fresh, PRESERVED_FIELDS + ("category_id", "category_name"))
                        or sorted(actual.get("tags") or []) != sorted(merged)):
                    raise Deferred("Tag update changed preserved data or failed read-back")
                result["tagged"] += 1
    after = indexed_journals(client.all("/transactions?type=all"))
    for identifier, old in originals.items():
        if identifier not in after or (_fingerprint(after[identifier]["journal"], FINANCIAL_FIELDS)
                                      != _fingerprint(old["journal"], FINANCIAL_FIELDS)):
            raise Deferred("Financial movement changed during bank classification")
    save_json(state_dir / "last-success.json", result)
    return result
