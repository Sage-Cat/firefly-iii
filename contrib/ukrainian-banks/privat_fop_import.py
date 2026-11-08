"""Normalize validated FOP statements without duplicating transit or FX legs."""

import argparse
import fcntl
import hashlib
import json
import os
import re
import time
from datetime import datetime
from decimal import Decimal
from pathlib import Path

from monobank_sync import Deferred, KYIV, amount, money
from privat_fop_sync import validate_snapshot
from privat_personal_import import build_plan


def bank_key(row):
    digest = hashlib.sha256((row["AUT_MY_ACC"] + ":" + str(row["ID"])).encode()).hexdigest()
    return "privat-fop:" + digest


def normalized_source(snapshot, config):
    validate_snapshot(snapshot)
    bindings = config["bindings"]
    posted = [r for r in snapshot["transactions"] if r["PR_PR"] == "r"]
    self_ids = {str(r["AUT_MY_CRF"]) for r in posted if r.get("AUT_MY_CRF")}
    self_names = {" ".join(r["AUT_MY_NAM"].casefold().split()) for r in posted if r.get("AUT_MY_NAM")}
    by_key = {bank_key(r): r for r in posted}
    ignored = set()
    transit = []
    for key, row in by_key.items():
        if row["AUT_MY_ACC"] not in bindings:
            raise Deferred("Unbound FOP bank account")
        peer = row.get("AUT_CNTR_ACC")
        if peer in bindings and bindings[peer] == bindings[row["AUT_MY_ACC"]]:
            candidates = [bank_key(other) for other in posted if
                          other["AUT_MY_ACC"] == peer and other.get("AUT_CNTR_ACC") == row["AUT_MY_ACC"]
                          and other["CCY"] == row["CCY"] and other["SUM"] == row["SUM"]
                          and other["TRANTYPE"] != row["TRANTYPE"]
                          and other["DATE_TIME_DAT_OD_TIM_P"] == row["DATE_TIME_DAT_OD_TIM_P"]
                          and other["NUM_DOC"] == row["NUM_DOC"]]
            if len(candidates) != 1:
                raise Deferred("Technical transit is not uniquely reciprocal")
            ignored.update([key, candidates[0]])
            transit.append(key)
    rows, cards = [], {}
    for account in {r["acc"] for r in snapshot["balances"]}:
        if account not in bindings:
            raise Deferred("Unbound FOP daily balance account")
        history = sorted((r for r in snapshot["balances"] if r["acc"] == account),
                         key=lambda r: datetime.strptime(r["dpd"][:10], "%d.%m.%Y"))
        cards[account] = {"account": str(bindings[account]), "currency": history[0]["currency"],
                          "start": snapshot["start_date"], "opening": history[0]["balanceIn"],
                          "closing": history[-1]["balanceOut"]}
    for key, row in by_key.items():
        if key in ignored:
            continue
        own = str(bindings[row["AUT_MY_ACC"]])
        value = money(row["SUM"]) * (-1 if row["TRANTYPE"] == "D" else 1)
        date = datetime.strptime(row["DATE_TIME_DAT_OD_TIM_P"], "%d.%m.%Y %H:%M:%S").replace(tzinfo=KYIV)
        text = row["OSND"]
        peer = row.get("AUT_CNTR_ACC")
        is_self = ("власних коштів" in text.casefold() or peer in bindings
                   or str(row.get("AUT_CNTR_CRF", "")) in self_ids
                   or " ".join(row.get("AUT_CNTR_NAM", "").casefold().split()) in self_names)
        rows.append({"A": row["DATE_TIME_DAT_OD_TIM_P"], "B": "ФОП", "C": row["AUT_MY_ACC"],
                     "D": text, "E": amount(value), "F": row["CCY"], "G": row["SUM"],
                     "H": row["CCY"], "I": "", "J": row["CCY"], "account": own,
                     "key": key, "date": date.isoformat(), "review_own": is_self})
    return rows, cards, [{"start": snapshot["start_date"], "end": snapshot["end_date"],
                          "bank_rows": len(posted), "collapsed_transit_legs": len(ignored)}]


def exchange_pairs(snapshot):
    """Pair exact bank FX amounts and rates; equivalent identical trades are fungible."""
    rows = [r for r in snapshot["transactions"] if r["PR_PR"] == "r"]
    debits = sorted((r for r in rows if r["TRANTYPE"] == "D" and "валютно-обм" in r["OSND"]), key=bank_key)
    credits = [r for r in rows if r["TRANTYPE"] == "C" and r["OSND"].startswith("Гривн") and "продажу" in r["OSND"]]
    result, used = {}, set()
    for debit in debits:
        rate = re.search(r"по курсу ([0-9.]+)", debit["OSND"])
        if not rate or money(rate[1]) <= 0:
            raise Deferred("FX rate is missing")
        expected = (money(debit["SUM"]) * Decimal(rate[1])).quantize(Decimal(".01"))
        date = datetime.strptime(debit["DATE_TIME_DAT_OD_TIM_P"], "%d.%m.%Y %H:%M:%S")
        candidates = [r for r in credits if bank_key(r) not in used and r["CCY"] == "UAH"
                      and money(r["SUM"]) == expected
                      and re.search(r"продажу " + re.escape(debit["SUM"]) + " " + re.escape(debit["CCY"]) + r" по курсу ", r["OSND"])
                      and abs((datetime.strptime(r["DATE_TIME_DAT_OD_TIM_P"], "%d.%m.%Y %H:%M:%S") - date).total_seconds()) <= 120]
        if not candidates:
            raise Deferred("FX sale has no exact currency counterpart")
        if len({(r["AUT_MY_ACC"], r["CCY"], r["SUM"], r["DATE_TIME_DAT_OD_TIM_P"], r["OSND"]) for r in candidates}) != 1:
            raise Deferred("FX sale counterpart is ambiguous")
        credit = sorted(candidates, key=bank_key)[0]
        left, right = bank_key(debit), bank_key(credit)
        result[left], result[right] = right, left
        used.add(right)
    if used != {bank_key(r) for r in credits}:
        raise Deferred("Unmatched FX proceeds")
    return result


