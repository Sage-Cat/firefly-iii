import copy
import hashlib
import io
import json
import unittest

from firefly_api import Client, internal_card_fingerprint, tag_fingerprint


def journal():
    return {
        "transaction_journal_id": "1701", "type": "transfer", "date": "2026-11-08T11:00:00+02:00",
        "amount": "123.45", "currency_id": "1", "source_id": "11", "destination_id": "12",
        "description": "Synthetic / картки\u2028", "notes": '{"proof":"Synthetic / доказ"}',
        "tags": ["Банк", "imported", "A\u2028"], "external_id": "synthetic:bank:row",
        "internal_reference": "synthetic-import", "original_source": "synthetic-source", "import_hash_v2": "synthetic-import-hash"
    }


class InternalCardFingerprintTest(unittest.TestCase):
    def test_cross_language_vector(self):
        self.assertEqual(internal_card_fingerprint("701", journal()),
                         "b56e14973bd4676077156d7fbd694465737c0cecf1c66bc4ba134580c6bb39a0")

    def test_decimal_date_and_tag_order_normalization(self):
        row = journal()
        expected = internal_card_fingerprint("701", row)
        row.update(amount="123.4500000000000000", date="2026-11-08T09:00:00+00:00")
        row["tags"].reverse()
        self.assertEqual(internal_card_fingerprint(701, row), expected)

    def test_every_contract_field_changes_hash(self):
        expected = internal_card_fingerprint("701", journal())
        for field, value in {
            "transaction_journal_id": "1702", "type": "withdrawal", "date": "2026-11-08T11:00:01+02:00",
            "amount": "123.46", "currency_id": "2", "source_id": "13", "destination_id": "14",
            "external_id": "changed-bank-identity", "internal_reference": "changed-import",
            "original_source": "changed-source", "import_hash_v2": "changed-import-hash",
            "description": "Owner edit", "notes": "Owner note", "tags": ["Owner tag"]
        }.items():
            row = copy.deepcopy(journal())
            row[field] = value
            with self.subTest(field=field):
                self.assertNotEqual(internal_card_fingerprint("701", row), expected)
        self.assertNotEqual(internal_card_fingerprint("702", journal()), expected)

    def test_invalid_precision_timezone_and_tags_rejected(self):
        for field, value in [("amount", "0"), ("amount", "NaN"), ("amount", "0.00000000000000001"),
                             ("date", "2026-11-08T11:00:00"), ("date", "2026-11-08T11:00:00.1+02:00"),
                             ("tags", [12]), ("source_id", "0")]:
            row = journal()
            row[field] = value
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                internal_card_fingerprint("701", row)

    def test_tag_fingerprint_preserves_labels(self):
        tags = ["Банк", "A/\u2028", "owner"]
        expected = hashlib.sha256(json.dumps(sorted(tags), ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()
        self.assertEqual(tag_fingerprint(tags), expected)
        self.assertEqual(tag_fingerprint(list(reversed(tags))), expected)
        self.assertNotEqual(tag_fingerprint(tags + ["Owner"]), expected)
        for invalid in [None, {}, "tag", [1]]:
            with self.assertRaises(ValueError):
                tag_fingerprint(invalid)


class ClientPreconditionTest(unittest.TestCase):
    def setUp(self):
        class Http:
            def open(self, request, timeout):
                self.request = request
                return io.BytesIO(b'')
        self.client = Client.__new__(Client)
        self.client.base = "http://127.0.0.1:8080/api/v1"
        self.client._token = "synthetic-test-token"
        self.client._http = Http()

    def test_optional_internal_card_header(self):
        self.client.request("DELETE", "/transactions/701", internal_card_hash="a" * 64)
        self.assertEqual(self.client._http.request.get_header("If-match-internal-card"), "a" * 64)
        self.client.request("DELETE", "/transactions/701")
        self.assertIsNone(self.client._http.request.get_header("If-match-internal-card"))

    def test_internal_card_header_only_for_group_delete(self):
        for method, path, value in [("DELETE", "/transactions/701", "A" * 64),
                                    ("DELETE", "/transactions/701", "invalid"),
                                    ("DELETE", "/transactions/701", 1),
                                    ("GET", "/transactions/701", "a" * 64),
                                    ("DELETE", "/transaction-journals/1701", "a" * 64)]:
            with self.subTest(method=method, path=path, value=value), self.assertRaises(ValueError):
                self.client.request(method, path, internal_card_hash=value)

    def test_optional_tag_header(self):
        self.client.request("PUT", "/transactions/701", {"transactions": [{"tags": ["test"]}]}, tags_hash="b" * 64)
        self.assertEqual(self.client._http.request.get_header("If-match-tags"), "b" * 64)
        self.client.request("GET", "/about")
        self.assertIsNone(self.client._http.request.get_header("If-match-tags"))
        for value in ["bad", "B" * 64, 1]:
            with self.assertRaises(ValueError):
                self.client.request("PUT", "/transactions/701", tags_hash=value)


if __name__ == "__main__":
    unittest.main()
