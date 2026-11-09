"""Synthetic native refund categorization and exact cancellation pairing."""

import copy
import json
import stat
import tempfile
import unittest
from pathlib import Path

import bank_refunds as refunds


def transaction(identifier, kind="withdrawal", value="27.50", **extra):
    own = "10"
    return dict({"transaction_journal_id": str(identifier), "type": kind, "amount": value,
                 "date": "2026-10-12T14:00:00+03:00" if kind == "withdrawal" else "2026-10-12T14:00:35+03:00",
                 "currency_id": "4", "currency_code": "UAH", "source_id": own if kind == "withdrawal" else "900",
                 "destination_id": own if kind == "deposit" else "901",
                 "description": "Example Merchant" if kind == "withdrawal" else "Скасування. Example Merchant",
                 "internal_reference": "monobank", "external_id": f"monobank:synthetic-{identifier}",
                 "category_id": None, "category_name": None, "notes": '{"proof":"Original bank evidence"}',
                 "budget_id": None, "budget_name": None, "tags": ["monobank"], "bill_id": None,
                 "foreign_currency_id": "1", "foreign_amount": "0.55"}, **extra)


def group(journal):
    return {"id": journal["transaction_journal_id"], "attributes": {"transactions": [journal]}}


def link(original="1", refund="2", kind="refund"):
    return {"id": "link", "attributes": {"inward_id": original, "outward_id": refund, "link_type_id": kind}}


class Client:
    def __init__(self, groups=None, categories=None, links=None):
        self.groups = copy.deepcopy(groups if groups is not None else [group(transaction("1")), group(transaction("2", "deposit"))])
        self.categories = copy.deepcopy(categories or [])
        self.links = copy.deepcopy(links or [])
        self.types = [{"id": "related", "attributes": {"name": "Related"}},
                      {"id": "refund", "attributes": {"name": "Refund"}}]
        self.accounts = [{"id": "10", "attributes": {"currency_code": "UAH", "current_balance": "100.00"}}]
        self.calls = []
        self.get_hook = None
        self.put_hook = None
        self.before_put = None

    def all(self, path):
        values = {"/accounts?type=asset": self.accounts, "/transactions?type=all": self.groups,
                  "/categories": self.categories, "/transaction-links": self.links, "/link-types": self.types}
        return copy.deepcopy(values[path])

    def request(self, method, path, data=None, *, category_match=None, payment_hash=None):
        self.calls.append((method, path, copy.deepcopy(data)))
        if method == "POST" and path == "/categories":
            category = {"id": "100", "attributes": {"name": data["name"]}}
            self.categories.append(category)
            return {"data": copy.deepcopy(category)}
        if method == "POST" and path == "/transaction-links":
            self.links.append({"id": str(len(self.links) + 1), "attributes": copy.deepcopy(data)})
            return {"data": copy.deepcopy(self.links[-1])}
        identifier = path.removeprefix("/transactions/")
        row = next(g for g in self.groups if g["id"] == identifier)
        if method == "GET":
            if self.get_hook:
                hook, self.get_hook = self.get_hook, None
                hook(row)
            return {"data": copy.deepcopy(row)}
        if method != "PUT":
            raise AssertionError((method, path))
        if self.before_put:
            self.before_put(row)
        if payment_hash is not None:
            from firefly_api import payment_fingerprint
            try:
                actual_hash = payment_fingerprint(row['id'], row['attributes']['transactions'][0])
            except ValueError:
                actual_hash = None
            if actual_hash != payment_hash:
                raise RuntimeError('HTTP 409: Payment changed since it was read')
        current = row["attributes"]["transactions"][0].get("category_id")
        if (str(current) if current else "none") != category_match:
            raise RuntimeError("HTTP 409: Category changed since it was read")
        from category_fallback import CATEGORY as DEFAULT_CATEGORY, MARKER, pending_default
        journal = row["attributes"]["transactions"][0]
        if journal.get("category_name") == DEFAULT_CATEGORY and not pending_default(journal):
            raise RuntimeError("HTTP 409: Payment was explicitly confirmed")
        if pending_default(journal):
            journal["tags"] = [tag for tag in journal["tags"] if tag != MARKER]
        self.assert_category_only(data)
        for patch in data["transactions"]:
            journal = next(j for j in row["attributes"]["transactions"]
                           if j["transaction_journal_id"] == patch["transaction_journal_id"])
            journal["category_id"] = patch["category_id"]
            journal["category_name"] = next(c["attributes"]["name"] for c in self.categories if c["id"] == patch["category_id"])
        if self.put_hook:
            self.put_hook(row)
        return {"data": copy.deepcopy(row)}

    @staticmethod
    def assert_category_only(data):
        assert data["apply_rules"] is False and data["fire_webhooks"] is False
        assert all(set(patch) == {"transaction_journal_id", "category_id"} for patch in data["transactions"])


class RefundTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name)

    def plan(self, client):
        return refunds.plan_refunds(client.groups, client.accounts, client.categories, client.types, client.links)

    def test_unique_explicit_cancellation_gets_category_and_native_journal_link(self):
        client = Client()
        before = copy.deepcopy(client.groups)
        result = refunds.synchronize(client, self.state)
        self.assertEqual((True, 1, 1), (result["category_created"], result["classified"], result["links_created"]))
        self.assertEqual(refunds.CATEGORY, client.groups[1]["attributes"]["transactions"][0]["category_name"])
        self.assertEqual({"link_type_id": "refund", "inward_id": "1", "outward_id": "2"}, client.links[0]["attributes"])
        for old, new in zip(before, client.groups):
            a, b = old["attributes"]["transactions"][0], new["attributes"]["transactions"][0]
            self.assertEqual({k: v for k, v in a.items() if not k.startswith("category_")},
                             {k: v for k, v in b.items() if not k.startswith("category_")})
        self.assertTrue(all(stat.S_IMODE(p.stat().st_mode) == 0o600 for p in self.state.iterdir()))

    def test_second_sync_reuses_category_and_link_without_writes(self):
        client = Client()
        refunds.synchronize(client, self.state)
        client.calls.clear()
        result = refunds.synchronize(client, self.state)
        self.assertEqual((False, 0, 0, 1), (result["category_created"], result["classified"], result["links_created"], result["links_reused"]))
        self.assertTrue(all(method == "GET" for method, _, _ in client.calls))
        self.assertEqual(1, len(client.categories))
        self.assertEqual(1, len(client.links))

    def test_dry_run_does_not_create_category_or_write_transactions(self):
        client = Client()
        result = refunds.synchronize(client, self.state, dry_run=True)
        self.assertEqual((1, 1), (result["planned_classifications"], result["planned_links"]))
        self.assertEqual([], client.calls)
        self.assertEqual([], client.categories)

    def test_regular_deposit_and_non_monobank_payment_are_not_guessed_refunds(self):
        for extra in [{"description": "Example Merchant"}, {"description": "Повернення від знайомого"},
                      {"internal_reference": "other", "external_id": "other:refund"}, {"hold": True},
                      {"notes": '{"hold":true}'}]:
            with self.subTest(extra=extra):
                client = Client([group(transaction("1")), group(transaction("2", "deposit", **extra))])
                self.assertEqual([], self.plan(client)["updates"])
                self.assertEqual([], self.plan(client)["pairs"])

    def test_merchant_matching_normalizes_case_and_whitespace_only(self):
        client = Client([group(transaction("1", description="EXAMPLE   MERCHANT")),
                         group(transaction("2", "deposit", description="  скасування. Example\tMerchant  "))])
        self.assertEqual(1, len(self.plan(client)["pairs"]))
        client.groups[1]["attributes"]["transactions"][0]["description"] = "Скасування. Example Merchant Shop"
        self.assertEqual([], self.plan(client)["pairs"])

    def test_mismatched_amount_currency_account_or_date_stays_unpaired(self):
        for extra in [{"amount": "27.49"}, {"currency_id": "1"}, {"destination_id": "11"},
                      {"date": "2026-10-12T14:10:01+03:00"}, {"date": "2026-10-12T13:59:59+03:00"},
                      {"date": "2026-10-13T14:00:00+03:00"}, {"date": "2026-10-12T14:00:35"}]:
            with self.subTest(extra=extra):
                client = Client([group(transaction("1")), group(transaction("2", "deposit", **extra))])
                client.accounts.append({"id": "11", "attributes": {}})
                self.assertEqual([], self.plan(client)["pairs"])
                self.assertEqual(1, len(self.plan(client)["updates"]))

    def test_cross_midnight_is_not_an_immediate_same_day_pair(self):
        client = Client([group(transaction("1", date="2026-10-12T23:59:55+03:00")),
                         group(transaction("2", "deposit", date="2026-10-13T00:00:05+03:00"))])
        self.assertEqual([], self.plan(client)["pairs"])

    def test_offsets_are_converted_to_kyiv_before_day_and_interval_checks(self):
        client = Client([group(transaction("1", date="2026-10-12T11:00:00+00:00")),
                         group(transaction("2", "deposit", date="2026-10-12T14:00:35+03:00"))])
        self.assertEqual(1, len(self.plan(client)["pairs"]))

    def test_ten_minute_boundary_is_inclusive(self):
        client = Client([group(transaction("1")), group(transaction("2", "deposit", date="2026-10-12T14:10:00+03:00"))])
        self.assertEqual(1, len(self.plan(client)["pairs"]))

    def test_two_equal_originals_or_two_refunds_are_ambiguous_in_any_order(self):
        for rows in [[transaction("1"), transaction("3"), transaction("2", "deposit")],
                     [transaction("1"), transaction("2", "deposit"), transaction("3", "deposit")]]:
            for ordering in [rows, list(reversed(rows))]:
                client = Client([group(row) for row in ordering])
                plan = self.plan(client)
                self.assertEqual([], plan["pairs"])
                self.assertGreater(plan["ambiguous"], 0)

    def test_manual_category_does_not_hide_an_equally_plausible_cancellation(self):
        client = Client([group(transaction("1")), group(transaction("2", "deposit")),
                         group(transaction("3", "deposit", category_id="manual", category_name="Owner choice"))])
        plan = self.plan(client)
        self.assertEqual(([], 1, 1), (plan["pairs"], plan["ambiguous"], plan["manual_preserved"]))

    def test_unsettled_original_is_not_used_for_a_refund_pair(self):
        for extra in [{"hold": True}, {"notes": '{"hold":true}'}]:
            client = Client([group(transaction("1", **extra)), group(transaction("2", "deposit"))])
            self.assertEqual([], self.plan(client)["pairs"])

    def test_nonpositive_or_invalid_amount_is_not_classified(self):
        for value in ["0", "-1", "NaN", "Infinity", True, None]:
            with self.subTest(value=value):
                client = Client([group(transaction("1")), group(transaction("2", "deposit", value=value))])
                self.assertEqual([], self.plan(client)["updates"])

    def test_unsafe_pair_does_not_prevent_other_unique_pair(self):
        client = Client([group(transaction("1")), group(transaction("2", "deposit")),
                         group(transaction("3", description="Another Merchant")),
                         group(transaction("4", description="Another Merchant")),
                         group(transaction("5", "deposit", description="Скасування. Another Merchant"))])
        result = refunds.synchronize(client, self.state)
        self.assertEqual((1, 1, 2), (result["links_created"], result["ambiguous"], result["classified"]))

    def test_owner_non_refund_category_is_preserved_and_not_auto_linked(self):
        client = Client([group(transaction("1")), group(transaction("2", "deposit", category_id="manual", category_name="Інші доходи"))])
        plan = self.plan(client)
        self.assertEqual(([], [], 1), (plan["updates"], plan["pairs"], plan["manual_preserved"]))

    def test_legacy_pending_category_can_be_replaced_with_explicit_refund(self):
        client = Client([group(transaction("1")), group(transaction("2", "deposit", category_id="7", category_name=refunds.LEGACY_CATEGORY))])
        result = refunds.synchronize(client, self.state)
        self.assertEqual(1, result["classified"])

    def test_pending_other_can_be_refined_but_final_other_is_preserved(self):
        from category_fallback import CATEGORY as DEFAULT_CATEGORY, MARKER
        for pending in (True, False):
            with self.subTest(pending=pending):
                tags = ["Owner tag", MARKER] if pending else ["Owner tag"]
                client = Client([group(transaction("1")), group(transaction("2", "deposit", category_id="7",
                                category_name=DEFAULT_CATEGORY, tags=tags))])
                result = refunds.synchronize(client, self.state)
                actual = client.groups[1]["attributes"]["transactions"][0]
                self.assertEqual(int(pending), result["classified"])
                self.assertEqual(["Owner tag"], actual["tags"])
                self.assertEqual(refunds.CATEGORY if pending else DEFAULT_CATEGORY, actual["category_name"])

    def test_finalizing_other_after_refund_plan_is_not_overwritten(self):
        from category_fallback import CATEGORY as DEFAULT_CATEGORY, MARKER
        client = Client([group(transaction("1")), group(transaction("2", "deposit", category_id="7",
                        category_name=DEFAULT_CATEGORY, tags=["Owner tag", MARKER]))])
        client.before_put = lambda row: row["attributes"]["transactions"][0]["tags"].remove(MARKER)
        result = refunds.synchronize(client, self.state)
        self.assertEqual(0, result["classified"])
        self.assertGreaterEqual(result["stale_skipped"], 1)
        self.assertEqual(DEFAULT_CATEGORY, client.groups[1]["attributes"]["transactions"][0]["category_name"])

    def test_native_existing_refund_link_is_reused_and_different_link_is_preserved(self):
        client = Client(links=[link()])
        result = refunds.synchronize(client, self.state)
        self.assertEqual((0, 1), (result["links_created"], result["links_reused"]))
        client = Client(links=[link(kind="related")])
        result = refunds.synchronize(client, self.state)
        self.assertEqual((0, 1), (result["links_created"], result["link_conflicts"]))
        self.assertEqual("related", client.links[0]["attributes"]["link_type_id"])

    def test_refund_already_attached_to_another_expense_is_not_relinked(self):
        client = Client(links=[link(original="99")])
        plan = self.plan(client)
        self.assertEqual(([], 1), (plan["pairs"], plan["link_conflicts"]))

    def test_split_group_category_patch_targets_only_the_explicit_refund_journal(self):
        original, returned, sibling = transaction("1"), transaction("2", "deposit"), transaction("3", "deposit", description="Unrelated income")
        client = Client([group(original), {"id": "20", "attributes": {"transactions": [returned, sibling]}}])
        before = copy.deepcopy(sibling)
        result = refunds.synchronize(client, self.state)
        self.assertEqual(1, result["classified"])
        self.assertEqual(before, client.groups[1]["attributes"]["transactions"][1])

    def test_owner_category_changed_after_plan_is_skipped_without_overwrite(self):
        client = Client()
        def owner_change(row):
            journal = row["attributes"]["transactions"][0]
            journal.update(category_id="manual", category_name="Owner choice")
        client.get_hook = owner_change
        result = refunds.synchronize(client, self.state)
        self.assertEqual(0, result["classified"])
        self.assertEqual(0, result["links_created"])
        self.assertEqual("manual", client.groups[1]["attributes"]["transactions"][0]["category_id"])

    def test_manual_choice_between_final_get_and_put_wins_atomically(self):
        client = Client()
        client.before_put = lambda row: row["attributes"]["transactions"][0].update(category_id="manual", category_name="Chosen")
        result = refunds.synchronize(client, self.state)
        self.assertGreaterEqual(result["stale_skipped"], 1)
        self.assertEqual(0, result["classified"])
        self.assertEqual(0, result["links_created"])
        self.assertEqual("manual", client.groups[1]["attributes"]["transactions"][0]["category_id"])

    def test_native_category_update_changing_notes_fails_readback(self):
        client = Client()
        client.put_hook = lambda row: row["attributes"]["transactions"][0].update(notes="Changed")
        with self.assertRaises(refunds.Deferred):
            refunds.synchronize(client, self.state)

    def test_existing_original_notes_budget_and_classification_are_not_changed(self):
        original = transaction("1", category_id="digital", category_name="Цифрові сервіси", budget_id="subscriptions", budget_name="Підписки")
        client = Client([group(original), group(transaction("2", "deposit"))])
        refunds.synchronize(client, self.state)
        self.assertEqual(original, client.groups[0]["attributes"]["transactions"][0])

    def test_duplicate_category_or_journal_identity_fails_before_writes(self):
        categories = [{"id": "one", "attributes": {"name": refunds.CATEGORY}},
                      {"id": "two", "attributes": {"name": refunds.CATEGORY}}]
        for client in [Client(categories=categories), Client([group(transaction("1")), group(transaction("1"))])]:
            with self.assertRaises(refunds.Deferred):
                refunds.synchronize(client, self.state)
            self.assertFalse(client.calls)

    def test_private_state_permissions_are_required(self):
        self.state.chmod(0o755)
        with self.assertRaises(PermissionError):
            refunds.synchronize(Client(), self.state)


if __name__ == "__main__":
    unittest.main()
