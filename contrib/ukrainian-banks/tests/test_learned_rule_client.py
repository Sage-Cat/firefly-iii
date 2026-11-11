import io
import unittest

from firefly_api import Client


class RuleHeaderTests(unittest.TestCase):
    def test_header_and_scope(self):
        class Http:
            def open(self, request, timeout):
                self.request = request
                return io.BytesIO(b'{}')
        client = Client.__new__(Client)
        client.base = 'http://127.0.0.1:8080/api/v1'
        client._token = 'synthetic-test-token'
        client._http = Http()
        client.request('PUT', '/rules/1', {'active': False}, rule_hash='a' * 64)
        self.assertEqual(client._http.request.get_header('If-match-learned-rule'), 'a' * 64)
        for method, path, value, extra in [('GET', '/rules/1', 'a'*64, {}), ('PUT', '/transactions/1', 'a'*64, {}),
                ('PUT', '/rules/1', 'bad', {}), ('PUT', '/rules/1', 'a'*64, {'tags_hash': 'b'*64})]:
            with self.assertRaises(ValueError):
                client.request(method, path, {'active': False}, rule_hash=value, **extra)
        client.request('GET', '/rules/1')
        self.assertIsNone(client._http.request.get_header('If-match-learned-rule'))


if __name__ == '__main__':
    unittest.main()