def plan_import(snapshot, config, accounts, groups, frozen=None):
    categories, merchants = dict(config.get("categories", {})), dict(config.get("merchants", {}))
    for row in snapshot["transactions"]:
        key = bank_key(row)
        merchants.setdefault(key, row.get("AUT_CNTR_NAM") or row["OSND"])
    prepared = dict(config, pairs=exchange_pairs(snapshot), categories=categories, merchants=merchants)
    source = normalized_source(snapshot, prepared)
    plan = build_plan(prepared, accounts, groups, frozen, source_data=source)
    originals = {bank_key(r): r for r in snapshot["transactions"]}
    for op in plan["operations"]:
        tx = op["transaction"]
        tx["internal_reference"] = "privat-fop"
        tx["tags"] = ["Privat", "ФОП"]
        from bank_review import note_object
        notes = note_object(tx.get("notes"))
        evidence = {bank_key(row): row for row in notes.get("bank_records", [])}
        evidence.update({k: originals[k] for k in op["aliases"]})
        notes["bank_records"] = list(evidence.values())
        tx["notes"] = json.dumps(notes, ensure_ascii=False)
        if "patch" in op:
            op["patch"]["notes"] = tx["notes"]
    return plan


def synchronize(command, state_dir, cached=False):
    from firefly_api import Client
    from monobank_sync import ensure_rates, read_json, save_json
    from privat_fop_sync import collect
    from privat_personal_import import apply_plan

    config = read_json(state_dir / "config.json")
    frozen_file = state_dir / "frozen-plan.json"
    frozen = read_json(frozen_file) if frozen_file.exists() else None
    if cached:
        snapshot = read_json(state_dir / "snapshot.json")
    else:
        if frozen is None:
            raise Deferred("The initial FOP import must be reviewed before scheduled synchronization")
        start = min(o["date"] for o in frozen["openings"].values())
        snapshot = collect(start, state_dir=state_dir)
    client = Client()
    accounts, groups = client.all("/accounts?type=asset"), client.all("/transactions?type=all")
    plan = plan_import(snapshot, config, accounts, groups, frozen)
    save_json(state_dir / "last-plan.json", plan)
    if command == "plan":
        return {"source_rows": plan["source_rows"], "actions": {
            action: sum(o["action"] == action for o in plan["operations"]) for action in ("create", "update", "reuse")}}
    backup = state_dir / "backups"
    backup.mkdir(mode=0o700, exist_ok=True)
    save_json(backup / (str(time.time_ns()) + ".json"), {
        "accounts": accounts, "transactions": groups, "frozen": frozen})
    from bank_review import prepare_suspense
    accounts = prepare_suspense(client, accounts, plan["operations"])
    plan = plan_import(snapshot, config, accounts, groups, frozen)
    save_json(frozen_file, plan)
    ensure_rates(client, {"openings": [{"account_id": a, **o} for a, o in plan["openings"].items()],
                         "operations": plan["operations"]},
                 {a["id"]: a["attributes"] for a in accounts}, state_dir)
    plan = plan_import(snapshot, config, client.all("/accounts?type=asset"),
                       client.all("/transactions?type=all"), plan)
    counts = apply_plan(client, plan)
    repeated = plan_import(snapshot, config, client.all("/accounts?type=asset"),
                           client.all("/transactions?type=all"), plan)
    if any(o["action"] != "reuse" for o in repeated["operations"]):
        raise Deferred("FOP repeat was not idempotent")
    result = {"finished_at": datetime.now(KYIV).isoformat(), "counts": counts,
              "targets": plan["targets"], "repeat_creates": 0}
    save_json(state_dir / "last-success.json", result)
    return result


def main():
    from monobank_api import _private_file
    from monobank_sync import save_json

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["plan", "sync"])
    parser.add_argument("--cached", action="store_true")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent
    state = root / ".state/privat-fop/full-history"
    with os.fdopen(_private_file(root / ".state/monobank/sync.lock", os.O_RDWR | os.O_CREAT), "a+") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SystemExit("A bank synchronization is already running") from None
        try:
            result = synchronize(args.command, state, args.cached)
        except Exception as error:
            save_json(state / "last-error.json", {"at": datetime.now(KYIV).isoformat(),
                      "type": type(error).__name__, "detail": str(error)})
            raise SystemExit("Privat FOP synchronization deferred; inspect private last-error.json") from None
        print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
