"""Read-only bank ingestion into the local Firefly ledger, with reconciliation."""

import argparse
import copy
import fcntl
import hashlib
import json
import os
import tempfile
import time
import urllib.parse
import urllib.request
from datetime import datetime
from decimal import Decimal
from pathlib import Path
from zoneinfo import ZoneInfo

from firefly_api import Client as FireflyClient, NoRedirect
from monobank_api import Client as BankClient, _private_file

ROOT = Path(__file__).resolve().parent
STATE = ROOT / ".state/monobank"
KYIV = ZoneInfo("Europe/Kyiv")
CURRENCIES = {980: "UAH", 978: "EUR", 840: "USD", 756: "CHF"}
ZERO = Decimal("0")
OWN_TRANSFER_HINTS = ("між власними", "між своїми", "своїми рахунками", "зі своєї картки",
                      "з власної картки", "на свою картку", "власних коштів", "власні кошти",
                      "з чорної картки", "з гривневого рахунку фоп", "на гривневий рахунок фоп",
                      "обмін валют", "обмін валюти", "own account", "currency exchange")


class Deferred(RuntimeError):
    """Inconsistent bank data or an invalid confirmed mapping must not change the ledger."""


def read_json(path):
    with os.fdopen(_private_file(path, os.O_RDONLY)) as stream:
        return json.load(stream)


