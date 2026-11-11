"""Synthetic exact-category learning and native ownership rules; no external accounts."""

import copy
import json
import html
import tempfile
import unittest
from pathlib import Path

import bank_classification as classification
import category_learning as learning
from category_fallback import MARKER
from monobank_sync import save_json
from test_bank_classification import Client as LedgerClient, journal, group


def configuration():
    return {"version": 1, "learning": True, "merchant_rules": [], "income_rules": []}


def confirmed(identifier="1", description="ExampleGrocer", category="Продукти", category_id="101", **extra):
    return journal(identifier, description=description, category_name=category, category_id=category_id, **extra)


class Client(LedgerClient):
    def __init__(self, journals=None):
        super().__init__(journals if journals is not None else [confirmed()])
        self.categories = [{"id": identifier, "attributes": {"name": name}}
                           for identifier, name in (("101", "Продукти"), ("102", "Послуги"), ("103", "Інше"))]
        self.rule_groups, self.rules = [], []
        self.native_calls = []
        self.rule_read_hook = self.native_before_put = self.native_after_post = self.native_after_put = None

    def all(self, path):
        if path in ("/rules", "/rule-groups"):
            return copy.deepcopy(self.rules if path == "/rules" else self.rule_groups)
        return super().all(path)

    def request(self, method, path, data=None, **options):
        if not (path.startswith("/rules") or path.startswith("/rule-groups")):
            return super().request(method, path, data, **options)
        self.native_calls.append((method, path, copy.deepcopy(data)))
        collection = self.rules if path.startswith("/rules") else self.rule_groups
        if method == "POST":
            attributes = copy.deepcopy(data)
            if path == "/rules":
                attributes["description"] = html.escape(attributes["description"], quote=True).replace("&#x27;", "&#039;")
            attributes.setdefault("order", len(collection) + 1)
            identifier = str(1000 + len(collection))
            if path == "/rules":
                for name in ("triggers", "actions"):
                    for index, item in enumerate(attributes[name]):
                        item["order"] = index + 1
                        if name == "triggers":
                            item["prohibited"] = False
            created = {"id": identifier, "attributes": attributes}
            collection.append(created)
            if self.native_after_post:
                self.native_after_post(path, created)
            return {"data": copy.deepcopy(created)}
        row = next(item for item in collection if str(item["id"]) == path.rsplit("/", 1)[1])
        if method == "GET":
            if path.startswith("/rules/") and self.rule_read_hook:
                self.rule_read_hook(row)
            return {"data": copy.deepcopy(row)}
        assert method == "PUT" and set(data) == {"active"} and type(data["active"]) is bool
        assert options.get("rule_hash"), "Native state changes must carry the conditional rule fingerprint"
        if self.native_before_put:
            self.native_before_put(row)
        if options["rule_hash"] != learning._hash(learning._rule_shape(row["attributes"])):
            raise RuntimeError("HTTP 409: Rule changed since it was read")
        row["attributes"]["active"] = data["active"]
        if self.native_after_put:
            self.native_after_put(row)
        return {"data": copy.deepcopy(row)}


class LearningTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.state = Path(self.directory.name)
        save_json(self.state / "config.json", configuration())

    def policy(self, client, config=None):
        return learning.learn(classification.snapshot(client), config or configuration())

    def test_learns_exact_confirmed_messages_and_classifies_unknown_once(self):
        client = Client([confirmed(), journal("2", description="ExampleGrocer")])
        old = copy.deepcopy(client.groups)
        result = classification.synchronize(client, self.state)
        self.assertEqual((result["learning"]["created"], result["classified"]), (1, 1))
        attributes = client.rules[0]["attributes"]
        self.assertTrue(attributes["strict"])
        self.assertEqual([trigger["type"] for trigger in attributes["triggers"]], ["transaction_type", "description_is", "has_no_category"])
        self.assertEqual(attributes["actions"][0]["type"], "set_category")
        self.assertEqual(json.loads(html.unescape(attributes["description"])), {"firefly_learned_category": 1, "description": "ExampleGrocer", "type": "withdrawal", "category": "Продукти"})
        for before, after in zip(old, client.groups):
            self.assertEqual({field: before["attributes"]["transactions"][0].get(field) for field in classification.PRESERVED_FIELDS},
                             {field: after["attributes"]["transactions"][0].get(field) for field in classification.PRESERVED_FIELDS})
        count = len(client.native_calls)
        repeated = classification.synchronize(client, self.state)
        self.assertEqual((repeated["classified"], repeated["learning"]["created"]), (0, 0))
        self.assertFalse(any(method != "GET" for method, _, _ in client.native_calls[count:]))

    def test_learned_matchers_never_override_manual_categories_or_use_fuzzy_prefixes(self):
        client = Client([journal("1", description="ExampleGrocer"), confirmed("2", category="Послуги", category_id="102"),
                         journal("3", description="ExampleGrocer order 42"), journal("4", description=" examplegrocer ")])
        matchers = [{"kind": "withdrawal", "description": "ExampleGrocer", "category": "Продукти"}]
        plan = classification.plan(configuration(), **classification.snapshot(client), learned=matchers)
        self.assertEqual([row["journal_id"] for row in plan["updates"]], ["1"])
        self.assertEqual(plan["category_conflicts"], 1)

    def test_explicit_rules_take_priority_and_conflicting_policy_does_not_learn(self):
        client = Client()
        config = configuration()
        config["merchant_rules"] = [{"prefix": "ExampleGrocer", "category": "Послуги"}]
        self.assertEqual(self.policy(client, config)["candidates"], [])
        plan = classification.plan(config, **classification.snapshot(Client([journal(description="ExampleGrocer")])),
                                   learned=[{"kind": "withdrawal", "description": "ExampleGrocer", "category": "Продукти"}])
        self.assertEqual(plan["updates"][0]["category"], "Послуги")

    def test_conflicting_normalized_messages_are_quarantined_without_majority_vote(self):
        client = Client([confirmed("1"), confirmed("2"), confirmed("3", description=" examplegrocer ", category="Послуги", category_id="102")])
        # Formatting variants must conflict even though the native matcher uses raw text.
        result = self.policy(client)
        self.assertEqual((len(result["candidates"]), result["conflicts"]), (0, 1))

    def test_conflict_disables_only_unchanged_managed_rule(self):
        client = Client()
        classification.synchronize(client, self.state)
        client.groups.append(group(confirmed("2", category="Послуги", category_id="102")))
        result = classification.synchronize(client, self.state)
        self.assertEqual(result["learning"]["disabled"], 1)
        self.assertFalse(client.rules[0]["attributes"]["active"])
        self.assertEqual([payload for method, _, payload in client.native_calls if method == "PUT"], [{"active": True}, {"active": False}])

    def test_owner_rule_edits_and_disable_are_preserved_and_never_recreated(self):
        for change in ({"title": "Owner rule"}, {"active": False}):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                save_json(state / "config.json", configuration())
                client = Client()
                classification.synchronize(client, state)
                client.rules[0]["attributes"].update(change)
                client.groups.append(group(journal("2", description="ExampleGrocer")))
                count = len(client.native_calls)
                result = classification.synchronize(client, state)
                self.assertEqual((result["classified"], result["learning"]["owner_changed"]), (0, 1))
                self.assertFalse(any(method != "GET" for method, _, _ in client.native_calls[count:]))
                self.assertEqual(len(client.rules), 1)

    def test_owner_group_disable_and_deleted_rule_never_reenable_learning(self):
        for change in ("group", "rule"):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                save_json(state / "config.json", configuration())
                client = Client()
                classification.synchronize(client, state)
                if change == "group":
                    client.rule_groups[0]["attributes"]["active"] = False
                else:
                    client.rules.clear()
                count = len(client.native_calls)
                result = classification.synchronize(client, state)
                self.assertEqual(result["learning"]["owner_changed"], 1)
                self.assertFalse(any(method != "GET" for method, _, _ in client.native_calls[count:]))

    def test_fallback_pending_refunds_holds_transfers_and_missing_categories_are_not_seeds(self):
        rows = [confirmed("1", category="Інше", category_id="103", tags=[MARKER]),
                confirmed("2", notes='{"hold":true}'), confirmed("3", kind="transfer"),
                confirmed("4", category_id="999"), confirmed("5", notes='{"bank_review":{"status":"pending"}}'),
                confirmed("6", description="Refund ExampleGrocer"), confirmed("7", category="Повернення коштів")]
        self.assertEqual(self.policy(Client(rows))["candidates"], [])
        client = Client([confirmed()])
        client.links = [{"id": "1", "attributes": {"link_type_id": "1", "inward_id": "1", "outward_id": "2"}}]
        self.assertEqual(self.policy(client)["candidates"], [])
        self.assertEqual(self.policy(Client([confirmed(category="Інше", category_id="103")]))["candidates"][0]["category"], "Інше")

    def test_generic_descriptions_and_private_exclusions_are_not_learned(self):
        for description in ("ФОП Example", "FOP Merchant", "МАГАЗИН", "Дія", "На картку", "Переказ коштів", "Card payment", "PORTMONE.COM", "RPAY *PROM", "WFPFASTFOOD1", "Exampleperson A.B.", "ExamplepersonA.B.", "Іваненко Іван Іванович", "+380991234567"):
            with self.subTest(description=description):
                self.assertFalse(learning.safe_description(description))
        config = dict(configuration(), learning_exclusions=["ExampleGrocer"])
        self.assertEqual(self.policy(Client(), config)["candidates"], [])

    def test_learning_is_opt_in_and_dry_run_does_not_create_native_rules(self):
        client = Client()
        config = {"version": 1}
        save_json(self.state / "config.json", config)
        self.assertNotIn("learning", classification.synchronize(client, self.state))
        self.assertEqual(client.native_calls, [])
        save_json(self.state / "config.json", configuration())
        result = classification.synchronize(client, self.state, dry_run=True)
        self.assertEqual(result["learning"]["candidates"], 1)
        self.assertEqual(client.native_calls, [])

    def test_invalid_native_readback_defers_learning_without_blocking_explicit_classification(self):
        client = Client([confirmed(), journal("2", description="ApprovedService")])
        client.rule_read_hook = lambda row: row["attributes"].update(strict=False)
        config = dict(configuration(), merchant_rules=[{"prefix": "ApprovedService", "category": "Послуги"}])
        save_json(self.state / "config.json", config)
        result = classification.synchronize(client, self.state)
        self.assertEqual(result["learning"]["status"], "deferred")
        self.assertEqual(result["classified"], 1)
        self.assertEqual(client.groups[1]["attributes"]["transactions"][0]["category_name"], "Послуги")

    def test_existing_rules_and_same_named_owner_group_are_never_adopted(self):
        client = Client()
        client.rules.append({"id": "7", "attributes": {"title": "Owner rule", "active": False, "arbitrary": "Owner settings"}})
        owner = copy.deepcopy(client.rules[0])
        classification.synchronize(client, self.state)
        self.assertEqual(client.rules[0], owner)
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            save_json(state / "config.json", configuration())
            client = Client()
            client.rule_groups.append({"id": "7", "attributes": {"title": learning.GROUP_TITLE, "active": True}})
            result = classification.synchronize(client, state)
            self.assertEqual(result["learning"]["owner_changed"], 1)
            self.assertFalse(any(method != "GET" for method, _, _ in client.native_calls))

    def test_deleted_seed_category_disables_managed_rule_without_creating_metadata(self):
        client = Client()
        classification.synchronize(client, self.state)
        client.categories = [category for category in client.categories if category["id"] != "101"]
        result = classification.synchronize(client, self.state)
        self.assertEqual(result["learning"]["disabled"], 1)
        self.assertEqual(result["categories_created"], 0)
        self.assertFalse(client.rules[0]["attributes"]["active"])

    def test_missing_refund_evidence_defers_the_policy(self):
        snapshot = classification.snapshot(Client())
        del snapshot["links"]
        with self.assertRaises(Exception):
            learning.learn(snapshot, configuration())

    def test_human_confirmed_managed_authorizations_can_seed_but_unknown_holds_cannot(self):
        from datetime import datetime
        from monobank_authorizations import PENDING_TAG
        row = confirmed()
        protocol = {"version": 1, "status": "pending", "key": row["external_id"], "account_id": row["source_id"],
                    "currency": row["currency_code"], "amount": row["amount"],
                    "bank_time": int(datetime.fromisoformat(row["date"]).timestamp())}
        row.update(notes=json.dumps({"hold": True, "monobank_authorization": protocol}), tags=[PENDING_TAG])
        self.assertEqual(len(self.policy(Client([row]))["candidates"]), 1)
        row.update(category_name="Інше", category_id="103", tags=[PENDING_TAG, MARKER])
        self.assertEqual(self.policy(Client([row]))["candidates"], [])

    def test_native_titles_are_readable_and_bounded_and_disambiguate_case_variants(self):
        candidate = self.policy(Client())["candidates"][0]
        self.assertEqual(learning.rule_payload(candidate, "1")["title"], "Витрата · ExampleGrocer")
        long = dict(candidate, description="ExampleMerchant" * 12)
        self.assertLessEqual(len(learning.rule_payload(long, "1")["title"]), 100)
        self.assertIn(candidate["key"][:8], learning.rule_payload(long, "1")["title"])
        client = Client([confirmed("1"), confirmed("2", description="examplegrocer")])
        first = classification.synchronize(client, self.state)
        self.assertEqual(first["learning"]["created"], 2)
        self.assertEqual(len({rule["attributes"]["title"].casefold() for rule in client.rules}), 2)
        self.assertEqual(classification.synchronize(client, self.state)["learning"]["created"], 0)

    def test_confirmed_manual_expense_and_income_seed_but_application_stays_bank_only(self):
        expense = confirmed(description="WorkshopService", internal_reference=None, external_id=None)
        income = confirmed("2", kind="deposit", description="Авторські книжки", internal_reference=None, external_id=None)
        client = Client([expense, income])
        policy = self.policy(client)
        self.assertEqual({row["kind"] for row in policy["candidates"]}, {"deposit", "withdrawal"})
        manual_unknown = journal("3", description="WorkshopService", internal_reference=None, external_id=None)
        plan = classification.plan(configuration(), **classification.snapshot(Client([manual_unknown])), learned=policy["candidates"])
        self.assertEqual(plan["updates"], [])
        for patch in ({"source_id": "999"}, {"destination_id": "11"}, {"currency_id": "99"},
                      {"notes": '{"hold":true}'}, {"notes": '{"refund":true}'}, {"date": "not-a-date"}):
            with self.subTest(patch=patch):
                row = dict(expense, **patch)
                self.assertEqual(self.policy(Client([row]))["candidates"], [])
        suspense = Client([expense])
        suspense.accounts[0]["attributes"]["notes"] = '{"bank_review_suspense":1,"currency":"UAH"}'
        self.assertEqual(self.policy(suspense)["candidates"], [])

    def test_creation_intent_and_manifest_are_durable_before_activation(self):
        client = Client()
        def check_post(path, row):
            if path == "/rules":
                self.assertFalse(row["attributes"]["active"])
                state = json.loads((self.state / learning.MANIFEST).read_text())
                self.assertEqual(len(state["intents"]), 1)
                self.assertEqual(state["rules"], {})
        def check_put(row):
            state = json.loads((self.state / learning.MANIFEST).read_text())
            self.assertEqual(len(state["rules"]), 1)
            self.assertEqual(next(iter(state["rules"].values()))["status"], "activating")
        client.native_after_post = check_post
        client.native_before_put = check_put
        result = classification.synchronize(client, self.state)
        self.assertEqual((result["learning"]["created"], result["learning"]["activated"]), (1, 1))

    def test_timed_out_creation_recovers_only_the_durable_inactive_intent(self):
        client = Client()
        def timeout(path, row):
            if path == "/rules":
                raise RuntimeError("HTTP 503: synthetic POST response lost")
        client.native_after_post = timeout
        first = classification.synchronize(client, self.state)
        self.assertEqual(first["learning"]["status"], "deferred")
        self.assertEqual(len(client.rules), 1)
        self.assertFalse(client.rules[0]["attributes"]["active"])
        client.native_after_post = None
        recovered = classification.synchronize(client, self.state)
        self.assertEqual((recovered["learning"]["created"], recovered["learning"]["adopted"], recovered["learning"]["activated"]), (0, 1, 1))
        self.assertEqual(len(client.rules), 1)

    def test_untracked_inactive_rule_without_durable_intent_is_not_adopted(self):
        client = Client()
        def timeout(path, row):
            if path == "/rules":
                raise RuntimeError("HTTP 503: synthetic POST response lost")
        client.native_after_post = timeout
        classification.synchronize(client, self.state)
        client.native_after_post = None
        state = json.loads((self.state / learning.MANIFEST).read_text())
        state["intents"] = {}
        save_json(self.state / learning.MANIFEST, state)
        result = classification.synchronize(client, self.state)
        self.assertEqual(result["learning"]["owner_changed"], 1)
        self.assertFalse(client.rules[0]["attributes"]["active"])

    def test_owner_race_during_activation_and_deactivation_is_conditionally_preserved(self):
        client = Client()
        client.native_before_put = lambda row: row["attributes"].update(title="Owner changed during activation")
        result = classification.synchronize(client, self.state)
        self.assertEqual(result["learning"]["owner_changed"], 1)
        self.assertFalse(client.rules[0]["attributes"]["active"])
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            save_json(state / "config.json", configuration())
            client = Client()
            classification.synchronize(client, state)
            client.groups.append(group(confirmed("2", category="Послуги", category_id="102")))
            client.native_before_put = lambda row: row["attributes"].update(title="Owner changed during deactivation")
            result = classification.synchronize(client, state)
            self.assertEqual(result["learning"]["owner_changed"], 1)
            self.assertTrue(client.rules[0]["attributes"]["active"])

    def test_completed_activation_timeout_does_not_duplicate_or_rewrite_the_rule(self):
        client = Client()
        client.native_after_put = lambda row: (_ for _ in ()).throw(RuntimeError("HTTP 503: synthetic PUT response lost"))
        first = classification.synchronize(client, self.state)
        self.assertEqual(first["learning"]["status"], "deferred")
        self.assertTrue(client.rules[0]["attributes"]["active"])
        client.native_after_put = None
        count = len(client.native_calls)
        result = classification.synchronize(client, self.state)
        self.assertNotIn("status", result["learning"])
        self.assertFalse(any(method != "GET" for method, _, _ in client.native_calls[count:]))
        self.assertEqual(len(client.rules), 1)

    def test_failed_native_validation_leaves_the_created_rule_inactive(self):
        client = Client()
        client.rule_read_hook = lambda row: row["attributes"].update(strict=False)
        result = classification.synchronize(client, self.state)
        self.assertEqual(result["learning"]["status"], "deferred")
        self.assertFalse(client.rules[0]["attributes"]["active"])

    def test_native_query_syntax_is_excluded_and_native_html_metadata_is_decoded_once(self):
        for text in ('ExampleService "North"', "ExampleService's", "ExampleService\\North", "ExampleService\nNorth", "ExampleService\tNorth"):
            self.assertEqual(learning.description_reason(text), "native_query_syntax")
        client = Client([confirmed(description="ExampleService & North", category="Owner's category")])
        client.categories[0]["attributes"]["name"] = "Owner's category"
        result = classification.synchronize(client, self.state)
        self.assertEqual(result["learning"]["activated"], 1)
        attributes = client.rules[0]["attributes"]
        self.assertIn("&quot;", attributes["description"])
        self.assertIn("&#039;", attributes["description"])
        self.assertIn("&amp;", attributes["description"])
        self.assertNotEqual(learning._hash(learning._rule_shape(attributes)), learning._hash(learning._rule_shape(dict(attributes, description=html.unescape(attributes["description"])))))
        self.assertEqual(classification.synchronize(client, self.state)["learning"]["created"], 0)

    def test_learning_config_rejects_non_boolean_and_invalid_exclusions(self):
        for config in (dict(configuration(), learning="yes"), dict(configuration(), learning_exclusions="ExampleGrocer"),
                       dict(configuration(), learning_exclusions=[""])):
            with self.assertRaises(Exception):
                classification.validate_config(config, {})


if __name__ == "__main__":
    unittest.main()
