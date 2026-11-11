"""Learn conservative exact-description categories and manage a separate native rule group.

No account identifiers, merchant lists or credentials belong in this module. The
caller holds the bank lock. Native rule changes never execute rules over history.
"""

import hashlib
import html
import json
import os
import stat
import re
from pathlib import Path

from bank_refunds import indexed_journals
from category_fallback import MARKER, pending_default
from monobank_sync import Deferred, read_json, save_json

GROUP_TITLE = "Автокатегорії з підтверджених платежів"
GROUP_DESCRIPTION = "category-learning:v1; Змінені вручну правила зберігаються."
MANIFEST = "learned-rules.json"


def _hash(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def description_reason(value):
    """Return a conservative exclusion reason without guessing a merchant identity."""
    from bank_classification import normalized
    if not isinstance(value, str) or value != value.strip() or not 3 <= len(value) <= 256:
        return "unsupported_format"
    if any(character in value for character in ('"', "'", "\\")) or any(character.isspace() and character != " " or ord(character) < 32 or ord(character) == 127 for character in value):
        return "native_query_syntax"
    text = normalized(value)
    if text.startswith(("portmone", "rpay", "wfp")) or re.match(r"prom(?:\b|[.*_])", text):
        return "payment_processor"
    if (not any(character.isalpha() for character in text) or re.search(r"\d{7,}", text)
            or re.search(r"(?:https?://|@|\biban\b|\bua\d{2})", text)):
        return "personal_identifier"
    if re.match(r"(?:фоп|fop)(?:\b|[.*_])", text):
        return "person_or_sole_trader"
    if re.match(r"(?:магазин|дія|на картку|переказ|поповнення|платіж|оплата|картка|на карту|card|p2p|visa direct|transfer|payment|fuib moneytransfer)(?:\b|[.*_])", text):
        return "generic_description"
    service_prefixes = {"парковка", "аптека", "купон", "кафе", "ресторан", "готель", "бар", "салон", "маркет",
                        "сервіс", "шиномонтаж", "доставка", "клініка", "банк", "головне", "управління", "товариство",
                        "державна", "державне", "державний", "комунальне"}
    if text.split()[0].rstrip(",.:") in service_prefixes:
        return None
    if re.fullmatch(r"[A-Za-zА-Яа-яІЇЄҐіїєґ’-]+[ ,]+(?=[A-ZА-ЯІЇЄҐ. ]*\.)[A-ZА-ЯІЇЄҐ. ]{1,8}", value):
        return "person_or_sole_trader"
    if re.fullmatch(r"[A-Za-zА-Яа-яІЇЄҐіїєґ’-]+(?:[A-ZА-ЯІЇЄҐ]{1,3}\.)+[A-ZА-ЯІЇЄҐ]\.?", value):
        return "person_or_sole_trader"
    words = value.split()
    if len(words) == 3 and all(re.fullmatch(r"[А-ЯІЇЄҐ][а-яіїєґ’-]+|[А-ЯІЇЄҐ]{2,}", word) for word in words):
        return "person_or_sole_trader"
    return None


def safe_description(value):
    return description_reason(value) is None


def learn(snapshot, config):
    """Create a pure, unanimous policy; conflicting normalized descriptions quarantine all variants."""
    from bank_classification import _ordinary, _refund_journals, _target, normalized, LEGACY_CATEGORY, PROVIDERS
    if not {"accounts", "groups", "categories", "link_types", "links"}.issubset(snapshot):
        raise Deferred("Learning requires complete account, category and refund-link evidence")
    assets = {str(row["id"]): row["attributes"] for row in snapshot["accounts"]}
    refunds = _refund_journals(snapshot.get("link_types", []), snapshot.get("links", []))
    categories = {str(row["id"]): row["attributes"].get("name") for row in snapshot["categories"]}
    exclusions = {normalized(value) for value in config.get("learning_exclusions", [])}
    families = {}
    excluded = 0
    for record in indexed_journals(snapshot["groups"]).values():
        journal = record["journal"]
        description = journal.get("description")
        bank_reference = (journal.get("internal_reference") in PROVIDERS
                          or any(str(journal.get("external_id", "")).startswith(provider + ":") for provider in PROVIDERS))
        if (not _ordinary(journal, assets, refunds, require_bank=bank_reference)
                or pending_default(journal) or MARKER in (journal.get("tags") or [])
                or not journal.get("category_id") or not journal.get("category_name")
                or journal["category_name"] in {LEGACY_CATEGORY, "Повернення коштів"}
                or categories.get(str(journal["category_id"])) != journal["category_name"]):
            continue
        if not isinstance(description, str) or not description:
            continue
        key = (journal["type"], normalized(description))
        family = families.setdefault(key, {"categories": set(), "descriptions": set(), "explicit_conflict": False})
        family["categories"].add((str(journal["category_id"]), journal["category_name"]))
        family["descriptions"].add(description)
        explicit, ambiguous = _target(config, journal)
        family["explicit_conflict"] |= bool(ambiguous or explicit and explicit["category"] != journal["category_name"])
    candidates, conflicts, excluded_descriptions = [], 0, []
    for (kind, _), family in sorted(families.items()):
        if len(family["categories"]) != 1 or family["explicit_conflict"]:
            conflicts += 1
            reason = "conflicting_categories" if len(family["categories"]) != 1 else "explicit_rule_conflict"
            excluded_descriptions.extend({"description": description, "reason": reason} for description in sorted(family["descriptions"]))
            continue
        category_id, category = next(iter(family["categories"]))
        for description in sorted(family["descriptions"]):
            reason = "private_exclusion" if normalized(description) in exclusions else description_reason(description)
            if reason:
                excluded += 1
                excluded_descriptions.append({"description": description, "reason": reason})
                continue
            explicit, ambiguous = _target(config, {"type": kind, "description": description,
                                                   "destination_name": "", "destination_id": ""})
            if ambiguous or explicit and explicit["category"] != category:
                conflicts += 1
                excluded_descriptions.append({"description": description, "reason": "explicit_rule_conflict"})
                continue
            candidates.append({"key": _hash([kind, description]), "kind": kind, "description": description,
                               "category": category, "category_id": category_id})
    return {"candidates": candidates, "conflicts": conflicts, "excluded": excluded, "excluded_descriptions": excluded_descriptions}


def rule_payload(candidate, group_id, title=None):
    prefix = "Витрата · " if candidate["kind"] == "withdrawal" else "Дохід · "
    if title is None:
        title = prefix + candidate["description"]
        if len(title) > 100:
            suffix = " · " + candidate["key"][:8]
            title = prefix + candidate["description"][:100 - len(prefix) - len(suffix) - 1] + "…" + suffix
    return {"rule_group_id": str(group_id), "title": title,
            "description": json.dumps({"firefly_learned_category": 1, "description": candidate["description"],
                                       "type": candidate["kind"], "category": candidate["category"]},
                                      ensure_ascii=False, sort_keys=True),
            "trigger": "store-journal", "strict": True,
            "active": True, "stop_processing": False,
            "triggers": [{"type": "transaction_type", "value": candidate["kind"], "active": True, "stop_processing": False},
                         {"type": "description_is", "value": candidate["description"], "active": True, "stop_processing": False},
                         {"type": "has_no_category", "value": "true", "active": True, "stop_processing": False}],
            "actions": [{"type": "set_category", "value": candidate["category"], "active": True, "stop_processing": False}]}


def _rule_shape(attributes):
    result = {key: attributes.get(key) for key in ("title", "description", "trigger", "strict", "active", "stop_processing", "order")}
    result["rule_group_id"] = str(attributes.get("rule_group_id"))
    for name in ("triggers", "actions"):
        if not isinstance(attributes.get(name), list):
            raise Deferred("Invalid native learned rule structure")
        result[name] = [{key: item.get(key, False if key == "prohibited" else None)
                         for key in (("type", "value", "active", "stop_processing", "order", "prohibited")
                                     if name == "triggers" else ("type", "value", "active", "stop_processing", "order"))}
                        for item in attributes[name]]
    if any(type(result[field]) is not bool for field in ("active", "strict", "stop_processing")):
        raise Deferred("Invalid native learned rule flags")
    for name in ("triggers", "actions"):
        for item in result[name]:
            flags = ("active", "stop_processing", "prohibited") if name == "triggers" else ("active", "stop_processing")
            if (any(type(item[field]) is not bool for field in flags)
                    or type(item["order"]) is not int or item["order"] < 1):
                raise Deferred("Invalid native learned condition flags")
    return result


def _valid_rule(row, payload):
    attributes = row.get("attributes", {})
    shape = _rule_shape(attributes)
    if not str(row.get("id", "")).isdigit() or type(shape["order"]) is not int or shape["order"] < 1:
        raise Deferred("Invalid native learned rule identity")
    if "order" in payload and shape["order"] != payload["order"]:
        raise Deferred("Native learned rule order differs")
    for field in ("title", "description", "trigger", "strict", "active", "stop_processing", "rule_group_id"):
        value = html.unescape(shape[field]) if field == "description" and isinstance(shape[field], str) else shape[field]
        if value != payload[field] or type(shape[field]) is not type(payload[field]):
            raise Deferred("Native learned rule read-back differs")
    for name in ("triggers", "actions"):
        expected = [dict(item, order=index + 1, **({"prohibited": False} if name == "triggers" else {}))
                    for index, item in enumerate(payload[name])]
        if shape[name] != expected:
            raise Deferred("Native learned rule conditions or action differ")
    return _hash(shape)


def _group_shape(row):
    attributes = row.get("attributes", {})
    return {key: attributes.get(key) for key in ("title", "description", "active", "order")}


def _set_active(client, actual, active):
    """The local API compares the complete rule shape under its database lock."""
    expected = _rule_shape(actual["attributes"])
    try:
        client.request("PUT", "/rules/" + str(actual["id"]), {"active": active}, rule_hash=_hash(expected))
    except RuntimeError as error:
        if "HTTP 409:" in str(error):
            return None
        raise
    changed = client.request("GET", "/rules/" + str(actual["id"]))["data"]
    expected["active"] = active
    if str(changed.get("id")) != str(actual["id"]) or _rule_shape(changed["attributes"]) != expected:
        raise Deferred("Learned rule state update changed native owner fields")
    return changed


def synchronize_rules(client, state_dir, learned, dry_run=False):
    """Durable inactive creation precedes conditional activation; owner edits are never rewritten."""
    state_dir = Path(state_dir)
    mode = state_dir.lstat()
    if not stat.S_ISDIR(mode.st_mode) or mode.st_uid != os.getuid() or stat.S_IMODE(mode.st_mode) != 0o700:
        raise PermissionError("Learned category rules require a private state directory")
    path = state_dir / MANIFEST
    try:
        state = read_json(path)
    except FileNotFoundError:
        state = {"version": 1, "group": None, "rules": {}, "intents": {}}
    if (not isinstance(state, dict) or state.get("version") != 1 or not isinstance(state.get("rules"), dict)
            or not isinstance(state.setdefault("intents", {}), dict)
            or state.get("group") is not None and not isinstance(state["group"], dict)):
        raise Deferred("Invalid learned category manifest")
    desired = {item["key"]: item for item in learned["candidates"]}
    result = {"created": 0, "adopted": 0, "activated": 0, "disabled": 0, "owner_changed": 0,
              "conflicts": learned["conflicts"], "excluded": learned["excluded"], "matchers": []}
    if dry_run:
        return dict(result, candidates=len(desired))
    groups = {str(row["id"]): row for row in client.all("/rule-groups")}
    rules = {str(row["id"]): row for row in client.all("/rules")}
    group = state["group"]
    if group is None:
        if not desired:
            return result
        expected_group = {"title": GROUP_TITLE, "description": GROUP_DESCRIPTION, "active": True}
        found = [row for row in groups.values() if row["attributes"].get("title") == GROUP_TITLE]
        if found and state.get("group_intent") != expected_group:
            return dict(result, owner_changed=1)
        if not found:
            state["group_intent"] = expected_group
            save_json(path, state)
            created = client.request("POST", "/rule-groups", expected_group)["data"]
            found = [created]
        if len(found) != 1:
            return dict(result, owner_changed=1)
        group_id = str(found[0]["id"])
        actual = client.request("GET", "/rule-groups/" + group_id)["data"]
        shape = _group_shape(actual)
        if (str(actual.get("id")) != group_id or not group_id.isdigit() or shape["title"] != GROUP_TITLE
                or shape["description"] != GROUP_DESCRIPTION or shape["active"] is not True
                or type(shape["order"]) is not int):
            raise Deferred("Native learned group read-back differs")
        state["group"] = group = {"id": group_id, "fingerprint": _hash(shape)}
        state.pop("group_intent", None)
        groups[group_id] = actual
        save_json(path, state)
    group_id = str(group.get("id"))
    actual_group = client.request("GET", "/rule-groups/" + group_id)["data"] if group_id in groups else None
    if actual_group is None or _hash(_group_shape(actual_group)) != group.get("fingerprint") or _group_shape(actual_group)["active"] is not True:
        return dict(result, owner_changed=1)

    def block(record):
        record["status"] = "owner_changed"
        result["owner_changed"] += 1
        save_json(path, state)

    def candidate_matches(record, candidate):
        return bool(candidate and candidate["category_id"] == record.get("category_id") and candidate["category"] == record.get("category"))

    def activate(record, actual):
        _valid_rule(actual, dict(record["payload"], active=False))
        # The activation intent is durable before the conditional API write.
        record["status"] = "activating"
        save_json(path, state)
        changed = _set_active(client, actual, True)
        if changed is None:
            block(record)
            return None
        _valid_rule(changed, dict(record["payload"], active=True))
        record.update(status="active", fingerprint=_hash(_rule_shape(changed["attributes"])))
        save_json(path, state)
        result["activated"] += 1
        return changed

    for key, record in list(state["rules"].items()):
        if not isinstance(record, dict) or not str(record.get("id", "")).isdigit() or not isinstance(record.get("fingerprint"), str):
            raise Deferred("Invalid managed category rule record")
        if record.get("status") not in {"active", "pending", "activating"}:
            continue
        identifier = str(record["id"])
        if identifier not in rules:
            block(record)
            continue
        actual = client.request("GET", "/rules/" + identifier)["data"]
        shape = _rule_shape(actual["attributes"])
        if record["status"] == "activating":
            # Recover only a completed activation. A still-disabled rule may be an owner's choice.
            inactive = dict(shape, active=False)
            if shape["active"] is True and _hash(inactive) == record["fingerprint"]:
                _valid_rule(actual, dict(record["payload"], active=True))
                record.update(status="active", fingerprint=_hash(shape))
                save_json(path, state)
            else:
                block(record)
                continue
        if _hash(shape) != record["fingerprint"]:
            block(record)
            continue
        candidate = desired.get(key)
        if not candidate_matches(record, candidate):
            if shape["active"]:
                changed = _set_active(client, actual, False)
                if changed is None:
                    block(record)
                    continue
                record["fingerprint"] = _hash(_rule_shape(changed["attributes"]))
            record["status"] = "disabled"
            result["disabled"] += 1
            save_json(path, state)
        else:
            if record["status"] == "pending":
                actual = activate(record, actual)
                if actual is None:
                    continue
            _valid_rule(actual, dict(record["payload"], active=True))
            result["matchers"].append(candidate)

    from bank_classification import normalized
    for key, candidate in desired.items():
        if key in state["rules"]:
            continue
        intent = state["intents"].get(key)
        if intent is not None and intent.get("status") != "pending":
            continue
        if intent is None:
            payload = dict(rule_payload(candidate, group_id), active=False)
            collisions = [row for row in rules.values() if normalized(row["attributes"].get("title")) == normalized(payload["title"])]
            if collisions:
                owned = {str(record["id"]) for record in state["rules"].values()}
                if any(str(row["id"]) not in owned for row in collisions):
                    result["owner_changed"] += 1
                    continue
                suffix = " · " + key[:8]
                payload["title"] = payload["title"][:100 - len(suffix)] + suffix
                if any(normalized(row["attributes"].get("title")) == normalized(payload["title"]) for row in rules.values()):
                    result["owner_changed"] += 1
                    continue
            payload["order"] = sum(str(row["attributes"].get("rule_group_id")) == group_id for row in rules.values()) + 1
            intent = {"payload": payload, "category_id": candidate["category_id"], "category": candidate["category"], "status": "pending"}
            state["intents"][key] = intent
            save_json(path, state)
        if not candidate_matches(intent, candidate):
            intent["status"] = "disabled"
            save_json(path, state)
            continue
        payload = intent["payload"]
        if payload != dict(rule_payload(candidate, group_id, payload.get("title")), active=False, order=payload.get("order")):
            raise Deferred("Invalid learned rule creation intent")
        found = [row for row in rules.values() if normalized(row["attributes"].get("title")) == normalized(payload["title"])]
        if not found:
            created = client.request("POST", "/rules", payload)["data"]
            found = [created]
            result["created"] += 1
        else:
            result["adopted"] += 1
        if len(found) != 1:
            block(intent)
            continue
        identifier = str(found[0]["id"])
        actual = client.request("GET", "/rules/" + identifier)["data"]
        fingerprint = _valid_rule(actual, payload)
        record = {"id": identifier, "fingerprint": fingerprint, "status": "pending", "payload": payload,
                  "category_id": candidate["category_id"], "category": candidate["category"]}
        state["rules"][key] = record
        del state["intents"][key]
        save_json(path, state)
        # Re-read after committing ownership so a concurrent owner edit cannot be activated.
        fresh = client.request("GET", "/rules/" + identifier)["data"]
        if _hash(_rule_shape(fresh["attributes"])) != fingerprint:
            block(record)
            continue
        changed = activate(record, fresh)
        rules[identifier] = fresh if changed is None else changed
        if changed is not None:
            result["matchers"].append(candidate)
    result["blocked"] = sum(record.get("status") in {"owner_changed", "disabled"} for record in state["rules"].values())
    return result
