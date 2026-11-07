import fcntl
import http.client
import io
import json
import multiprocessing
import os
import stat
import tempfile
import threading
import time
import unittest
import urllib.error
from pathlib import Path
from unittest.mock import Mock, patch

from monobank_api import APIError, Client, NoRedirect, RateLimitError

REAL_SLEEP = time.sleep


class Clock:
    def __init__(self, now=1000):
        self.now = now
        self.elapsed = 0
        self.sleeps = []

    def time(self):
        return self.now

    def sleep(self, delay):
        self.sleeps.append(delay)
        self.now += delay
        self.elapsed += delay

    def monotonic(self):
        return self.elapsed


def hold_lock(path, ready, release):
    import fcntl
    descriptor = os.open(path, os.O_RDWR | os.O_CREAT, 0o600)
    with os.fdopen(descriptor, "a+") as stream:
        fcntl.flock(stream, fcntl.LOCK_EX)
        ready.set()
        release.wait(5)


class ClientTests(unittest.TestCase):
    TOKEN = "synthetic-test-token"

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.token = self.root / "token"
        self.token.write_text(self.TOKEN + "\n")
        self.token.chmod(0o600)
        self.state = self.root / "state"
        self.clock = Clock()
        self.patches = [patch("monobank_api.time.time", self.clock.time),
                        patch("monobank_api.time.sleep", self.clock.sleep),
                        patch("monobank_api.time.monotonic", self.clock.monotonic)]
        for replacement in self.patches:
            replacement.start()
            self.addCleanup(replacement.stop)
        self.client = Client(self.token, self.state)
        self.client._http = Mock()
        self.respond({"accounts": [{"balance": 123, "creditLimit": 0}]})

    def respond(self, payload):
        response = Mock()
        content = json.dumps(payload).encode()

        def enter():
            response.stream = io.BytesIO(content)
            return response

        response.__enter__ = Mock(side_effect=enter)
        response.__exit__ = Mock(return_value=False)
        response.read1.side_effect = lambda size: response.stream.read1(size)
        response.read.side_effect = AssertionError("Unbounded response.read() is not allowed")
        self.client._http.open.return_value = response
        return response

    def test_client_info_is_authenticated_get_and_keeps_integer_minor_units(self):
        result = self.client.client_info()
        request = self.client._http.open.call_args.args[0]
        self.assertEqual(request.full_url, "https://api.monobank.ua/personal/client-info")
        self.assertEqual(request.method, "GET")
        self.assertEqual(request.get_header("X-token"), self.TOKEN)
        self.assertEqual(result["accounts"][0]["balance"], 123)
        self.assertIs(type(result["accounts"][0]["balance"]), int)
        self.assertNotIn(self.TOKEN, repr(self.client))
        self.assertEqual(stat.S_IMODE(self.state.stat().st_mode), 0o700)
        for name in ("rate-limits.json", "request.lock"):
            self.assertEqual(stat.S_IMODE((self.state / name).stat().st_mode), 0o600)

    def test_account_identifier_is_encoded_as_one_path_segment(self):
        self.respond([])
        self.assertEqual(self.client.statement("account/id+=?", 10, 20), [])
        request = self.client._http.open.call_args.args[0]
        self.assertEqual(request.full_url,
                         "https://api.monobank.ua/personal/statement/account%2Fid%2B%3D%3F/10/20")

    def test_statement_rejects_invalid_windows_without_request(self):
        for start, end in [(1, 1), (2, 1), (-1, 10), (0, 2_682_001), (1.0, 2), (True, 2)]:
            with self.subTest(start=start, end=end), self.assertRaises(ValueError):
                self.client.statement("account", start, end)
        self.client._http.open.assert_not_called()
        self.respond([])
        self.client.statement("account", 0, 2_682_000)

    def test_statement_limit_is_shared_between_accounts_and_client_instances(self):
        self.respond([])
        self.client.statement("first", 10, 20)
        self.client.statement("second", 10, 20)
        self.assertEqual(self.clock.sleeps, [30, 30])
        other = Client(self.token, self.state)
        other._http = self.client._http
        self.clock.now += 10
        other.statement("third", 10, 20)
        self.assertEqual(self.clock.sleeps, [30, 30, 30, 20])
        self.assertEqual(json.loads((self.state / "rate-limits.json").read_text())["statement"], 1180)

    def test_client_info_has_independent_rate_limit_family(self):
        self.respond([])
        self.client.statement("account", 10, 20)
        self.client.client_info()
        self.assertEqual(self.clock.sleeps, [])
        self.client.client_info()
        self.assertEqual(self.clock.sleeps, [30, 30])

    def test_cross_process_lock_blocks_transport_until_released(self):
        ready, release = multiprocessing.Event(), multiprocessing.Event()
        child = multiprocessing.Process(target=hold_lock,
                                        args=(str(self.state / "request.lock"), ready, release))
        child.start()
        self.addCleanup(child.join, 2)
        self.addCleanup(release.set)
        self.assertTrue(ready.wait(2))
        contacted = threading.Event()
        self.client._http.open.side_effect = lambda *args, **kwargs: (contacted.set() or self.respond({}))
        errors = []

        def request():
            try:
                self.client.client_info()
            except Exception as error:
                errors.append(error)

        thread = threading.Thread(target=request)
        with patch("monobank_api.time.sleep", REAL_SLEEP):
            thread.start()
            try:
                self.assertFalse(contacted.wait(0.1))
            finally:
                release.set()
                thread.join(2)
                child.join(2)
        self.assertFalse(thread.is_alive())
        self.assertTrue(contacted.is_set())
        self.assertEqual(errors, [])

    def test_429_returns_delay_and_persists_server_deadline_without_body_leak(self):
        body = io.BytesIO((self.TOKEN + " private account data").encode())
        self.client._http.open.side_effect = urllib.error.HTTPError(
            "https://api.monobank.ua/private-account", 429, self.TOKEN, {"Retry-After": "93"}, body)
        with self.assertRaises(RateLimitError) as caught:
            self.client.client_info()
        self.assertEqual(caught.exception.status, 429)
        self.assertEqual(caught.exception.retry_after, 93)
        self.assertNotIn(self.TOKEN, str(caught.exception))
        self.assertNotIn("private-account", str(caught.exception))
        self.assertEqual(body.tell(), 0)
        self.assertEqual(json.loads((self.state / "rate-limits.json").read_text())["client-info"], 1093)

    def test_429_uses_sixty_seconds_for_missing_or_invalid_header(self):
        for headers in ({}, {"Retry-After": "not-a-delay"}, {"Retry-After": "inf"}):
            with self.subTest(headers=headers):
                self.client._http.open.side_effect = urllib.error.HTTPError(
                    "https://api.monobank.ua/", 429, "limit", headers, io.BytesIO())
                with self.assertRaises(RateLimitError) as caught:
                    self.client.client_info()
                self.assertEqual(caught.exception.retry_after, 60)

    def test_429_accepts_http_date_header(self):
        self.client._http.open.side_effect = urllib.error.HTTPError(
            "https://api.monobank.ua/", 429, "limit",
            {"Retry-After": "Thu, 01 Jan 1970 00:18:20 GMT"}, io.BytesIO())
        with self.assertRaises(RateLimitError) as caught:
            self.client.client_info()
        self.assertEqual(caught.exception.retry_after, 100)
        self.assertEqual(json.loads((self.state / "rate-limits.json").read_text())["client-info"], 1100)

    def test_huge_retry_after_is_persisted_without_unbounded_wait(self):
        self.client._http.open.side_effect = urllib.error.HTTPError(
            "https://api.monobank.ua/", 429, "limit", {"Retry-After": "1e100"}, io.BytesIO())
        with self.assertRaises(RateLimitError) as caught:
            self.client.client_info()
        retry_after = caught.exception.retry_after
        self.assertGreater(retry_after, 1e90)
        self.client._http.open.reset_mock()
        with self.assertRaises(RateLimitError) as caught:
            self.client.client_info()
        self.assertGreater(caught.exception.retry_after, 1e90)
        self.assertEqual(self.clock.sleeps, [])
        self.client._http.open.assert_not_called()
        self.client._http.open.side_effect = None
        self.respond([])
        self.client.statement("account", 10, 20)
        self.client._http.open.assert_called_once()

    def test_persisted_future_deadline_defers_without_early_request(self):
        state_file = self.state / "rate-limits.json"
        state_file.write_text(json.dumps({"statement": self.clock.now + 3600}))
        state_file.chmod(0o600)
        with self.assertRaises(RateLimitError) as caught:
            self.client.statement("account", 10, 20)
        self.assertEqual(caught.exception.retry_after, 3600)
        self.assertEqual(self.clock.sleeps, [])
        self.client._http.open.assert_not_called()
        self.client.client_info()
        self.client._http.open.assert_called_once()

    def test_busy_lock_wait_is_bounded_by_monotonic_budget(self):
        def advance_only_monotonic(delay):
            self.clock.sleeps.append(delay)
            self.clock.elapsed += delay

        with patch("monobank_api.fcntl.flock", side_effect=BlockingIOError) as flock, \
                patch("monobank_api.time.sleep", advance_only_monotonic):
            with self.assertRaises(RateLimitError):
                self.client.client_info()
        self.assertAlmostEqual(sum(self.clock.sleeps), self.client.MAX_WAIT_SECONDS)
        self.assertEqual(self.clock.now, 1000)
        self.assertLessEqual(max(self.clock.sleeps), self.client.LOCK_POLL_SECONDS)
        self.client._http.open.assert_not_called()
        self.assertTrue(all(call.args[1] & fcntl.LOCK_NB for call in flock.call_args_list))

    def test_rate_wait_releases_lock_and_shares_total_wait_budget(self):
        self.respond([])
        self.client.statement("first", 10, 20)
        acquired_during_wait = []

        def advance(delay):
            import fcntl
            descriptor = os.open(self.state / "request.lock", os.O_RDWR)
            with os.fdopen(descriptor, "a+") as stream:
                fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
                acquired_during_wait.append(True)
            self.clock.sleep(delay)

        with patch("monobank_api.time.sleep", advance):
            self.client.statement("second", 10, 20)
        self.assertTrue(acquired_during_wait)
        self.assertEqual(self.clock.sleeps, [30, 30])

        # Another caller extends the deadline after each wait; the budget must not reset.
        self.client._http.open.reset_mock()
        state_file = self.state / "rate-limits.json"
        state_file.write_text(json.dumps({"statement": self.clock.now + 60}))

        def extend(delay):
            self.clock.sleep(delay)
            state_file.write_text(json.dumps({"statement": self.clock.now + 60}))

        start_elapsed = self.clock.elapsed
        with patch("monobank_api.time.sleep", extend), self.assertRaises(RateLimitError):
            self.client.statement("third", 10, 20)
        self.assertLessEqual(self.clock.elapsed - start_elapsed, self.client.MAX_WAIT_SECONDS)
        self.client._http.open.assert_not_called()

    def test_corrupt_rate_limit_state_refuses_transport(self):
        state_file = self.state / "rate-limits.json"
        state_file.write_text('{"statement": "invalid timestamp"}')
        state_file.chmod(0o600)
        with self.assertRaises(ValueError):
            self.client.client_info()
        self.client._http.open.assert_not_called()

    def test_other_errors_do_not_reveal_url_body_or_credentials(self):
        self.client._http.open.side_effect = urllib.error.HTTPError(
            "https://api.monobank.ua/private-account", 401, self.TOKEN, {}, io.BytesIO(self.TOKEN.encode()))
        with self.assertRaises(APIError) as caught:
            self.client.client_info()
        self.assertEqual(caught.exception.status, 401)
        self.assertEqual(str(caught.exception), "Monobank HTTP 401")
        self.client._http.open.side_effect = urllib.error.URLError(self.TOKEN)
        with self.assertRaises(APIError) as caught:
            self.client.client_info()
        self.assertEqual(str(caught.exception), "Monobank connection failed")
        self.client._http.open.side_effect = None
        response = self.respond({})
        response.read1.side_effect = http.client.IncompleteRead(self.TOKEN.encode())
        with self.assertRaises(APIError) as caught:
            self.client.client_info()
        self.assertEqual(str(caught.exception), "Monobank connection failed")

    def test_invalid_response_is_not_echoed(self):
        response = self.respond({})
        response.read1.side_effect = [self.TOKEN.encode(), b""]
        with self.assertRaises(APIError) as caught:
            self.client.client_info()
        self.assertEqual(str(caught.exception), "Monobank returned invalid JSON")

    def test_slow_trickle_stops_at_total_deadline_and_releases_lock(self):
        response = self.respond({})
        chunks = iter([b'{"value":"'] + [b"x"] * 20 + [b'"}', b""])

        def trickle(size):
            self.clock.now += 10
            self.clock.elapsed += 10
            return next(chunks)

        response.read1.side_effect = trickle
        with self.assertRaises(APIError) as caught:
            self.client.client_info()
        self.assertEqual(str(caught.exception), "Monobank response deadline exceeded")
        self.assertEqual(self.clock.elapsed, self.client.MAX_WAIT_SECONDS)
        self.assertEqual(response.read1.call_count, 12)
        response.read.assert_not_called()
        with (self.state / "request.lock").open("a+") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_oversized_response_is_rejected_after_reading_one_excess_byte(self):
        response = self.respond({"value": self.TOKEN})
        self.client.MAX_RESPONSE_BYTES = 8
        with self.assertRaises(APIError) as caught:
            self.client.client_info()
        self.assertEqual(str(caught.exception), "Monobank response exceeds size limit")
        response.read1.assert_called_once_with(9)
        self.assertEqual(response.stream.tell(), 9)
        response.read.assert_not_called()

    def test_response_at_exact_size_limit_is_accepted(self):
        response = self.respond([1])
        self.client.MAX_RESPONSE_BYTES = 3
        self.assertEqual(self.client.client_info(), [1])
        self.assertEqual([call.args[0] for call in response.read1.call_args_list], [4, 1])

    def test_private_token_and_state_are_required(self):
        self.token.chmod(0o644)
        with self.assertRaises(PermissionError):
            Client(self.token, self.state)
        self.token.chmod(0o600)
        self.state.chmod(0o755)
        with self.assertRaises(PermissionError):
            Client(self.token, self.state)

    def test_proxy_environment_and_redirects_are_not_used(self):
        with patch("urllib.request.getproxies", return_value={"https": "http://proxy.invalid"}) as proxies:
            client = Client(self.token, self.state)
        proxies.assert_not_called()
        self.assertTrue(any(isinstance(handler, NoRedirect) for handler in client._http.handlers))
        self.assertIsNone(NoRedirect().redirect_request(None, None, 302, "redirect", {}, "https://other.invalid"))


if __name__ == "__main__":
    unittest.main()
