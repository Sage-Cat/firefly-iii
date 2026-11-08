import copy
import io
import unittest

from firefly_api import Client, bank_authorization_fingerprint


def journal():
    return {
        "transaction_journal_id": "1901", "type": "withdrawal", "date": "2026-11-08T11:00:00+02:00",
        "amount": "123.45", "currency_id": "1", "source_id": "21", "destination_id": "22",
        "description": "Synthetic / авторизація\u2028", "notes": '{"proof":"Synthetic / доказ"}',
        "tags": ["Банк", "imported", "A\u2028"], "category_id": "31", "budget_id": "41", "bill_id": "51",
        "external_id": "monobank:synthetic:row", "internal_reference": "monobank",
        "original_source": "synthetic-source", "import_hash_v2": "synthetic-import-hash",
    }


class BankAuthorizationFingerprintTest(unittest.TestCase):
    def test_cross_language_vector(self):
        self.assertEqual(bank_authorization_fingerprint("901", journal()),
                         "60fcad7219927e93b8900e5c4417f2fc6b203eaa4f4ea1354fec4eccf5b472ae")

    def test_normalization(self):
        row = journal()
        expected = bank_authorization_fingerprint("901", row)
        row.update(amount="123.4500000000000000", date="2026-11-08T09:00:00+00:00", type="Withdrawal")
        row["tags"].reverse()
        self.assertEqual(bank_authorization_fingerprint(901, row), expected)
        row = journal()
        for field in ("category_id", "budget_id", "bill_id"):
            row[field] = None
        empty = copy.deepcopy(row)
        for field in ("category_id", "budget_id", "bill_id"):
            del empty[field]
        self.assertEqual(bank_authorization_fingerprint(901, row), bank_authorization_fingerprint(901, empty))

    def test_every_contract_field_changes_hash(self):
        expected = bank_authorization_fingerprint("901", journal())
        for field, value in {
            "transaction_journal_id": "1902", "date": "2026-11-08T11:00:01+02:00", "amount": "123.46",
            "currency_id": "2", "source_id": "23", "destination_id": "24", "description": "Owner edit",
            "notes": "Owner note", "tags": ["Owner tag"], "category_id": "32", "budget_id": "42", "bill_id": "52",
            "external_id": "monobank:synthetic:changed", "internal_reference": "changed-import",
            "original_source": "changed-source", "import_hash_v2": "changed-import-hash",
        }.items():
            row = copy.deepcopy(journal())
            row[field] = value
            with self.subTest(field=field):
                self.assertNotEqual(bank_authorization_fingerprint("901", row), expected)
        self.assertNotEqual(bank_authorization_fingerprint("902", journal()), expected)

    def test_invalid_snapshot_rejected(self):
        for field, value in [("amount", "0"), ("amount", "-1"), ("amount", "NaN"),
                             ("amount", "0.00000000000000001"), ("type", "transfer"),
                             ("date", "2026-11-08T11:00:00"), ("date", "2026-11-08T11:00:00.1+02:00"),
                             ("tags", {}), ("tags", [1]), ("source_id", "0"), ("category_id", "0"),
                             ("budget_id", []), ("notes", {}), ("external_id", 123)]:
            row = journal()
            row[field] = value
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                bank_authorization_fingerprint("901", row)


class BankAuthorizationHeaderTest(unittest.TestCase):
    def setUp(self):
        class Http:
            def open(self, request, timeout):
                self.request = request
                return io.BytesIO(b'')
        self.client = Client.__new__(Client)
        self.client.base = "http://127.0.0.1:8080/api/v1"
        self.client._token = "synthetic-test-token"
        self.client._http = Http()

    def test_optional_header_for_update_and_delete(self):
        for method in ("PUT", "DELETE"):
            self.client.request(method, "/transactions/901", bank_authorization_hash="a" * 64)
            self.assertEqual(self.client._http.request.get_header("If-match-bank-authorization"), "a" * 64)
        self.client.request("GET", "/about")
        self.assertIsNone(self.client._http.request.get_header("If-match-bank-authorization"))

    def test_header_only_for_group_mutations(self):
        for method, path, value in [("PUT", "/transactions/901", "A" * 64), ("DELETE", "/transactions/901", "bad"),
                                    ("PUT", "/transactions/901", 1), ("GET", "/transactions/901", "a" * 64),
                                    ("POST", "/transactions/901", "a" * 64),
                                    ("DELETE", "/transaction-journals/1901", "a" * 64)]:
            with self.subTest(method=method, path=path, value=value), self.assertRaises(ValueError):
                self.client.request(method, path, bank_authorization_hash=value)

    def test_mixed_preconditions_rejected(self):
        for other in ({"notes_hash": "b" * 64}, {"category_match": "none"},
                      {"tags_hash": "b" * 64}, {"internal_card_hash": "b" * 64}):
            with self.subTest(other=other), self.assertRaises(ValueError):
                self.client.request("DELETE", "/transactions/901", bank_authorization_hash="a" * 64, **other)


if __name__ == "__main__":
    unittest.main()
