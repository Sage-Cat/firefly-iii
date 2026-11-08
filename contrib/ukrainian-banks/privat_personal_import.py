"""Reconcile and import PrivatBank's personal XLSX statements into Firefly III."""

import argparse
import hashlib
import json
import re
from collections import Counter, defaultdict
from datetime import datetime
from decimal import Decimal
from pathlib import Path
from xml.etree import ElementTree as ET
from zipfile import ZipFile

from monobank_sync import Deferred, KYIV, amount, effects, money, save_json

NS = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
HEADERS = ["Дата", "Категорія", "Картка", "Опис операції", "Сума в валюті картки",
           "Валюта картки", "Сума в валюті транзакції", "Валюта транзакції",
           "Залишок на кінець періоду", "Валюта залишку"]


def read_statement(path):
    with ZipFile(path) as archive:
        if sum(i.file_size for i in archive.infolist()) > 64 * 1024 * 1024:
            raise Deferred("Statement exceeds the supported size")
        shared = []
        if "xl/sharedStrings.xml" in archive.namelist():
            shared = ["".join(t.itertext()) for t in ET.fromstring(
                archive.read("xl/sharedStrings.xml")).findall("s:si", NS)]
        sheets = [n for n in archive.namelist() if re.fullmatch(r"xl/worksheets/sheet\d+\.xml", n)]
        if len(sheets) != 1:
            raise Deferred("Expected one statement worksheet")
        rows = []
        for row in ET.fromstring(archive.read(sheets[0])).findall("s:sheetData/s:row", NS):
            values = {}
            for cell in row:
                if cell.find("s:f", NS) is not None:
                    raise Deferred("Bank statement contains an unexpected formula")
                v = cell.find("s:v", NS)
                value = v.text if v is not None else "".join(cell.itertext())
                if cell.get("t") == "s":
                    value = shared[int(value)]
                values[re.sub(r"\d", "", cell.attrib["r"])] = value
            rows.append(values)
    if len(rows) < 2 or [rows[1].get(c) for c in "ABCDEFGHIJ"] != HEADERS:
        raise Deferred("Unrecognized statement columns")
    period = re.search(r"(\d{2}\.\d{2}\.\d{4}) - (\d{2}\.\d{2}\.\d{4})", rows[0].get("A", ""))
    if not period:
        raise Deferred("Statement period is missing")
    start, end = [datetime.strptime(v, "%d.%m.%Y").date() for v in period.groups()]
    result, occurrences = [], Counter()
    for row in rows[2:]:
        if set(row) != set("ABCDEFGHIJ"):
            raise Deferred("Incomplete statement row")
        date = datetime.strptime(row["A"], "%d.%m.%Y %H:%M:%S").replace(tzinfo=KYIV)
        if not start <= date.date() <= end:
            raise Deferred("Transaction outside statement coverage")
        for field in "EGI":
            value = Decimal(row[field])
            if not value.is_finite() or value != value.quantize(Decimal(".01")):
                raise Deferred("Invalid monetary value")
        if not Decimal(row["E"]) or row["F"] != row["J"]:
            raise Deferred("Unexpected zero movement or balance currency")
        digest = hashlib.sha256(json.dumps(row, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
        occurrences[digest] += 1
        result.append(dict(row, date=date.isoformat(), key=f"privat-personal:{digest}:{occurrences[digest]}"))
    for newer, older in zip(result, result[1:]):
        if (newer["C"] != older["C"] or newer["F"] != older["F"]
                or newer["date"] < older["date"]
                or money(newer["I"]) - money(newer["E"]) != money(older["I"])):
            raise Deferred("Statement balance chain does not reconcile")
    return {"start": start.isoformat(), "end": end.isoformat(), "rows": result}


def collect_sources(config):
    rows, cards, coverage = {}, {}, []
    for source in config["sources"]:
        statement = read_statement(source["path"])
        if not statement["rows"]:
            raise Deferred("Empty statement needs an independently confirmed balance")
        first, last = statement["rows"][-1], statement["rows"][0]
        card = first["C"]
        if card[-4:] != source["card_suffix"]:
            raise Deferred("Statement card does not match the configured account")
        binding = {"account": str(source["account_id"]), "currency": first["F"],
                   "start": statement["start"], "opening": amount(money(first["I"]) - money(first["E"])),
                   "closing": amount(money(last["I"]))}
        if card in cards and cards[card] != binding:
            raise Deferred("Overlapping statements disagree; reconcile before importing")
        cards[card] = binding
        for row in statement["rows"]:
            if row["C"] != card or row["F"] != binding["currency"]:
                raise Deferred("Mixed statement accounts are not supported")
            rows[row["key"]] = dict(row, account=binding["account"])
        coverage.append({"start": statement["start"], "end": statement["end"], "rows": len(statement["rows"])})
    return sorted(rows.values(), key=lambda r: (r["date"], r["key"])), cards, coverage


def operations(config, rows, assets, groups=None):
    from bank_review import apply_review
    groups = groups or []
    review_reservations = set()
    by_key = {r["key"]: r for r in rows}
    for row in rows:
        if row["account"] not in assets or assets[row["account"]]["currency_code"] != row["F"]:
            raise Deferred("Invalid account currency mapping")
    pairs, peers, seen, output = config.get("pairs", {}), config.get("peers", {}), set(), []
    if any(pairs.get(right) != left for left, right in pairs.items()):
        raise Deferred("Transfer pairing must be reciprocal")
    for row in rows:
        key = row["key"]
        if key in seen:
            continue
        own, value = row["account"], money(row["E"])
        if own not in assets or assets[own]["currency_code"] != row["F"]:
            raise Deferred("Invalid account currency mapping")
        tx = {"date": row["date"], "currency_id": assets[own]["currency_id"],
              "amount": amount(abs(value)), "description": row["D"][:1000],
              "external_id": key, "internal_reference": "privat-personal",
              "tags": ["Privat", "Особисте"],
              "notes": json.dumps({"card": row["C"], "bank_category": row["B"],
                                   "transaction_amount": row["G"], "transaction_currency": row["H"],
                                   "balance": row["I"]}, ensure_ascii=False)}
        aliases = [key]
        peer = str(peers[key]) if key in peers else None
        suspected = bool(row.get("review_own"))
        if key in pairs:
            other = by_key.get(pairs[key])
            if (not other or money(other["E"]) * value >= 0 or other["account"] == own
                    or abs((datetime.fromisoformat(row["date"]) - datetime.fromisoformat(other["date"])).total_seconds()) > 600):
                raise Deferred("Paired own transfer is inconsistent")
            if value > 0:
                continue
            peer = other["account"]
            if row["F"] == other["F"]:
                if value != -money(other["E"]):
                    raise Deferred("Own transfer amounts differ")
            else:
                tx.update(foreign_currency_id=assets[peer]["currency_id"], foreign_amount=amount(money(other["E"])))
            aliases.append(other["key"])
        if peer:
            if peer not in assets or peer == own:
                raise Deferred("Invalid transfer peer")
            if key not in pairs and assets[peer]["currency_code"] != row["F"]:
                raise Deferred("Cross-currency transfer requires its other bank leg")
            tx.update(type="transfer", source_id=peer if value > 0 else own,
                      destination_id=own if value > 0 else peer)
        else:
            if row["B"] in {"Зарахування зі своєї картки", "Переказ на свою картку"} or "власних коштів" in row["D"].casefold():
                suspected = True
            tx["type"] = "deposit" if value > 0 else "withdrawal"
            tx["destination_id" if value > 0 else "source_id"] = own
            tx["source_name" if value > 0 else "destination_name"] = config.get("merchants", {}).get(key, row["D"].split(". Коментар:")[0])[:255]
            category = config.get("categories", {}).get(key)
            if category and category != "Потребує уточнення":
                tx["category_name"] = category
            if row["H"] != row["F"]:
                foreign = config["currencies"].get(row["H"])
                if not foreign or money(row["G"]) <= 0:
                    raise Deferred("Foreign transaction currency is not configured")
                tx.update(foreign_currency_id=foreign, foreign_amount=amount(money(row["G"])))
        tx = apply_review(tx, own, "in" if value > 0 else "out", row["F"], assets, groups, suspected and not peer,
                          config.get("merchants", {}).get(key, row["D"].split(". Коментар:")[0]),
                          reserved=review_reservations)
        output.append({"key": key, "aliases": aliases, "transaction": tx})
        seen.update(aliases)
    if seen != set(by_key):
        raise Deferred("Some source records were not represented")
    return output


def build_plan(config, accounts, groups, frozen=None, source_data=None):
    assets = {a["id"]: a["attributes"] for a in accounts}
    rows, cards, coverage = source_data if source_data is not None else collect_sources(config)
    from bank_review import is_suspense, review_update, review_patch, review_notes_update, journal_keys
    ops = operations(config, rows, assets, groups)
    known, targets, dates = defaultdict(Decimal), defaultdict(Decimal), {}
    for binding in cards.values():
        own = binding["account"]
        if own not in assets or assets[own]["currency_code"] != binding["currency"]:
            raise Deferred("Invalid source balance currency mapping")
        known[own] += money(binding["opening"])
        targets[own] += money(binding["closing"])
        dates[own] = min(dates.get(own, binding["start"]), binding["start"])
    delta, used = defaultdict(Decimal), set()
    for op in ops:
        tx = op["transaction"]
        matches = [g for g in groups if any(journal_keys(j).intersection(op["aliases"])
                   for j in g["attributes"]["transactions"])]
        if not matches and tx["type"] == "transfer":
            for group in groups:
                js = group["attributes"]["transactions"]
                if len(js) != 1:
                    continue
                j = js[0]
                if (j["type"] == "transfer" and all(str(j.get(k)) == str(tx.get(k)) for k in ("source_id", "destination_id", "currency_id"))
                        and money(j["amount"]) == money(tx["amount"])
                        and str(j.get("foreign_currency_id")) == str(tx.get("foreign_currency_id"))
                        and (not tx.get("foreign_currency_id") or money(j["foreign_amount"]) == money(tx["foreign_amount"]))
                        and abs((datetime.fromisoformat(j["date"]) - datetime.fromisoformat(tx["date"])).total_seconds()) <= 600):
                    matches.append(group)
        if len(matches) > 1 or matches and matches[0]["id"] in used:
            raise Deferred("Ambiguous or duplicate existing transaction")
        if matches:
            group = matches[0]
            if len(group["attributes"]["transactions"]) != 1:
                raise Deferred("Imported transaction was manually split")
            j = group["attributes"]["transactions"][0]
            changed = j["type"] != tx["type"] or effects(j, assets) != effects(tx, assets)
            approved = review_update(j, tx)
            metadata = review_notes_update(j, tx)
            if changed and not approved:
                raise Deferred("Existing imported transaction changed financially")
            if metadata:
                if changed:
                    raise Deferred("Review alias must not change a confirmed transfer financially")
                op["patch"] = {"notes": tx["notes"], "transaction_journal_id": j["transaction_journal_id"]}
            if approved:
                for account, value in effects(tx, assets).items():
                    delta[account] += value
                for account, value in effects(j, assets).items():
                    delta[account] -= value
                op["patch"] = review_patch(j, tx)
            op["existing_id"] = group["id"]
            used.add(group["id"])
        else:
            for account, value in effects(tx, assets).items():
                delta[account] += value
        op["action"] = "update" if matches and "patch" in op else "reuse" if matches else "create"
    openings = {a: {"value": amount(v), "date": dates[a]} for a, v in known.items()}
    peers = {}
    for account, change in delta.items():
        if account not in targets:
            if is_suspense(assets[account]):
                peers[account] = amount(money(assets[account]["current_balance"]) + change)
            else:
                peers[account] = assets[account]["current_balance"]
                openings[account] = {"value": amount(money(assets[account].get("opening_balance")) - change),
                                     "date": min(dates.values())}
    if frozen:
        openings = frozen["openings"]
        peers = {}
        for account in set(delta) | set(frozen["preserved_peers"]):
            if account in targets:
                continue
            opening_delta = (money(openings[account]["value"]) - money(assets[account].get("opening_balance"))
                             if account in openings else Decimal(0))
            peers[account] = amount(money(assets[account]["current_balance"]) + delta[account] + opening_delta)
    for account, opening in openings.items():
        if money(opening["value"]) < 0:
            raise Deferred("Import would invent a negative opening balance")
        delta[account] += money(opening["value"]) - money(assets[account].get("opening_balance"))
    checks = dict(peers, **{a: amount(v) for a, v in targets.items()})
    for account, target in checks.items():
        if money(assets[account]["current_balance"]) + delta[account] != money(target):
            raise Deferred("Projected closing balance does not reconcile")
    return {"openings": openings, "operations": ops, "targets": {a: amount(v) for a, v in targets.items()},
            "preserved_peers": peers, "coverage": coverage, "source_rows": len(rows)}


def apply_plan(client, plan):
    for account, opening in plan["openings"].items():
        current = client.request("GET", f"/accounts/{account}")["data"]["attributes"]
        date = datetime.fromisoformat(opening["date"]).replace(tzinfo=KYIV).isoformat()
        if money(current.get("opening_balance")) == money(opening["value"]) and (
                not money(opening["value"]) or current.get("opening_balance_date") == date):
            continue
        client.request("PUT", f"/accounts/{account}", {
            "opening_balance": opening["value"] if money(opening["value"]) else "",
            "opening_balance_date": date if money(opening["value"]) else None})
    counts = Counter()
    for op in plan["operations"]:
        if op["action"] == "create":
            client.request("POST", "/transactions", {"apply_rules": False, "fire_webhooks": False,
                           "error_if_duplicate_hash": True, "transactions": [op["transaction"]]})
        elif op["action"] == "update":
            client.request("PUT", f"/transactions/{op['existing_id']}", {"apply_rules": False,
                           "fire_webhooks": False, "transactions": [op["patch"]]})
        counts[op["action"]] += 1
    for account, target in dict(plan["preserved_peers"], **plan["targets"]).items():
        actual = client.request("GET", f"/accounts/{account}")["data"]["attributes"]["current_balance"]
        if money(actual) != money(target):
            raise Deferred("Closing balance verification failed")
    return dict(counts)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["plan"])
    parser.add_argument("config", type=Path)
    args = parser.parse_args()
    from firefly_api import Client
    client = Client()
    plan = build_plan(json.loads(args.config.read_text()), client.all("/accounts?type=asset"),
                      client.all("/transactions?type=all"))
    save_json(args.config.parent / "plan.json", plan)
    print(json.dumps({"source_rows": plan["source_rows"], "actions": dict(Counter(o["action"] for o in plan["operations"]))}))


if __name__ == "__main__":
    main()