def save_json(path, data):
    fd, temporary = tempfile.mkstemp(prefix=".sync-", dir=path.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            json.dump(data, stream, ensure_ascii=False, indent=2)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def money(value):
    return Decimal(str(value or "0"))


def minor(value):
    if type(value) is not int:
        raise Deferred("Bank amounts must use integer minor units")
    return Decimal(value) / 100


def amount(value):
    return format(value.quantize(Decimal("0.01")), "f")


def external_key(account_id, record_id, provider="monobank"):
    account_hash = hashlib.sha256(account_id.encode()).hexdigest()[:16]
    record_hash = hashlib.sha256(record_id.encode()).hexdigest()
    return f"{provider}:{account_hash}:{record_hash}"


def statement_file(account_id):
    return f"statements-{hashlib.sha256(account_id.encode()).hexdigest()[:16]}.json"


def start_timestamp(config):
    return int(datetime.fromisoformat(config["start_date"]).replace(tzinfo=KYIV).timestamp())


def validate_bindings(config):
    bindings = config["bindings"]
    for field in ("bank_id", "firefly_id"):
        values = [b[field] for b in bindings]
        if (not values or any(not isinstance(v, str) or not v for v in values)
                or len(set(values)) != len(values)):
            raise Deferred("Bank and Firefly bindings must be one-to-one")


def collect(bank, config, state_dir=STATE):
    """Persist a full selected history, refreshing a seven-day overlap each run."""
    validate_bindings(config)
    requested = int(time.time())
    info = bank.client_info()
    # The balance may change while client-info waits for its rate-limit slot.
    started = int(time.time())
    envelopes = {}
    previous = (read_json(state_dir / "snapshot.json")["statements"]
                if (state_dir / "snapshot.json").exists() else {})
    for binding in config["bindings"]:
        account_id = binding["bank_id"]
        path = state_dir / statement_file(account_id)
        old = previous.get(path.name) or (read_json(path) if path.exists() else None)
        beginning = start_timestamp(config)
        # A cached range must actually cover the configured start.
        if old and old["from"] <= beginning:
            beginning = max(beginning, old["to"] - 7 * 86400)
        else:
            old = None
        cursor = beginning
        refreshed = {}
        while cursor < started:
            end = min(started, cursor + BankClient.MAX_STATEMENT_SECONDS)
            items = bank.statement(account_id, cursor, end)
            if not isinstance(items, list) or len(items) >= 500:
                raise Deferred("Statement window may be truncated; narrow it before retrying")
            for item in items:
                if not cursor <= item["time"] <= end:
                    raise Deferred("Bank returned a record outside the requested window")
                refreshed[item["id"]] = item
            cursor = end
        merged = {item["id"]: item for item in (old or {}).get("items", [])
                  if item["time"] < beginning}
        merged.update(refreshed)
        envelope = {"account_id": account_id,
                    "from": min(start_timestamp(config), old["from"]) if old else start_timestamp(config),
                    "to": started, "fetched_at": int(time.time()),
                    "items": list(merged.values())}
        # Commit the coherent snapshot only after every account has been fetched.
        envelopes[path.name] = envelope
    snapshot = {"request_started_at": requested, "as_of": started,
                "fetched_at": int(time.time()), "data": info}
    # One atomic bundle is authoritative; legacy individual source files are kept.
    bundle = {"client_info": snapshot, "statements": envelopes}
    save_json(state_dir / "snapshot.json", bundle)
    return bundle


def cached_snapshot(config, state_dir=STATE):
    path = state_dir / "snapshot.json"
    if path.exists():
        return read_json(path)
    return {"client_info": read_json(state_dir / "client-info.json"),
            "statements": {statement_file(b["bank_id"]):
                           read_json(state_dir / statement_file(b["bank_id"]))
                           for b in config["bindings"]}}


def extend_snapshot(bank, config, snapshot, state_dir=STATE):
    """Fetch only missing older ranges while retaining the existing as-of cutoff."""
    validate_bindings(config)
    beginning = start_timestamp(config)
    result = copy.deepcopy(snapshot)
    for binding in config["bindings"]:
        account = binding["bank_id"]
        name = statement_file(account)
        old = result["statements"][name]
        items = {i["id"]: i for i in old["items"]}
        covered = old["from"]
        legacy_path = state_dir / name
        if legacy_path.exists():
            legacy = read_json(legacy_path)
            if legacy["from"] < covered <= legacy["to"] <= old["to"]:
                original = {i["id"]: i for i in legacy["items"]}
                original.update(items)
                items, covered = original, legacy["from"]
        cursor = beginning
        while cursor < covered:
            end = min(covered, cursor + BankClient.MAX_STATEMENT_SECONDS)
            rows = bank.statement(account, cursor, end)
            if not isinstance(rows, list) or len(rows) >= 500:
                raise Deferred("Older statement window may be truncated")
            for row in rows:
                if not cursor <= row["time"] <= end:
                    raise Deferred("Older bank record is outside the requested window")
                # Newer cached revisions take precedence at an inclusive boundary.
                items.setdefault(row["id"], row)
            cursor = end
        old.update({"from": min(beginning, covered), "items": list(items.values()),
                    "extended_at": int(time.time())})
    save_json(state_dir / "snapshot.json", result)
    return result


def bank_records(config, snapshot):
    validate_bindings(config)
    accounts = {a["id"]: a for a in snapshot["client_info"]["data"]["accounts"]}
    targets, records = {}, []
    for b in config["bindings"]:
        bank = accounts.get(b["bank_id"])
        if not bank or CURRENCIES.get(bank["currencyCode"]) != b["currency_code"]:
            raise Deferred("A configured bank account is missing or changed currency")
        if bank.get("creditLimit", 0) != 0:
            raise Deferred("Credit-limit accounting requires explicit configuration")
        targets[b["firefly_id"]] = minor(bank["balance"])
        envelope = snapshot["statements"][statement_file(b["bank_id"])]
        if envelope["from"] > start_timestamp(config):
            raise Deferred("Statement history does not cover the selected start")
        settled = [i for i in envelope["items"] if not i.get("hold")]
        if settled:
            newest = max(i["time"] for i in settled)
            balances = {minor(i["balance"]) for i in settled if i["time"] == newest}
            if targets[b["firefly_id"]] not in balances:
                raise Deferred("Statement closing balance differs from account snapshot; fetch again")
        seen = set()
        for item in envelope["items"]:
            if item["time"] < start_timestamp(config):
                continue
            if item["id"] in seen:
                raise Deferred("Duplicate bank record in source")
            seen.add(item["id"])
            if item.get("hold"):
                raise Deferred("Pending bank authorization; wait for settlement before reconciliation")
            if minor(item["amount"]) == ZERO:
                continue
            records.append({"binding": b, "item": item,
                            "key": external_key(b["bank_id"], item["id"], config.get("provider", "monobank"))})
    return targets, sorted(records, key=lambda r: (r["item"]["time"], r["key"]))


def make_operations(config, records, assets, groups=None):
    from bank_review import apply_review, matching_journal, review_of, note_object
    groups = groups or []
    review_reservations = set()
    operations, consumed = [], set()
    by_bank = {b["bank_id"]: b for b in config["bindings"]}
    own_ibans = {iban.replace(" ", ""): b["firefly_id"] for b in config["bindings"]
                 for iban in b.get("ibans", [])}
    own_ibans.update(config.get("own_ibans", {}))
    self_names = {s.casefold().strip() for s in config.get("self_names", []) if s.strip()}
    normalized_names = {tuple(sorted(s.split())) for s in self_names}
    approved_pairs = config.get("approved_exchange_pairs", {})
    if (not isinstance(approved_pairs, dict) or any(
            not isinstance(left, str) or not isinstance(right, str) or
            approved_pairs.get(right) != left for left, right in approved_pairs.items())):
        raise Deferred("Currency exchange pair mapping must be reciprocal")

    def mapped_peer(record):
        return config.get("approved_transfers", {}).get(record["key"]) or own_ibans.get(
            record["item"].get("counterIban", "").replace(" ", ""))

    def own_identity(record, expected):
        mapped = mapped_peer(record)
        if mapped:
            return mapped == expected
        if record["item"].get("counterIban"):
            return False
        name = record["item"].get("counterName", "").casefold().strip()
        if name and tuple(sorted(name.split())) not in normalized_names:
            return False
        description = record["item"].get("description", "").casefold()
        return tuple(sorted(name.split())) in normalized_names or any(h in description for h in OWN_TRANSFER_HINTS)

    def same_currency_pair(left, right):
        return (left["binding"]["firefly_id"] != right["binding"]["firefly_id"]
                and left["binding"]["currency_code"] == right["binding"]["currency_code"]
                and minor(left["item"]["amount"]) == -minor(right["item"]["amount"])
                and abs(left["item"]["time"] - right["item"]["time"]) <= 600)

    def reciprocal_records(record, peer, available=False):
        return [r for r in records if (not available or r["key"] not in consumed)
                and r["binding"]["firefly_id"] == peer and same_currency_pair(record, r)
                and own_identity(r, record["binding"]["firefly_id"])]

    def review_pair_safe(left, right):
        source = left if left["item"]["amount"] < 0 else right
        destination = right if source is left else left
        for record in (left, right):
            existing = matching_journal(groups, record["key"])
            state = review_of(existing) if existing else None
            if state and (state.get("status") != "resolved" or state.get("decision", {}).get("kind") != "transfer"
                          or str(existing.get("source_id")) != source["binding"]["firefly_id"]
                          or str(existing.get("destination_id")) != destination["binding"]["firefly_id"]):
                return False
        return True

    def exchange_matches(left, right):
        a, b = left["item"], right["item"]
        return (left["binding"]["firefly_id"] != right["binding"]["firefly_id"]
                and left["binding"]["currency_code"] != right["binding"]["currency_code"]
                and CURRENCIES.get(a.get("currencyCode")) == right["binding"]["currency_code"]
                and CURRENCIES.get(b.get("currencyCode")) == left["binding"]["currency_code"]
                and type(a.get("operationAmount")) is int and type(b.get("operationAmount")) is int
                and a["amount"] == -b["operationAmount"] and b["amount"] == -a["operationAmount"]
                and abs(a["time"] - b["time"]) <= 2)

    def confirmed_exchange(left, right):
        for current, other in ((left, right), (right, left)):
            item = current["item"]
            mapped = mapped_peer(current)
            if mapped and mapped != other["binding"]["firefly_id"]:
                return False
            if item.get("counterIban") and not mapped:
                return False
            name = item.get("counterName", "").casefold().strip()
            if name and tuple(sorted(name.split())) not in normalized_names and not mapped:
                return False
        return (mapped_peer(left) == right["binding"]["firefly_id"]
                and mapped_peer(right) == left["binding"]["firefly_id"]) or any(
            hint in r["item"].get("description", "").casefold()
            for r in (left, right) for hint in OWN_TRANSFER_HINTS)

    for record in records:
        if record["key"] in consumed:
            continue
        item, b = record["item"], record["binding"]
        value = minor(item["amount"])
        own = b["firefly_id"]
        peer = mapped_peer(record)
        if not peer:
            reverse = [r for r in records if r["key"] not in consumed and same_currency_pair(record, r)
                       and mapped_peer(r) == own and own_identity(record, r["binding"]["firefly_id"])]
            if len(reverse) == 1:
                peer = reverse[0]["binding"]["firefly_id"]
        exchange_pair = None
        candidates = [r for r in records if r["key"] not in consumed and exchange_matches(record, r)]
        compatible = [r for r in candidates if approved_pairs.get(r["key"]) in {None, record["key"]}]
        if candidates and not compatible:
            raise Deferred("Currency exchange pair mapping contradicts its bank counterpart")
        candidates = compatible
        expected_pair = approved_pairs.get(record["key"])
        if expected_pair:
            candidates = [r for r in candidates if r["key"] == expected_pair]
            if len(candidates) != 1:
                raise Deferred("Explicit currency exchange pair does not match bank amounts")
        if candidates:
            if len(candidates) != 1 or not confirmed_exchange(record, candidates[0]):
                raise Deferred("Currency exchange has no uniquely confirmed own-account counterpart")
            exchange_pair = candidates[0]
            peer = exchange_pair["binding"]["firefly_id"]
        aliases = [record["key"]]
        suspected, suggested_peer = False, None
        if peer:
            if peer == own or peer not in assets:
                raise Deferred("Invalid own-transfer account mapping")
            if assets[peer]["currency_code"] != b["currency_code"] and not exchange_pair:
                raise Deferred("Cross-currency own transfer needs both exact currency amounts")
            # A missing reciprocal bank field is classification work, not a reason to stop ingestion.
            if peer in {x["firefly_id"] for x in by_bank.values()}:
                matches = [exchange_pair] if exchange_pair else reciprocal_records(record, peer, available=True)
                unique = len(matches) == 1
                if unique and not exchange_pair:
                    unique = (len(reciprocal_records(record, peer)) == 1
                              and len(reciprocal_records(matches[0], own)) == 1)
                if not unique or not review_pair_safe(record, matches[0]):
                    suggested_peer, peer, suspected = peer, None, True
                    exchange_pair = None
                else:
                    other = matches[0]
                    aliases.append(other["key"])
                    if value > 0:
                        record, item, b = other, other["item"], other["binding"]
                        value, own, peer = minor(item["amount"]), peer, own
        if peer:
            source, destination = (peer, own) if value > 0 else (own, peer)
            kind = "transfer"
        else:
            counter_name = item.get("counterName", "").casefold().strip()
            description = item.get("description", "").casefold()
            if (tuple(sorted(counter_name.split())) in normalized_names or any(name and name in description for name in self_names)
                    or any(hint in description for hint in OWN_TRANSFER_HINTS)):
                suspected = True
            source = own if value < 0 else None
            destination = own if value > 0 else None
            kind = "withdrawal" if value < 0 else "deposit"
        text = item.get("description") or item.get("counterName") or "monobank"
        transaction = {"type": kind, "date": datetime.fromtimestamp(item["time"], KYIV).isoformat(),
                       "currency_id": assets[own]["currency_id"], "amount": amount(abs(value)),
                       "description": text[:1000], "external_id": record["key"],
                       "internal_reference": config.get("provider", "monobank"),
                       "tags": [config.get("provider", "monobank")],
                       "notes": json.dumps({k: item[k] for k in (
                           "id", "mcc", "originalMcc", "operationAmount", "currencyCode",
                           "commissionRate", "cashbackAmount", "reference", "documentNumber")
                           if k in item}, ensure_ascii=False)}
        if exchange_pair:
            transaction["foreign_currency_id"] = assets[destination]["currency_id"]
            transaction["foreign_amount"] = amount(abs(minor(item["operationAmount"])))
        if source:
            transaction["source_id"] = source
        else:
            transaction["source_name"] = (item.get("counterName") or text)[:255]
        if destination:
            transaction["destination_id"] = destination
        else:
            transaction["destination_name"] = (item.get("counterName") or text)[:255]
        transaction = apply_review(transaction, own, "in" if value > 0 else "out", b["currency_code"],
                                   assets, groups, suspected, item.get("counterName") or text, reserved=review_reservations)
        if suggested_peer and review_of(transaction) and review_of(transaction).get("status") in {"pending", "decided"}:
            notes = note_object(transaction["notes"])
            notes["bank_review"]["suggested_account_id"] = suggested_peer
            transaction["notes"] = json.dumps(notes, ensure_ascii=False)
        operations.append({"key": record["key"], "aliases": aliases, "transaction": transaction})
        consumed.update(aliases)
    return operations


def effects(transaction, assets):
    result = {}
    for field, sign in (("source_id", -1), ("destination_id", 1)):
        account = str(transaction.get(field, ""))
        if account in assets:
            value = money(transaction["amount"])
            if (field == "destination_id" and transaction.get("foreign_currency_id")
                    and str(transaction["foreign_currency_id"]) == str(assets[account]["currency_id"])):
                value = money(transaction["foreign_amount"])
            result[account] = result.get(account, ZERO) + sign * value
    return result


def find_existing(operation, groups, references):
    from bank_review import journal_keys
    matches = []
    for group in groups:
        journals = group["attributes"]["transactions"]
        if (references.get(operation["key"]) == group["id"] or any(
                journal_keys(j).intersection(operation["aliases"]) for j in journals)):
            matches.append(group)
    if len(matches) > 1:
        raise Deferred("Duplicate external identifier in Firefly")
    if not matches and operation["transaction"]["type"] == "transfer":
        desired = operation["transaction"]
        for group in groups:
            journals = group["attributes"]["transactions"]
            if len(journals) != 1:
                continue
            j = journals[0]
            if (j["type"] == "transfer" and all(str(j.get(k)) == str(desired.get(k))
                    for k in ("source_id", "destination_id", "currency_id"))
                    and money(j["amount"]) == money(desired["amount"])
                    and (not desired.get("foreign_currency_id") or (
                        str(j.get("foreign_currency_id")) == str(desired["foreign_currency_id"])
                        and money(j.get("foreign_amount")) == money(desired["foreign_amount"])))
                    and abs((datetime.fromisoformat(j["date"]) -
                             datetime.fromisoformat(desired["date"])).total_seconds()) <= 86400):
                matches.append(group)
        if len(matches) > 1:
            raise Deferred("Multiple existing transfers could match a bank record")
    if matches and len(matches[0]["attributes"]["transactions"]) != 1:
        raise Deferred("A synchronized group was manually split; review it before changing it")
    return matches[0] if matches else None


def plan_sync(config, snapshot, account_rows, groups, baseline=None, references=None):
    assets = {a["id"]: a["attributes"] for a in account_rows}
    targets, records = bank_records(config, snapshot)
    for b in config["bindings"]:
        a = assets.get(b["firefly_id"])
        if not a or a["currency_code"] != b["currency_code"]:
            raise Deferred("Firefly account mapping or currency is invalid")
    from bank_review import is_suspense, review_update, review_patch, review_notes_update
    operations = make_operations(config, records, assets, groups)
    references = references or {}
    delta, used_groups = {}, set()
    for operation in operations:
        existing = find_existing(operation, groups, references)
        operation["existing_id"] = existing["id"] if existing else None
        if existing and existing["id"] in used_groups:
            raise Deferred("Two bank events would reuse the same Firefly transaction")
        if existing:
            used_groups.add(existing["id"])
        desired = operation["transaction"]
        old = existing["attributes"]["transactions"][0] if existing else {}
        for account, value in effects(desired, assets).items():
            delta[account] = delta.get(account, ZERO) + value
        if existing:
            for account, value in effects(old, assets).items():
                delta[account] = delta.get(account, ZERO) - value
            # A partial update preserves all manually edited categorization and notes.
            patch = {k: desired[k] for k in ("type", "date", "currency_id", "amount",
                                            "source_id", "destination_id", "foreign_currency_id",
                                            "foreign_amount") if k in desired}
            changed = any((money(old.get(k)) != money(v) if k in {"amount", "foreign_amount"} else
                           datetime.fromisoformat(old[k]) != datetime.fromisoformat(v)
                           if k == "date" else str(old.get(k)) != str(v))
                          for k, v in patch.items())
            if review_update(old, desired):
                patch = review_patch(old, desired)
                changed = True
            elif review_notes_update(old, desired):
                if changed:
                    raise Deferred("Review alias must not change a confirmed transfer financially")
                patch = {"notes": desired["notes"]}
                changed = True
            operation["patch"] = dict(patch, transaction_journal_id=old["transaction_journal_id"])
            operation["action"] = "update" if changed else "reuse"
        else:
            operation["action"] = "create"
    first = baseline is None
    if first:
        baseline = {"start_date": config["start_date"], "accounts": {}}
        movement = {account: ZERO for account in targets}
        for record in records:
            account = record["binding"]["firefly_id"]
            movement[account] += minor(record["item"]["amount"])
        for account, closing in targets.items():
            baseline["accounts"][account] = amount(closing - movement[account])
        # External peers keep their current closing balance when known history is backfilled.
        for account, value in delta.items():
            if account not in targets and not is_suspense(assets[account]):
                baseline["accounts"][account] = amount(money(assets[account].get("opening_balance")) - value)
    if baseline["start_date"] != config["start_date"]:
        raise Deferred("Changing an established import start requires a separate migration")
    openings = []
    for account, value in baseline["accounts"].items():
        a = assets[account]
        desired, old = money(value), money(a.get("opening_balance"))
        opening_date = baseline.get("opening_dates", {}).get(account, config["start_date"])
        date = datetime.fromisoformat(opening_date).replace(tzinfo=KYIV).isoformat()
        current_date = a.get("opening_balance_date")
        if desired != old or (desired != ZERO and current_date != date):
            openings.append({"account_id": account, "value": amount(desired), "date": date})
            delta[account] = delta.get(account, ZERO) + desired - old
    if baseline.get("established") and openings:
        raise Deferred("An established opening balance was changed manually; review before synchronization")
    predicted = {account: money(a["current_balance"]) + delta.get(account, ZERO)
                 for account, a in assets.items()}
    for account, target in targets.items():
        if predicted[account] != target:
            raise Deferred("Bank and predicted ledger balances differ; no balancing transaction was invented")
    # Established baselines are immutable: later unexplained drift must be investigated.
    return {"baseline": baseline, "first": first, "openings": openings,
            "operations": operations, "targets": {k: amount(v) for k, v in targets.items()},
            "preserved_peers": {k: amount(predicted[k]) for k in delta if k not in targets},
            "source_at": snapshot["client_info"].get("as_of", snapshot["client_info"]["request_started_at"])}


def ensure_rates(firefly, plan, assets, state_dir=STATE):
    """Only add missing historical fiat/EUR rates; keep existing user rates."""
    codes = {str(a["currency_id"]): a["currency_code"] for a in assets.values()}
    required = {(assets[o["account_id"]]["currency_code"], o["date"][:10])
                for o in plan["openings"] if money(o["value"])}
    for op in plan["operations"]:
        if op["action"] == "reuse":
            continue
        for field in ("currency_id", "foreign_currency_id"):
            if op["transaction"].get(field):
                required.add((codes[str(op["transaction"][field])], op["transaction"]["date"][:10]))
    required = {(code, date) for code, date in required if code != "EUR"}
    if not required:
        return
    existing = {}
    for code in sorted({code for code, _ in required}):
        if code not in CURRENCIES.values():
            raise Deferred("Unsupported historical fiat conversion")
        existing[code] = {r["attributes"]["date"][:10]
                          for r in firefly.all(f"/exchange-rates/{code}/EUR")}
    http = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def nbu_rate(code, date):
        if code == "UAH":
            return Decimal(1)
        path = state_dir / (f"nbu-{date}.json" if code == "EUR" else f"nbu-{code}-{date}.json")
        if path.exists():
            data = read_json(path)
        else:
            query = urllib.parse.urlencode({"valcode": code, "date": date.replace("-", "")})
            url = "https://bank.gov.ua/NBUStatService/v1/statdirectory/exchangenew?" + query + "&json"
            with http.open(url, timeout=30) as response:
                data = json.load(response)
            save_json(path, data)
        if (len(data) != 1 or data[0]["cc"] != code or money(data[0]["rate"]) <= 0
                or data[0]["exchangedate"] != datetime.fromisoformat(date).strftime("%d.%m.%Y")):
            raise Deferred("NBU did not return the requested historical rate")
        return money(data[0]["rate"])

    for code, date in sorted(required):
        if date in existing[code]:
            continue
        firefly.request("POST", "/exchange-rates", {"date": date, "from": code, "to": "EUR",
                        "rate": str(nbu_rate(code, date) / nbu_rate("EUR", date))})


def load_active_state(state_dir=STATE):
    active = state_dir / "active-state.json"
    if active.exists():
        state = read_json(active)
        return state["config"], state["baseline"]
    baseline = state_dir / "baseline.json"
    return (read_json(state_dir / "config.json"),
            read_json(baseline) if baseline.exists() else None)


def checkpoint_state(config, baseline, state_dir=STATE):
    # The single authoritative document keeps the import date and absolute baseline together.
    save_json(state_dir / "active-state.json", {"config": config, "baseline": baseline})


def apply_plan(firefly, plan, state_dir=STATE, config=None):
    # Save absolute baselines before the first API mutation, allowing crash-safe resume.
    baseline_path = state_dir / "baseline.json"
    if config is not None:
        checkpoint_state(config, plan["baseline"], state_dir)
    elif not baseline_path.exists():
        save_json(baseline_path, plan["baseline"])
    refs_path = state_dir / "references.json"
    references = read_json(refs_path) if refs_path.exists() else {}
    for opening in plan["openings"]:
        account_id = opening["account_id"]
        a = firefly.request("GET", f"/accounts/{account_id}")["data"]["attributes"]
        marker = "monobank: виписки з " + plan["baseline"]["start_date"]
        note = ("\n\n" + marker + ". Початковий залишок перераховано перед імпортом рухів. "
                "Для зовнішнього рахунку використано лише підтверджені власні перекази; "
                "це не повна виписка цього зовнішнього рахунку.")
        payload = {"opening_balance": opening["value"] if money(opening["value"]) else "",
                   "opening_balance_date": opening["date"] if money(opening["value"]) else None}
        if marker not in (a.get("notes") or ""):
            payload["notes"] = (a.get("notes") or "") + note
        firefly.request("PUT", f"/accounts/{account_id}", payload)
    counts = {"create": 0, "update": 0, "reuse": 0}
    for operation in plan["operations"]:
        action = operation["action"]
        if action == "create":
            response = firefly.request("POST", "/transactions", {
                "apply_rules": False, "fire_webhooks": False, "error_if_duplicate_hash": True,
                "transactions": [operation["transaction"]]})
            group_id = response["data"]["id"]
        elif action == "update":
            group_id = operation["existing_id"]
            firefly.request("PUT", f"/transactions/{group_id}", {
                "apply_rules": False, "fire_webhooks": False, "transactions": [operation["patch"]]})
        else:
            group_id = operation["existing_id"]
        for key in operation["aliases"]:
            references[key] = group_id
        save_json(refs_path, references)
        counts[action] += 1
    checks = dict(plan["targets"], **plan["preserved_peers"])
    for account, expected in checks.items():
        actual = firefly.request("GET", f"/accounts/{account}")["data"]["attributes"]["current_balance"]
        if money(actual) != money(expected):
            raise Deferred("Post-import balance verification failed; inspect private plan before retry")
    established = dict(plan["baseline"], established=True)
    if config is not None:
        checkpoint_state(config, established, state_dir)
        # Compatibility copies are not authoritative if interrupted between these writes.
        save_json(state_dir / "config.json", config)
    save_json(baseline_path, established)
    return counts


def run(command, cached=False, state_dir=STATE, from_date=None):
    config, baseline = load_active_state(state_dir)
    previous_state = {"config": copy.deepcopy(config), "baseline": copy.deepcopy(baseline)}
    backfill = command in {"backfill-plan", "backfill"}
    if backfill:
        if not from_date or datetime.fromisoformat(from_date).date().isoformat() != from_date:
            raise Deferred("Backfill requires --from-date YYYY-MM-DD")
        if from_date > config["start_date"]:
            raise Deferred("Backfill cannot shorten the established history")
        if from_date < config["start_date"]:
            if baseline and not baseline.get("established"):
                raise Deferred("Complete the pending import before extending history again")
            config = dict(config, start_date=from_date)
            baseline = None
        snapshot = cached_snapshot(config, state_dir)
        if not cached:
            snapshot = extend_snapshot(BankClient(), config, snapshot, state_dir)
    else:
        if from_date is not None:
            raise Deferred("Use the explicit backfill command to change the import date")
        snapshot = cached_snapshot(config, state_dir) if cached else collect(BankClient(), config, state_dir)
    firefly = FireflyClient()
    accounts = firefly.all("/accounts?type=asset")
    groups = firefly.all("/transactions?type=all")
    refs_file = state_dir / "references.json"
    if baseline is None and backfill and previous_state["baseline"] is not None:
        # Extending history does not authorize silently replacing a manually changed baseline.
        plan_sync(previous_state["config"], snapshot, accounts, groups,
                  previous_state["baseline"], read_json(refs_file) if refs_file.exists() else None)
    plan = plan_sync(config, snapshot, accounts, groups, baseline,
                     read_json(refs_file) if refs_file.exists() else None)
    save_json(state_dir / "last-plan.json", plan)
    if command in {"plan", "backfill-plan"}:
        return {"accounts": len(plan["targets"]), "openings": len(plan["openings"]),
                "actions": {a: sum(o["action"] == a for o in plan["operations"])
                            for a in ("create", "update", "reuse")}}
    backup_dir = state_dir / "backups"
    backup_dir.mkdir(mode=0o700, exist_ok=True)
    save_json(backup_dir / f"before-{time.time_ns()}.json", {
        "accounts": accounts, "transactions": groups, "active_state": previous_state})
    from bank_review import prepare_suspense
    accounts = prepare_suspense(firefly, accounts, plan["operations"])
    plan = plan_sync(config, snapshot, accounts, groups, plan["baseline"],
                     read_json(refs_file) if refs_file.exists() else None)
    ensure_rates(firefly, plan, {a["id"]: a["attributes"] for a in accounts}, state_dir)
    # Re-read after rate lookup: another connector or the user may have edited the ledger.
    accounts = firefly.all("/accounts?type=asset")
    groups = firefly.all("/transactions?type=all")
    if baseline is None and backfill and previous_state["baseline"] is not None:
        plan_sync(previous_state["config"], snapshot, accounts, groups,
                  previous_state["baseline"], read_json(refs_file) if refs_file.exists() else None)
    plan = plan_sync(config, snapshot, accounts, groups, plan["baseline"],
                     read_json(refs_file) if refs_file.exists() else None)
    save_json(state_dir / "last-plan.json", plan)
    counts = apply_plan(firefly, plan, state_dir, config)
    result = {"finished_at": datetime.now(KYIV).isoformat(), "source_at": plan["source_at"],
              "accounts_verified": len(plan["targets"]), "counts": counts}
    if config.get("auto_refunds"):
        from bank_refunds import synchronize
        result["refunds"] = synchronize(firefly, state_dir.parent / "refunds")
    save_json(state_dir / "last-success.json", result)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("plan", "sync", "backfill-plan", "backfill"))
    parser.add_argument("--from-date", help="Explicitly extend history to YYYY-MM-DD")
    parser.add_argument("--cached", action="store_true", help="Use the saved coherent bank snapshot")
    args = parser.parse_args()
    with os.fdopen(_private_file(STATE / "sync.lock", os.O_RDWR | os.O_CREAT), "a+") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SystemExit("A monobank synchronization is already running") from None
        try:
            result = run(args.command, args.cached, from_date=args.from_date)
        except Exception as error:
            save_json(STATE / "last-error.json", {"at": datetime.now(KYIV).isoformat(),
                      "command": args.command, "from_date": args.from_date,
                      "type": type(error).__name__, "detail": str(error)})
            raise SystemExit("monobank: synchronization deferred; inspect private last-error.json") from None
        print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
