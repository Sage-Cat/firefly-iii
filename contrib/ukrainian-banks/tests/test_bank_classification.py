"""Synthetic confirmed bank metadata rules; no credentials or network access."""

import copy
import json
import stat
import tempfile
import unittest
from pathlib import Path

import bank_classification as classification
from monobank_sync import save_json


def config():
    return {"version": 1, "merchant_rules": [
        {"prefix": "Близенько", "category": "Продукти"},
        {"prefix": "Glovo", "category": "Доставка їжі"},
        {"prefix": "Uklon", "category": "Таксі"}], "income_rules": [
        {"destination_id": "11", "category": "Інші доходи", "tag": "Кешбек"},
        {"description_prefix": "Кешбек", "category": "Інші доходи", "tag": "Кешбек"}]}


def journal(identifier="1", kind="withdrawal", **extra):
    return dict({"transaction_journal_id": identifier, "type": kind,
                 "amount": "123.45", "date": "2026-10-02T12:00:00+03:00", "currency_id": "4",
                 "currency_code": "UAH", "foreign_currency_id": "1", "foreign_currency_code": "EUR",
                 "foreign_amount": "2.47", "source_id": "10" if kind == "withdrawal" else "900",
                 "destination_id": "901" if kind == "withdrawal" else "10", "source_name": "Counterparty",
                 "destination_name": "Counterparty", "description": "Glovo order 42",
                 "external_id": "monobank:synthetic-" + identifier, "internal_reference": "monobank",
                 "category_id": None, "category_name": None, "tags": ["monobank", "Owner tag"],
                 "notes": '{"proof":"Original bank evidence"}', "budget_id": "8", "bill_id": "9"}, **extra)


def group(row):
    return {"id": row["transaction_journal_id"], "attributes": {"transactions": [row]}}


class Client:
    def __init__(self, journals=None):
        self.groups = [group(copy.deepcopy(row)) for row in (journals if journals is not None else [journal()])]
        self.accounts = [{"id": i, "attributes": {"currency_id": "4", "currency_code": "UAH"}}
                         for i in ("10", "11")]
        self.categories, self.tags, self.links = [], [], []
        self.link_types = [{"id": "1", "attributes": {"name": "Refund"}}]
        self.calls = []
        self.get_hook = self.before_put = self.after_put = self.metadata_hook = None

    def all(self, path):
        return copy.deepcopy({"/accounts?type=asset": self.accounts, "/transactions?type=all": self.groups,
                              "/categories": self.categories, "/tags": self.tags,
                              "/link-types": self.link_types, "/transaction-links": self.links}[path])

    def request(self, method, path, data=None, *, category_match=None, tags_hash=None, category_default=False, payment_hash=None):
        self.calls.append((method, path, copy.deepcopy(data), category_match, tags_hash))
        if method == "POST":
            assert path in {"/categories", "/tags"}
            values = self.categories if path == "/categories" else self.tags
            row = {"id": str(100 + len(values)), "attributes": copy.deepcopy(data)}
            values.append(row)
            if self.metadata_hook:
                self.metadata_hook(self)
            return {"data": copy.deepcopy(row)}
        row = next(g for g in self.groups if g["id"] == path.rsplit("/", 1)[1])
        if method == "GET":
            if self.get_hook:
                self.get_hook(row)
            return {"data": copy.deepcopy(row)}
        assert method == "PUT" and data["apply_rules"] is False and data["fire_webhooks"] is False
        assert len(data["transactions"]) == 1
        patch = data["transactions"][0]
        current = next(j for j in row["attributes"]["transactions"]
                       if j["transaction_journal_id"] == patch["transaction_journal_id"])
        if self.before_put:
            self.before_put(current)
        if payment_hash is not None:
            from firefly_api import payment_fingerprint
            try:
                actual_hash = payment_fingerprint(row['id'], current)
            except ValueError:
                actual_hash = None
            if actual_hash != payment_hash:
                raise RuntimeError('HTTP 409: Payment changed since it was read')
        if "category_id" in patch:
            assert set(patch) == {"transaction_journal_id", "category_id"}
            if (str(current["category_id"]) if current.get("category_id") else "none") != category_match:
                raise RuntimeError("HTTP 409: Category changed since it was read")
            from category_fallback import CATEGORY, MARKER, pending_default, unclassified
            target = next(c["attributes"]["name"] for c in self.categories if c["id"] == patch["category_id"])
            if category_default:
                assert target == CATEGORY
                if not unclassified(current):
                    raise RuntimeError("HTTP 409: Payment is already classified")
                if MARKER not in current["tags"]:
                    current["tags"].append(MARKER)
            elif current.get("category_name") == CATEGORY and not pending_default(current):
                raise RuntimeError("HTTP 409: Payment was explicitly confirmed")
            elif pending_default(current):
                current["tags"] = [tag for tag in current["tags"] if tag != MARKER]
            current["category_id"] = patch["category_id"]
            current["category_name"] = next(c["attributes"]["name"] for c in self.categories
                                            if c["id"] == patch["category_id"])
        else:
            assert set(patch) == {"transaction_journal_id", "tags"}
            from firefly_api import tag_fingerprint
            if tag_fingerprint(current.get("tags") or []) != tags_hash:
                raise RuntimeError("HTTP 409: Tags changed since they were read")
            if (str(current["category_id"]) if current.get("category_id") else "none") != category_match:
                raise RuntimeError("HTTP 409: Category changed since it was read")
            assert set(current.get("tags") or []).issubset(patch["tags"])
            current["tags"] = patch["tags"]
        if self.after_put:
            self.after_put(current)
        return {"data": copy.deepcopy(row)}


class ClassificationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name)
        save_json(self.state / "config.json", config())

    def plan(self, client, rules=None):
        return classification.plan(rules or config(), **classification.snapshot(client))

    def test_merchant_prefixes_are_casefolded_at_the_start_and_word_boundary(self):
        for text, category in [("  БЛИЗЕНЬКО магазин", "Продукти"), ("gLoVo. Order", "Доставка їжі"),
                               ("UKLON*trip", "Таксі"), ("Glovo", "Доставка їжі")]:
            with self.subTest(text=text):
                result = self.plan(Client([journal(description=text)]))
                self.assertEqual(category, result["updates"][0]["category"])
        for text in ["OTHERGLOVO", "Glovox", "UKLONCITY", "Before Glovo", "Glovo_merchant", "Близенького"]:
            with self.subTest(text=text):
                self.assertEqual([], self.plan(Client([journal(description=text)]))["updates"])

    def test_known_merchant_name_matches_even_with_a_bank_description(self):
        client = Client([journal(description="Bank payment 123", destination_name="Glovo*order")])
        self.assertEqual("Доставка їжі", self.plan(client)["updates"][0]["category"])

    def test_tagged_merchant_rule_sets_category_and_tag_or_only_the_missing_tag(self):
        rules = config()
        rules["merchant_rules"].append({"prefix": "Payee card", "category": "Family", "tag": "Payee"})
        client = Client([journal(description="Payee card"),
                         journal("2", description="Payee card", category_id="5", category_name="Family")])
        client.categories = [{"id": "5", "attributes": {"name": "Family"}}]
        result = self.plan(client, rules)
        self.assertEqual([("1", "Family", ["Payee"], True), ("2", "Family", ["Payee"], False)],
                         [(u["journal_id"], u["category"], u["tags"], u["category_change"]) for u in result["updates"]])

    def test_unknowns_and_merchant_deposits_are_untouched(self):
        for row in [journal(description="Unknown merchant"), journal(kind="deposit", description="Glovo")]:
            self.assertEqual([], self.plan(Client([row]))["updates"])

    def test_cashback_destination_or_explicit_prefix_only_classifies_deposits(self):
        for row in [journal(kind="deposit", destination_id="11", description="Bank incoming payment"),
                    journal(kind="deposit", description="КЕШБЕК. Виплата")]:
            target = self.plan(Client([row]))["updates"][0]
            self.assertEqual(("Інші доходи", ["Кешбек"]), (target["category"], target["tags"]))
        for row in [journal(kind="deposit", description="Other кешбек"),
                    journal(kind="deposit", description="Кешбековий платіж"),
                    journal(description="Кешбек. Виплата")]:
            self.assertEqual([], self.plan(Client([row]))["updates"])

    def test_cashback_uses_actual_bank_net_amount_without_tax_or_gross_movements(self):
        client = Client([journal(kind="deposit", amount="77.00",
                                description="Кешбек 100.00; утриманий податок 23.00")])
        before = copy.deepcopy(client.groups)
        result = classification.synchronize(client, self.state)
        self.assertEqual((1, 1, 1), (result["classified"], result["tagged"], len(client.groups)))
        old, actual = before[0]["attributes"]["transactions"][0], client.groups[0]["attributes"]["transactions"][0]
        self.assertEqual("77.00", actual["amount"])
        self.assertEqual("Інші доходи", actual["category_name"])
        self.assertEqual(["monobank", "Owner tag", "Кешбек"], actual["tags"])
        for field in classification.PRESERVED_FIELDS:
            self.assertEqual(old.get(field), actual.get(field), field)
        self.assertEqual(1, len(list(self.state.glob("before-*.json"))))

    def test_transfers_openings_holds_refunds_and_unvalidated_movements_are_excluded(self):
        cases = [{"type": "transfer"}, {"type": "opening balance"}, {"hold": True},
                 {"notes": '{"hold":true}'}, {"notes": '{"refund":true}'},
                 {"source_id": "10", "destination_id": "11"},
                 {"internal_reference": "other"}, {"external_id": "other:synthetic"},
                 {"source_id": None}, {"amount": "0"}, {"amount": "NaN"},
                 {"currency_id": "99"}, {"date": "2026-10-02"},
                 {"category_id": "8", "category_name": "Повернення коштів"},
                 {"description": "Скасування. Glovo", "destination_name": "Glovo"},
                 {"description": "Refund Glovo", "destination_name": "Glovo"},
                 {"description": "Повернення. Glovo", "destination_name": "Glovo"},
                 {"description": "Glovo між власними рахунками"},
                 {"notes": json.dumps({"bank_review": {"kind": "own_transfer", "status": "pending"}})}]
        for extra in cases:
            with self.subTest(extra=extra):
                self.assertEqual([], self.plan(Client([journal(**extra)]))["updates"])
        client = Client([journal(kind="deposit", destination_id="11", description="Скасування. Glovo")])
        self.assertEqual([], self.plan(client)["updates"])

    def test_native_refund_link_has_precedence_for_both_journals(self):
        client = Client([journal(), journal("2", kind="deposit", destination_id="11")])
        client.links = [{"id": "1", "attributes": {"inward_id": "1", "outward_id": "2", "link_type_id": "1"}}]
        self.assertEqual([], self.plan(client)["updates"])

    def test_all_supported_bank_namespaces_use_the_same_rules(self):
        for provider in classification.PROVIDERS:
            row = journal(internal_reference=provider, external_id=provider + ":synthetic")
            self.assertEqual(1, len(self.plan(Client([row]))["updates"]))

    def test_manual_category_is_preserved_and_reported_as_a_conflict(self):
        client = Client([journal(kind="deposit", description="Кешбек", category_id="5", category_name="Owner choice")])
        result = classification.synchronize(client, self.state)
        self.assertEqual((0, 0, 1), (result["classified"], result["tagged"], result["category_conflicts"]))
        self.assertFalse(client.calls)
        self.assertFalse(client.categories)
        self.assertFalse(client.tags)

    def test_legacy_category_can_be_replaced_but_unknown_category_id_is_preserved(self):
        client = Client([journal(category_id="6", category_name=classification.LEGACY_CATEGORY)])
        result = classification.synchronize(client, self.state)
        self.assertEqual(1, result["classified"])
        self.assertEqual("Доставка їжі", client.groups[0]["attributes"]["transactions"][0]["category_name"])
        self.assertEqual(1, self.plan(Client([journal(category_id="6")]))["category_conflicts"])

    def test_repeated_sync_has_no_metadata_writes(self):
        client = Client([journal(kind="deposit", description="Кешбек")])
        classification.synchronize(client, self.state)
        client.calls.clear()
        result = classification.synchronize(client, self.state)
        self.assertEqual((0, 0, 0, 0), tuple(result[key] for key in (
            "classified", "tagged", "categories_created", "tags_created")))
        self.assertFalse(client.calls)
        self.assertEqual((1, 1), (len(client.categories), len(client.tags)))

    def test_existing_target_category_can_gain_only_the_missing_tag(self):
        client = Client([journal(kind="deposit", description="Кешбек", category_id="5", category_name="Інші доходи")])
        client.categories = [{"id": "5", "attributes": {"name": "Інші доходи"}}]
        result = classification.synchronize(client, self.state)
        self.assertEqual((0, 1), (result["classified"], result["tagged"]))
        puts = [c for c in client.calls if c[0] == "PUT"]
        self.assertEqual({"transaction_journal_id", "tags"}, set(puts[0][2]["transactions"][0]))

    def test_fresh_tag_merge_preserves_owner_additions_while_metadata_is_created(self):
        client = Client([journal(kind="deposit", description="Кешбек")])
        def add_owner_tag(fake):
            tags = fake.groups[0]["attributes"]["transactions"][0]["tags"]
            if "Fresh owner tag" not in tags:
                tags.append("Fresh owner tag")
        client.metadata_hook = add_owner_tag
        result = classification.synchronize(client, self.state)
        self.assertEqual(1, result["tagged"])
        self.assertEqual(["monobank", "Owner tag", "Fresh owner tag", "Кешбек"],
                         client.groups[0]["attributes"]["transactions"][0]["tags"])

    def test_category_cas_race_never_overwrites_owner_choice_or_adds_tags(self):
        client = Client([journal(kind="deposit", description="Кешбек")])
        client.before_put = lambda row: row.update(category_id="7", category_name="Owner choice")
        result = classification.synchronize(client, self.state)
        self.assertEqual((0, 0, 1), (result["classified"], result["tagged"], result["stale_skipped"]))
        self.assertEqual(1, result["category_conflicts"])
        self.assertEqual("7", client.groups[0]["attributes"]["transactions"][0]["category_id"])
        self.assertFalse(client.tags)

    def test_tag_cas_preserves_an_owner_addition_immediately_before_put(self):
        client = Client([journal(kind="deposit", description="Кешбек")])
        before = copy.deepcopy(client.groups)
        def owner_race(row):
            if row.get("category_id") and "Fresh owner tag" not in row["tags"]:
                row["tags"].append("Fresh owner tag")
        client.before_put = owner_race
        result = classification.synchronize(client, self.state)
        self.assertEqual((1, 0, 1), (result["classified"], result["tagged"], result["stale_skipped"]))
        actual = client.groups[0]["attributes"]["transactions"][0]
        self.assertEqual(["monobank", "Owner tag", "Fresh owner tag"], actual["tags"])
        for field in classification.PRESERVED_FIELDS:
            self.assertEqual(before[0]["attributes"]["transactions"][0].get(field), actual.get(field))
        client.before_put = None
        repeated = classification.synchronize(client, self.state)
        self.assertEqual((0, 1), (repeated["classified"], repeated["tagged"]))
        self.assertEqual(["monobank", "Owner tag", "Fresh owner tag", "Кешбек"], actual["tags"])

    def test_tag_cas_does_not_suppress_other_failures_or_failed_readback(self):
        for failure in ("server", "financial"):
            with self.subTest(failure=failure):
                client = Client([journal(kind="deposit", description="Кешбек", category_id="5", category_name="Інші доходи")])
                client.categories = [{"id": "5", "attributes": {"name": "Інші доходи"}}]
                if failure == "server":
                    def error(row):
                        raise RuntimeError("HTTP 500: Synthetic server error")
                    client.before_put = error
                else:
                    client.after_put = lambda row: row.update(amount="999")
                with self.assertRaises(RuntimeError):
                    classification.synchronize(client, self.state)

    def test_tag_cas_checks_category_again_when_owner_changes_it_before_put(self):
        client = Client([journal(kind="deposit", description="Кешбек", category_id="5", category_name="Інші доходи")])
        client.categories = [{"id": "5", "attributes": {"name": "Інші доходи"}}]
        client.before_put = lambda row: row.update(category_id="7", category_name="Owner choice")
        result = classification.synchronize(client, self.state)
        self.assertEqual((0, 0, 1, 1), (result["classified"], result["tagged"], result["stale_skipped"], result["category_conflicts"]))
        actual = client.groups[0]["attributes"]["transactions"][0]
        self.assertEqual("7", actual["category_id"])
        self.assertEqual(["monobank", "Owner tag"], actual["tags"])

    def test_owner_edit_after_plan_is_skipped(self):
        client = Client()
        client.get_hook = lambda row: row["attributes"]["transactions"][0].update(category_id="7", category_name="Owner choice")
        result = classification.synchronize(client, self.state)
        self.assertEqual((0, 1), (result["classified"], result["stale_skipped"]))
        self.assertFalse([c for c in client.calls if c[0] != "GET"])

    def test_financial_or_evidence_changes_fail_readback(self):
        for extra in [{"amount": "999.99"}, {"notes": "changed"}, {"description": "changed"}, {"budget_id": "99"}]:
            with self.subTest(extra=extra):
                client = Client()
                client.after_put = lambda row: row.update(extra)
                with self.assertRaises(classification.Deferred):
                    classification.synchronize(client, self.state)

    def test_category_and_tags_are_always_separate_metadata_only_writes(self):
        client = Client([journal(kind="deposit", description="Кешбек")])
        classification.synchronize(client, self.state)
        puts = [c for c in client.calls if c[0] == "PUT"]
        self.assertEqual(2, len(puts))
        self.assertEqual("none", puts[0][3])
        self.assertEqual("100", puts[1][3])
        self.assertIsNone(puts[0][4])
        from firefly_api import tag_fingerprint
        self.assertEqual(tag_fingerprint(["monobank", "Owner tag"]), puts[1][4])
        self.assertEqual([{ "transaction_journal_id", "category_id"}, {"transaction_journal_id", "tags"}],
                         [set(c[2]["transactions"][0]) for c in puts])

    def test_split_group_updates_only_target_journal(self):
        client = Client()
        other = journal("2", description="Unknown merchant")
        client.groups[0]["attributes"]["transactions"].append(other)
        classification.synchronize(client, self.state)
        self.assertEqual(other, client.groups[0]["attributes"]["transactions"][1])

    def test_dry_run_and_unknowns_never_create_metadata(self):
        client = Client([journal(kind="deposit", description="Кешбек")])
        result = classification.synchronize(client, self.state, dry_run=True)
        self.assertEqual((1, 1), (result["planned_classifications"], result["planned_tags"]))
        self.assertFalse(client.calls)
        self.assertFalse(list(self.state.glob("before-*.json")))
        classification.synchronize(Client([journal(description="Unknown merchant")]), self.state)
        self.assertFalse(list(self.state.glob("before-*.json")))

    def test_duplicate_metadata_names_or_journals_fail_before_any_writes(self):
        for field, path, name in [("name", "categories", "Доставка їжі"), ("tag", "tags", "Кешбек")]:
            with self.subTest(field=field):
                client = Client([journal(kind="deposit", description="Кешбек")] if field == "tag" else None)
                setattr(client, path, [{"id": "1", "attributes": {field: name}},
                                       {"id": "2", "attributes": {field: name}}])
                with self.assertRaises(classification.Deferred):
                    classification.synchronize(client, self.state)
                self.assertFalse(client.calls)
        client = Client([journal(), journal()])
        with self.assertRaises(classification.Deferred):
            self.plan(client)

    def test_ambiguous_confirmed_rules_remain_untouched(self):
        rules = config()
        rules["merchant_rules"].append({"prefix": "Glovo", "category": "Other category"})
        result = self.plan(Client(), rules)
        self.assertEqual(([], 1), (result["updates"], result["ambiguous_rules"]))

    def test_invalid_unowned_or_overbroad_rules_fail_closed(self):
        for rule in [{"destination_id": "900", "category": "Інші доходи"},
                     {"destination_id": "11", "description_prefix": "Кешбек", "category": "Інші доходи"},
                     {"description_prefix": "", "category": "Інші доходи"},
                     {"description_prefix": "Кешбек", "category": "Інші доходи", "tag": ""}]:
            with self.subTest(rule=rule):
                with self.assertRaises(classification.Deferred):
                    self.plan(Client(), {"version": 1, "income_rules": [rule]})
        for rule in [{"prefix": "Glovo", "category": "Доставка їжі", "tag": ""},
                     {"prefix": "Glovo", "category": "Доставка їжі", "tag": " Padded"},
                     {"prefix": "Glovo", "category": "Доставка їжі", "destination_id": "11"}]:
            with self.subTest(rule=rule):
                with self.assertRaises(classification.Deferred):
                    self.plan(Client(), {"version": 1, "merchant_rules": [rule]})

    def test_private_state_and_snapshots_are_required(self):
        client = Client()
        classification.synchronize(client, self.state)
        self.assertTrue(all(stat.S_IMODE(p.stat().st_mode) == 0o600 for p in self.state.iterdir()))
        self.state.chmod(0o755)
        with self.assertRaises(PermissionError):
            classification.synchronize(client, self.state)


if __name__ == "__main__":
    unittest.main()
