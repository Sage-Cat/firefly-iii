import http.client
import io
import json
import multiprocessing
import os
import stat
import tempfile
import unittest
import urllib.error
import urllib.parse
import urllib.request
from email.message import Message
from email.utils import formatdate
from pathlib import Path
from unittest.mock import Mock, patch

from privat_fop_api import APIError, Client, NoRedirect, RateLimitError


class Clock:
    def __init__(self):
        self.wall = 1000
        self.elapsed = 0
        self.sleeps = []
        self.advance_wall = True

    def time(self):
        return self.wall

    def monotonic(self):
        return self.elapsed

    def sleep(self, delay):
        self.sleeps.append(delay)
        self.elapsed += delay
        if self.advance_wall:
            self.wall += delay


def page(kind, rows=None, more=False, cursor=None):
    result = {"status": "SUCCESS", "type": kind, kind: rows or [], "exist_next_page": more}
    if cursor is not None:
        result["next_page_id"] = cursor
    return result


def try_fifo_token(token, state, result):
    try:
        Client(token, state)
    except PermissionError:
        result.put("rejected")


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
        replacements = [patch("privat_fop_api.time.time", self.clock.time),
                        patch("privat_fop_api.time.monotonic", self.clock.monotonic),
                        patch("privat_fop_api.time.sleep", self.clock.sleep)]
        for replacement in replacements:
            replacement.start()
            self.addCleanup(replacement.stop)
        self.client = Client(self.token, self.state)
        self.client._http = Mock()

    def response(self, payload=None, *, raw=None, charset="utf-8", content_type=True):
        content = raw if raw is not None else json.dumps(payload, ensure_ascii=False).encode(charset)
        response = Mock()
        response.headers = Message()
        if content_type:
            response.headers["Content-Type"] = "application/json;charset=" + charset
        response.stream = io.BytesIO(content)
        response.read1.side_effect = response.stream.read1
        response.read.side_effect = AssertionError("Unbounded read is forbidden")
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        return response

    def settings(self, **overrides):
        result = {"status": "SUCCESS", "type": "settings", "settings": {"phase": "WRK"}}
        result.update(overrides)
        return result

    def test_settings_uses_documented_token_headers_and_private_state(self):
        payload = self.settings()
        self.client._http.open.return_value = self.response(payload)
        self.assertEqual(self.client.settings(), payload)
        request = self.client._http.open.call_args.args[0]
        self.assertEqual(request.full_url, "https://acp.privatbank.ua/api/statements/settings")
        self.assertEqual(request.method, "GET")
        self.assertIsNone(request.data)
        self.assertEqual(request.get_header("Token"), self.TOKEN)
        self.assertEqual(request.get_header("Content-type"), "application/json;charset=cp1251")
        self.assertTrue(request.get_header("User-agent"))
        self.assertIsNone(request.get_header("Authorization"))
        self.assertIsNone(request.get_header("Id"))
        self.assertEqual(self.client._http.open.call_args.kwargs["timeout"], 30)
        self.assertNotIn(self.TOKEN, repr(self.client))
        self.assertEqual(stat.S_IMODE(self.state.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((self.state / "request.lock").stat().st_mode), 0o600)

    def test_no_environment_proxy_or_redirect_is_enabled(self):
        with patch.dict(os.environ, {"https_proxy": "https://example.invalid:1"}):
            with patch("privat_fop_api.urllib.request.build_opener") as build:
                Client(self.token, self.root / "second-state")
        proxy, redirect = build.call_args.args
        self.assertIsInstance(proxy, urllib.request.ProxyHandler)
        self.assertEqual(proxy.proxies, {})
        self.assertIsInstance(redirect, NoRedirect)
        self.assertIsNone(redirect.redirect_request(None, None, 302, None, None,
                                                  "https://example.invalid/steal"))

    def test_balances_format_dates_encode_account_and_preserve_raw_amounts(self):
        rows = [{"acc": "synthetic-account", "currency": "EUR", "balanceIn": "12.30",
                 "balanceOut": "45.67", "dpd": "07.01.2020 02:58:00", "is_final_bal": True}]
        self.client._http.open.return_value = self.response(page("balances", rows))
        self.assertEqual(self.client.balances("2020-01-02", "2020-02-03", "UA-test&ID=other"), rows)
        request = self.client._http.open.call_args.args[0]
        url = urllib.parse.urlsplit(request.full_url)
        self.assertEqual(url.path, "/api/statements/balance")
        self.assertEqual(urllib.parse.parse_qs(url.query),
                         {"acc": ["UA-test&ID=other"], "startDate": ["02-01-2020"],
                          "endDate": ["03-02-2020"], "limit": ["100"]})
        self.assertIs(type(rows[0]["balanceOut"]), str)

    def test_omitted_account_and_end_date_request_all_active_accounts(self):
        self.client._http.open.return_value = self.response(page("balances"))
        self.assertEqual(self.client.balances("2020-01-02"), [])
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(
            self.client._http.open.call_args.args[0].full_url).query)
        self.assertEqual(query, {"startDate": ["02-01-2020"], "limit": ["100"]})

    def test_dates_and_account_are_validated_before_network(self):
        for start, end in [(None, None), ("20200102", None), ("2020-1-02", None),
                           ("2020-02-30", None), ("02-01-2020", None),
                           ("2020-01-02", "2020-01-01"), ("2020-01-02", 42)]:
            with self.subTest(start=start, end=end), self.assertRaises(ValueError):
                self.client.transactions(start, end)
        for account in ["", 42, "bad\naccount", "bad account", "x" * 257]:
            with self.subTest(account=account), self.assertRaises(ValueError):
                self.client.balances("2020-01-02", account=account)
        self.client._http.open.assert_not_called()

    def test_same_date_and_leap_day_are_valid(self):
        self.client._http.open.return_value = self.response(page("transactions"))
        self.assertEqual(self.client.transactions("2020-02-29", "2020-02-29"), [])

    def test_early_years_are_formatted_with_four_digits(self):
        self.client._http.open.return_value = self.response(page("balances"))
        self.client.balances("0001-01-01", "0002-02-02")
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(
            self.client._http.open.call_args.args[0].full_url).query)
        self.assertEqual(query["startDate"], ["01-01-0001"])
        self.assertEqual(query["endDate"], ["02-02-0002"])

    def test_transactions_follow_opaque_cursor_and_keep_filters(self):
        rows = [{"REF": "one", "REFN": "1", "SUM": "0.01"}, {"REF": "two", "REFN": "2"}]
        self.client._http.open.side_effect = [
            self.response(page("transactions", rows[:1], True, "cursor/&ID=other?")),
            self.response(page("transactions", rows[1:], False, "ignored"))]
        self.assertEqual(self.client.transactions("2020-01-02", "2020-01-03", "synthetic"), rows)
        calls = self.client._http.open.call_args_list
        first = urllib.parse.parse_qs(urllib.parse.urlsplit(calls[0].args[0].full_url).query)
        second = urllib.parse.parse_qs(urllib.parse.urlsplit(calls[1].args[0].full_url).query)
        self.assertNotIn("followId", first)
        self.assertEqual(second, dict(first, followId=["cursor/&ID=other?"]))
        self.assertEqual(len(calls), 2)

    def test_balances_also_paginate(self):
        self.client._http.open.side_effect = [self.response(page("balances", [{"acc": "one"}], True, "next")),
                                            self.response(page("balances", [{"acc": "two"}]))]
        self.assertEqual(self.client.balances("2020-01-02"), [{"acc": "one"}, {"acc": "two"}])

    def test_request_spacing_is_shared_across_endpoints_accounts_clients_and_pages(self):
        responses = [self.response(self.settings()), self.response(page("balances")),
                     self.response(page("transactions", [{}], True, "next")),
                     self.response(page("transactions", [{}]))]
        started = []

        def open_request(request, timeout):
            started.append(self.clock.time())
            return responses.pop(0)

        self.client._http.open.side_effect = open_request
        self.client.settings()
        self.client.balances("2020-01-02", account="first")
        other = Client(self.token, self.state)
        other._http = self.client._http
        self.assertEqual(other.transactions("2020-01-02", account="second"), [{}, {}])
        self.assertEqual(started, [1000, 1001, 1002, 1003])
        self.assertEqual(self.clock.sleeps, [1, 1, 1])
        spacing = self.state / "request-spacing.json"
        self.assertEqual(json.loads(spacing.read_text()), {"next_request_at": 1004})
        self.assertEqual(stat.S_IMODE(spacing.stat().st_mode), 0o600)
        self.assertNotIn(self.TOKEN, spacing.read_text())

    def test_request_spacing_is_persisted_before_an_unsuccessful_get(self):
        reserved = []

        def failed_request(request, timeout):
            reserved.append(json.loads((self.state / "request-spacing.json").read_text()))
            raise urllib.error.URLError("synthetic failure")

        self.client._http.open.side_effect = failed_request
        with self.assertRaises(APIError):
            self.client.settings()
        with self.assertRaises(APIError):
            self.client.balances("2020-01-02")
        self.assertEqual(reserved, [{"next_request_at": 1001}, {"next_request_at": 1002}])
        self.assertEqual(self.clock.sleeps, [1])

    def test_delayed_state_write_keeps_actual_http_starts_one_second_apart(self):
        outcomes = ["success", "http-error", "connection-error", "invalid-json",
                    "invalid-encoding", "unsupported-charset", "unsuccessful-status"]
        for outcome in outcomes:
            with self.subTest(outcome=outcome):
                self.clock.wall = 1000
                self.clock.elapsed = 0
                client = Client(self.token, self.root / outcome)
                client._http = Mock()
                original_save = client._save_timestamp
                writes = []
                started = []

                def delayed_save(filename, key, value):
                    if not writes:
                        self.clock.wall += 0.9
                        self.clock.elapsed += 0.9
                    writes.append((filename, value))
                    original_save(filename, key, value)

                def open_request(request, timeout):
                    started.append(self.clock.time())
                    if len(started) == 1:
                        if outcome == "http-error":
                            raise urllib.error.HTTPError(request.full_url, 500, "failure", {}, None)
                        if outcome == "connection-error":
                            raise urllib.error.URLError("synthetic failure")
                        if outcome == "invalid-json":
                            return self.response(raw=b"invalid")
                        if outcome == "invalid-encoding":
                            return self.response(raw=b"\xff")
                        if outcome == "unsupported-charset":
                            return self.response(raw=b"{}", charset="latin-1")
                        if outcome == "unsuccessful-status":
                            return self.response(self.settings(status="ERROR"))
                    return self.response(self.settings())

                client._save_timestamp = delayed_save
                client._http.open.side_effect = open_request
                if outcome == "success":
                    client.settings()
                else:
                    with self.assertRaises(APIError):
                        client.settings()
                self.assertEqual(started, [1000.9])
                spacing = json.loads((client._state_dir / "request-spacing.json").read_text())
                self.assertAlmostEqual(spacing["next_request_at"], started[0] + 1)
                client.settings()
                self.assertGreaterEqual(started[1] - started[0], 1)

    def test_response_completion_advances_spacing_under_the_request_lock(self):
        starts = []
        response = self.response(self.settings())

        def close_response(*arguments):
            self.clock.wall += 3
            self.clock.elapsed += 3

        response.__exit__.side_effect = close_response

        def open_request(request, timeout):
            starts.append(self.clock.time())
            return response if len(starts) == 1 else self.response(self.settings())

        self.client._http.open.side_effect = open_request
        self.client.settings()
        self.assertEqual(json.loads((self.state / "request-spacing.json").read_text()),
                         {"next_request_at": 1004})
        other = Client(self.token, self.state)
        other._http = self.client._http
        other.settings()
        self.assertEqual(starts, [1000, 1004])

    def test_elapsed_spacing_needs_no_extra_sleep(self):
        self.client._http.open.side_effect = [self.response(self.settings()), self.response(self.settings())]
        self.client.settings()
        self.clock.wall += 2
        self.clock.elapsed += 2
        self.client.settings()
        self.assertEqual(self.clock.sleeps, [])

    def test_huge_persisted_spacing_defers_without_sleep_or_early_request(self):
        spacing = self.state / "request-spacing.json"
        spacing.write_text('{"next_request_at": 1e100}')
        spacing.chmod(0o600)
        with self.assertRaises(RateLimitError) as raised:
            self.client.settings()
        self.assertGreaterEqual(raised.exception.retry_after, 10 ** 99)
        self.client._http.open.assert_not_called()
        self.assertEqual(self.clock.sleeps, [])

    def test_spacing_cannot_exceed_the_total_request_budget(self):
        self.client.MAX_CALL_SECONDS = 0.5
        spacing = self.state / "request-spacing.json"
        spacing.write_text('{"next_request_at": 1001}')
        spacing.chmod(0o600)
        with self.assertRaises(RateLimitError) as raised:
            self.client.settings()
        self.assertEqual(raised.exception.retry_after, 1)
        self.client._http.open.assert_not_called()
        self.assertEqual(self.clock.sleeps, [])
        self.assertEqual(json.loads(spacing.read_text()), {"next_request_at": 1001})

    def test_spacing_wait_uses_monotonic_time_when_wall_clock_is_frozen(self):
        self.clock.advance_wall = False
        self.client._http.open.side_effect = [self.response(self.settings()), self.response(self.settings())]
        self.client.settings()
        self.client.settings()
        self.assertEqual(self.clock.elapsed, 1)
        self.assertEqual(self.clock.wall, 1000)
        self.assertEqual(self.clock.sleeps, [1])
        self.assertEqual(self.client._http.open.call_count, 2)

    def test_corrupt_or_public_request_spacing_cannot_trigger_network(self):
        spacing = self.state / "request-spacing.json"
        for content in [self.TOKEN, '[]', '{"next_request_at": true}',
                        '{"next_request_at": -1}', '{"next_request_at": NaN}']:
            spacing.write_text(content)
            spacing.chmod(0o600)
            with self.subTest(content=content), self.assertRaises(ValueError) as raised:
                self.client.settings()
            self.assertNotIn(self.TOKEN, str(raised.exception))
        spacing.write_text('{"next_request_at": 1001}')
        spacing.chmod(0o644)
        with self.assertRaises(PermissionError):
            self.client.settings()
        self.client._http.open.assert_not_called()

    def test_pagination_rejects_cycle_without_exposing_cursor(self):
        cursor = "sensitive-cursor"
        self.client._http.open.side_effect = [self.response(page("balances", [], True, cursor)),
                                            self.response(page("balances", [], True, cursor))]
        with self.assertRaises(APIError) as raised:
            self.client.balances("2020-01-02")
        self.assertNotIn(cursor, str(raised.exception))
        self.assertEqual(self.client._http.open.call_count, 2)

    def test_invalid_pagination_is_rejected(self):
        for cursor in [None, "", 123, "bad\ncursor", "x" * 2049]:
            self.client._http.open.return_value = self.response(page("balances", [], True, cursor))
            with self.subTest(cursor=cursor), self.assertRaises(APIError):
                self.client.balances("2020-01-02")
        result = page("balances")
        result["exist_next_page"] = "false"
        self.client._http.open.return_value = self.response(result)
        with self.assertRaises(APIError):
            self.client.balances("2020-01-02")

    def test_page_and_row_limits_are_bounded(self):
        self.client.MAX_PAGES = 2
        self.client._http.open.side_effect = [self.response(page("balances", [], True, "one")),
                                            self.response(page("balances", [], True, "two"))]
        with self.assertRaisesRegex(APIError, "page limit"):
            self.client.balances("2020-01-02")
        self.assertEqual(self.client._http.open.call_count, 2)
        self.client._http.open.side_effect = None
        self.client.MAX_ROWS = 1
        self.client._http.open.return_value = self.response(page("balances", [{}, {}]))
        with self.assertRaisesRegex(APIError, "row limit"):
            self.client.balances("2020-01-02")

    def test_invalid_outer_status_type_settings_and_rows_are_sanitized(self):
        for payload in [None, [], self.settings(status="ERROR", message=self.TOKEN),
                        self.settings(type="other"), self.settings(settings=[]),
                        self.settings(settings=None)]:
            self.client._http.open.return_value = self.response(payload)
            with self.subTest(payload=payload), self.assertRaises(APIError) as raised:
                self.client.settings()
            self.assertNotIn(self.TOKEN, str(raised.exception))
        for rows in [None, {}, ["private-value"], [{}] * 501]:
            payload = page("balances")
            payload["balances"] = rows
            self.client._http.open.return_value = self.response(payload)
            with self.subTest(rows=type(rows)), self.assertRaises(APIError):
                self.client.balances("2020-01-02")

    def test_utf8_cp1251_and_default_cp1251_are_decoded(self):
        payload = self.settings(settings={"phase": "WRK", "name": "Українська назва"})
        for charset, content_type in [("utf-8", True), ("utf8", True), ("cp1251", True),
                                      ("windows-1251", True), ("cp1251", False)]:
            self.client._http.open.return_value = self.response(payload, charset=charset,
                                                               content_type=content_type)
            with self.subTest(charset=charset, content_type=content_type):
                self.assertEqual(self.client.settings(), payload)

    def test_invalid_json_encoding_and_unsupported_charset_are_sanitized(self):
        for content, charset in [(self.TOKEN.encode(), "utf-8"), (b"\xff", "utf-8"),
                                 (b"{}", "latin-1")]:
            self.client._http.open.return_value = self.response(raw=content, charset=charset)
            with self.subTest(charset=charset), self.assertRaises(APIError) as raised:
                self.client.settings()
            self.assertNotIn(self.TOKEN, str(raised.exception))

    def test_http_errors_reveal_only_status_and_do_not_read_body(self):
        for code in [302, 400, 401, 403, 500]:
            error = urllib.error.HTTPError("https://secret.invalid/?acc=private", code,
                                           self.TOKEN, {"Sensitive": self.TOKEN}, io.BytesIO(self.TOKEN.encode()))
            error.read = Mock(side_effect=AssertionError("Error body must not be read"))
            error.close = Mock(wraps=error.close)
            self.client._http.open.side_effect = error
            with self.subTest(code=code), self.assertRaises(APIError) as raised:
                self.client.balances("2020-01-02", account="private-account")
            self.assertEqual(str(raised.exception), f"Privat FOP HTTP {code}")
            self.assertEqual(raised.exception.status, code)
            error.read.assert_not_called()
            error.close.assert_called_once()

    def test_connection_errors_do_not_expose_urls_or_credentials(self):
        for error in [urllib.error.URLError(self.TOKEN), OSError(self.TOKEN),
                      TimeoutError(self.TOKEN), http.client.IncompleteRead(self.TOKEN.encode())]:
            self.client._http.open.side_effect = error
            with self.subTest(error=type(error)), self.assertRaises(APIError) as raised:
                self.client.settings()
            self.assertEqual(str(raised.exception), "Privat FOP connection failed")

    def test_429_persists_private_cooldown_and_defers_all_endpoints(self):
        self.client._http.open.side_effect = urllib.error.HTTPError(
            "https://secret.invalid/?acc=private", 429, self.TOKEN, {"Retry-After": "90"},
            io.BytesIO(self.TOKEN.encode()))
        with self.assertRaises(RateLimitError) as raised:
            self.client.balances("2020-01-02")
        self.assertEqual(raised.exception.retry_after, 90)
        cooldown = self.state / "cooldown.json"
        self.assertEqual(stat.S_IMODE(cooldown.stat().st_mode), 0o600)
        self.assertEqual(json.loads(cooldown.read_text()), {"retry_until": 1090})
        self.assertNotIn(self.TOKEN, cooldown.read_text())
        other = Client(self.token, self.state)
        other._http = Mock()
        with self.assertRaises(RateLimitError) as raised:
            other.settings()
        self.assertEqual(raised.exception.retry_after, 90)
        other._http.open.assert_not_called()
        self.assertEqual(self.clock.sleeps, [])
        self.clock.wall = 1090
        other._http.open.return_value = self.response(self.settings())
        self.assertEqual(other.settings(), self.settings())

    def test_retry_after_parses_http_date_and_falls_back_safely(self):
        for header, expected in [(formatdate(1061, usegmt=True), 61), (None, 60),
                                  ("nonsense", 60), ("NaN", 60), ("inf", 60), ("-1", 1), ("0.5", 1)]:
            headers = {"Retry-After": header} if header is not None else {}
            self.client._http.open.side_effect = urllib.error.HTTPError(
                "https://secret.invalid", 429, self.TOKEN, headers, None)
            with self.subTest(header=header), self.assertRaises(RateLimitError) as raised:
                self.client.settings()
            self.assertEqual(raised.exception.retry_after, expected)
            (self.state / "cooldown.json").unlink()

    def test_huge_retry_after_is_respected_without_sleep_or_early_network(self):
        self.client._http.open.side_effect = urllib.error.HTTPError(
            "https://secret.invalid", 429, self.TOKEN, {"Retry-After": "1e100"}, None)
        with self.assertRaises(RateLimitError) as raised:
            self.client.settings()
        self.assertGreaterEqual(raised.exception.retry_after, 10 ** 99)
        self.client._http.open.reset_mock()
        with self.assertRaises(RateLimitError):
            self.client.transactions("2020-01-02")
        self.client._http.open.assert_not_called()
        self.assertEqual(self.clock.sleeps, [])

    def test_busy_lock_has_monotonic_deadline_without_real_sleep(self):
        self.client.MAX_CALL_SECONDS = 1
        self.clock.advance_wall = False
        with patch("privat_fop_api.fcntl.flock", side_effect=BlockingIOError):
            with self.assertRaisesRegex(APIError, "busy"):
                self.client.settings()
        self.assertGreaterEqual(self.clock.elapsed, 1)
        self.assertLessEqual(self.clock.elapsed, 1.1)
        self.assertEqual(self.clock.wall, 1000)
        self.client._http.open.assert_not_called()

    def test_slow_stream_and_total_pagination_have_a_shared_deadline(self):
        first = self.response(page("balances", [{}], True, "next"))
        second = self.response(page("balances", [{}]))

        def slow_read(response, size):
            self.clock.elapsed += 31
            return response.stream.read1(size)

        first.read1.side_effect = lambda size: slow_read(first, size)
        second.read1.side_effect = lambda size: slow_read(second, size)
        self.client._http.open.side_effect = [first, second]
        with self.assertRaisesRegex(APIError, "deadline exceeded"):
            self.client.balances("2020-01-02")
        self.assertEqual(self.client._http.open.call_count, 2)
        first.read.assert_not_called()
        second.read.assert_not_called()

    def test_response_size_is_bounded_with_one_byte_overrun_probe(self):
        self.client.MAX_RESPONSE_BYTES = 128
        response = self.response(raw=b"x" * 1000)
        self.client._http.open.return_value = response
        with self.assertRaisesRegex(APIError, "size limit"):
            self.client.settings()
        self.assertEqual(response.stream.tell(), 129)
        response.read.assert_not_called()

    def test_token_file_must_be_private_regular_and_not_a_symlink(self):
        for mode in [0o644, 0o660, 0o400]:
            self.token.chmod(mode)
            with self.subTest(mode=mode), self.assertRaises(PermissionError):
                Client(self.token, self.state)
        self.token.chmod(0o600)
        link = self.root / "link"
        link.symlink_to(self.token)
        with self.assertRaises(OSError):
            Client(link, self.state)
        with self.assertRaises(PermissionError):
            Client(self.root, self.state)

    def test_state_directory_and_existing_state_files_are_private(self):
        self.state.chmod(0o755)
        with self.assertRaises(PermissionError):
            Client(self.token, self.state)
        self.state.chmod(0o700)
        link = self.root / "state-link"
        link.symlink_to(self.state, target_is_directory=True)
        with self.assertRaises(PermissionError):
            Client(self.token, link)
        lock = self.state / "request.lock"
        lock.write_text("")
        lock.chmod(0o644)
        with self.assertRaises(PermissionError):
            self.client.settings()
        self.client._http.open.assert_not_called()

    def test_fifo_token_is_rejected_without_waiting_for_a_writer(self):
        fifo = self.root / "fifo-token"
        os.mkfifo(fifo, 0o600)
        context = multiprocessing.get_context("fork")
        result = context.Queue()
        process = context.Process(target=try_fifo_token, args=(fifo, self.state, result))
        process.start()
        try:
            process.join(2)
            self.assertFalse(process.is_alive(), "Token reads must not block on a FIFO")
            self.assertEqual(process.exitcode, 0)
            self.assertEqual(result.get(timeout=1), "rejected")
        finally:
            if process.is_alive():
                process.terminate()
                process.join(2)
            result.close()

    def test_invalid_token_cannot_inject_headers_or_expose_its_content(self):
        for value in ["", self.TOKEN + "\nInjected: value", "\u0000" + self.TOKEN,
                      "\u00e9" + self.TOKEN, "x" * 16_385]:
            self.token.write_text(value)
            with self.subTest(length=len(value)), self.assertRaises(ValueError) as raised:
                Client(self.token, self.state)
            self.assertNotIn(self.TOKEN, str(raised.exception))

    def test_corrupt_cooldown_does_not_trigger_network_or_leak_values(self):
        cooldown = self.state / "cooldown.json"
        for value in [self.TOKEN, "[]", '{"retry_until": "private-value"}',
                      '{"retry_until": -1}', '{"retry_until": NaN}',
                      '{"retry_until": true}', "x" * 4097]:
            cooldown.write_text(value)
            cooldown.chmod(0o600)
            with self.subTest(value=value[:20]), self.assertRaises(ValueError) as raised:
                self.client.settings()
            self.assertNotIn(self.TOKEN, str(raised.exception))
        self.client._http.open.assert_not_called()

    def test_non_allowlisted_paths_and_group_id_are_rejected(self):
        for path, query in [("/api/pay/something", {}), ("https://example.invalid", {}),
                            ("/api/statements/balance", {"ID": "other"}),
                            ("/api/statements/settings", {"acc": "other"})]:
            with self.subTest(path=path), self.assertRaises(ValueError):
                self.client._get(path, query, 120)
        self.client._http.open.assert_not_called()


if __name__ == "__main__":
    unittest.main()
