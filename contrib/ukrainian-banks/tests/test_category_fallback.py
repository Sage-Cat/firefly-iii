"""Ordinary unknown payments receive an actual, explicitly provisional category."""

import copy
import io
import tempfile
import unittest
from pathlib import Path

import bank_classification
import category_fallback as fallback
from firefly_api import Client as ApiClient
from firefly_api import payment_fingerprint
from monobank_sync import Deferred, save_json
from test_bank_classification import Client, config, journal


class FallbackTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name) / "fallback"

    def test_unknown_manual_and_bank_expenses_and_income_receive_actual_defaults(self):
        client = Client([journal(), journal("2", kind="deposit"),
                         journal("3", external_id=None, internal_reference=None),
                         journal("4", kind="deposit", external_id=None, internal_reference=None)])
        before = copy.deepcopy(client.groups)
        result = fallback.synchronize(client, self.state)
        self.assertEqual((4, True, True), (result["defaulted"], result["category_created"], result["marker_created"]))
        for original, actual in zip(before, client.groups):
            old, row = original["attributes"]["transactions"][0], actual["attributes"]["transactions"][0]
            self.assertTrue(fallback.pending_default(row))
            for field in bank_classification.PRESERVED_FIELDS:
                self.assertEqual(old.get(field), row.get(field), field)
            self.assertEqual(old["tags"] + [fallback.MARKER], row["tags"])
        self.assertEqual((1, 1), (len(client.categories), len(client.tags)))
        self.assertTrue(list(self.state.glob("before-*.json")))
        self.assertFalse((self.state / "config.json").exists())

    def test_repeat_is_zero_and_never_reopens_an_existing_final_other_category(self):
        client = Client([journal(), journal("2", category_id="9", category_name=fallback.CATEGORY),
                         journal("3", category_id="8", category_name="Owner choice")])
        fallback.synchronize(client, self.state)
        client.calls.clear()
        self.assertEqual(0, fallback.synchronize(client, self.state)["defaulted"])
        self.assertFalse(client.calls)
        final = client.groups[1]["attributes"]["transactions"][0]
        self.assertEqual(("9", ["monobank", "Owner tag"]), (final["category_id"], final["tags"]))

    def test_transfers_openings_reconciliation_and_suspense_are_never_defaults(self):
        for extra in [{"type": "transfer"}, {"type": "opening balance"}, {"type": "reconciliation"},
                      {"destination_id": "11"}, {"source_id": None}, {"amount": "0"},
                      {"amount": "NaN"}, {"notes": '{"bank_review":{"status":"pending"}}'}]:
            with self.subTest(extra=extra):
                client = Client([journal(**extra)])
                self.assertEqual([], fallback.plan(**fallback.snapshot(client))["updates"])
        client = Client()
        client.accounts[0]["attributes"]["notes"] = '{"bank_review_suspense":1,"currency":"UAH"}'
        self.assertEqual([], fallback.plan(**fallback.snapshot(client))["updates"])

    def test_provisional_authorization_gets_category_without_losing_its_status(self):
        client = Client([journal(hold=True, tags=["monobank", "Очікує підтвердження банком"],
                                 notes='{"bank_authorization":{"status":"pending"}}')])
        fallback.synchronize(client, self.state)
        actual = client.groups[0]["attributes"]["transactions"][0]
        self.assertTrue(actual["hold"])
        self.assertEqual(["monobank", "Очікує підтвердження банком", fallback.MARKER], actual["tags"])
        self.assertEqual('{"bank_authorization":{"status":"pending"}}', actual["notes"])

    def test_legacy_pending_label_becomes_actual_other_and_stays_pending(self):
        client = Client([journal(category_id="7", category_name=fallback.LEGACY_CATEGORY)])
        self.assertEqual(1, fallback.synchronize(client, self.state)["defaulted"])
        self.assertTrue(fallback.pending_default(client.groups[0]["attributes"]["transactions"][0]))

    def test_default_cas_preserves_owner_category_choice_after_get(self):
        client = Client()
        client.before_put = lambda row: row.update(category_id="8", category_name="Owner choice")
        result = fallback.synchronize(client, self.state)
        self.assertEqual((0, 1), (result["defaulted"], result["stale_skipped"]))
        row = client.groups[0]["attributes"]["transactions"][0]
        self.assertNotIn(fallback.MARKER, row["tags"])
        self.assertEqual("8", row["category_id"])

    def test_additive_default_keeps_owner_tags_added_after_get(self):
        client = Client()
        client.before_put = lambda row: row["tags"].append("Fresh owner tag")
        result = fallback.synchronize(client, self.state)
        self.assertEqual((0, 1), (result['defaulted'], result['stale_skipped']))
        self.assertEqual(["monobank", "Owner tag", "Fresh owner tag"],
                         client.groups[0]["attributes"]["transactions"][0]["tags"])

    def test_financial_readback_changes_are_detected(self):
        client = Client()
        client.after_put = lambda row: row.update(amount="999")
        with self.assertRaises(Deferred):
            fallback.synchronize(client, self.state)

    def test_dry_run_pure_plan_and_already_categorized_ledgers_never_create_metadata(self):
        client = Client()
        before = copy.deepcopy(client.groups)
        result = fallback.synchronize(client, self.state, dry_run=True)
        self.assertEqual(1, result["planned_defaults"])
        self.assertEqual(before, client.groups)
        self.assertFalse(client.calls)
        self.assertFalse(list(self.state.glob("before-*.json")))
        client = Client([journal(category_id="9", category_name=fallback.CATEGORY)])
        self.assertEqual(0, fallback.synchronize(client, self.state)["defaulted"])
        self.assertFalse(client.categories)
        self.assertFalse(client.tags)

    def test_confirmed_rules_replace_only_pending_defaults_and_remove_only_marker(self):
        client = Client([journal(), journal("2", category_id="9", category_name=fallback.CATEGORY)])
        fallback.synchronize(client, self.state)
        save_json(self.state / "config.json", config())
        result = bank_classification.synchronize(client, self.state)
        self.assertEqual((1, 1), (result["classified"], result["category_conflicts"]))
        pending, final = [g["attributes"]["transactions"][0] for g in client.groups]
        self.assertEqual(("Доставка їжі", ["monobank", "Owner tag"]), (pending["category_name"], pending["tags"]))
        self.assertEqual(fallback.CATEGORY, final["category_name"])
        self.assertEqual(0, fallback.synchronize(client, self.state)["defaulted"])

    def test_owner_confirms_same_other_between_plan_and_rule_put(self):
        client = Client()
        fallback.synchronize(client, self.state)
        save_json(self.state / "config.json", config())
        client.before_put = lambda row: row["tags"].remove(fallback.MARKER)
        result = bank_classification.synchronize(client, self.state)
        self.assertEqual((0, 1), (result["classified"], result["stale_skipped"]))
        actual = client.groups[0]["attributes"]["transactions"][0]
        self.assertEqual(fallback.CATEGORY, actual["category_name"])

    def test_owner_description_edit_before_put_never_applies_a_stale_rule_or_default(self):
        for classified in (True, False):
            with self.subTest(classified=classified):
                client = Client()
                if classified:
                    fallback.synchronize(client, self.state)
                    save_json(self.state / 'config.json', config())
                client.before_put = lambda row: row.update(description='Owner changed merchant')
                result = (bank_classification.synchronize(client, self.state) if classified
                          else fallback.synchronize(client, self.state))
                actual = client.groups[0]['attributes']['transactions'][0]
                self.assertEqual(1, result['stale_skipped'])
                self.assertEqual(fallback.CATEGORY if classified else None, actual['category_name'])
                self.assertEqual(classified, fallback.MARKER in actual['tags'])

    def test_payment_fingerprint_covers_all_owner_state_fields_used_by_rules(self):
        original = journal()
        expected = payment_fingerprint('1', original)
        for field, value in {'source_name': 'Owner source', 'destination_name': 'Owner merchant',
                             'description': 'Owner description', 'notes': 'Owner note', 'amount': '5.00',
                             'tags': ['Owner changed tag'], 'category_id': '5', 'budget_id': '4',
                             'foreign_amount': '5.02', 'external_id': 'other'}.items():
            altered = dict(original, **{field: value})
            self.assertNotEqual(expected, payment_fingerprint('1', altered), field)

    def test_cross_language_payment_fingerprint_vector(self):
        row = dict(transaction_journal_id='20', type='withdrawal', date='2026-11-08T09:00:00+02:00',
                   amount='123.45', currency_id='1', source_id='1', destination_id='2', source_name='Account 1',
                   destination_name='Account 2', description='Synthetic bank payment 20',
                   notes='{"proof":"Original bank evidence","bank_review_aliases":["synthetic:other"]}',
                   tags=['Owner tag'], category_id='4')
        self.assertEqual('a0d1b7d13c0da1f4a56c8028301f1bcfef6a5690c0399279f5753c1fa65149a9', payment_fingerprint('20', row))

    def test_duplicate_default_metadata_names_fail_before_writes(self):
        client = Client()
        client.categories = [{"id": str(i), "attributes": {"name": fallback.CATEGORY}} for i in (1, 2)]
        with self.assertRaises(Deferred):
            fallback.synchronize(client, self.state)
        self.assertFalse(client.calls)


class FallbackHeaderTests(unittest.TestCase):
    def setUp(self):
        class Http:
            def open(self, request, timeout):
                self.request = request
                return io.BytesIO(b'')
        self.client = ApiClient.__new__(ApiClient)
        self.client.base = "http://127.0.0.1:8080/api/v1"
        self.client._token = "synthetic-test-token"
        self.client._http = Http()

    def test_explicit_default_requires_conditional_category_update(self):
        self.client.request("PUT", "/transactions/1", {}, category_match="none", category_default=True)
        self.assertEqual("1", self.client._http.request.get_header("If-category-default"))
        self.client.request("GET", "/about")
        self.assertIsNone(self.client._http.request.get_header("If-category-default"))
        for kwargs in [{}, {"category_match": "none", "tags_hash": "a" * 64}]:
            with self.assertRaises(ValueError):
                self.client.request("PUT", "/transactions/1", category_default=True, **kwargs)
        for value in [1, "1", None]:
            with self.assertRaises(ValueError):
                self.client.request("PUT", "/transactions/1", category_match="none", category_default=value)
        with self.assertRaises(ValueError):
            self.client.request("POST", "/transactions", category_match="none", category_default=True)
        self.client.request('PUT', '/transactions/1', category_match='none', payment_hash='a' * 64)
        self.assertEqual('a' * 64, self.client._http.request.get_header('If-match-payment'))
        with self.assertRaises(ValueError):
            self.client.request('GET', '/transactions/1', category_match='none', payment_hash='a' * 64)


if __name__ == "__main__":
    unittest.main()
