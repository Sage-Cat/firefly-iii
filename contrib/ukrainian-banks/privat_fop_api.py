"""Read-only PrivatBank FOP statements client with private credentials."""

import fcntl
import http.client
import json
import math
import os
import stat
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date
from email.message import Message
from email.utils import parsedate_to_datetime
from pathlib import Path


class APIError(RuntimeError):
    def __init__(self, status=None, message=None):
        self.status = status
        super().__init__(message or f"Privat FOP HTTP {status}")


class RateLimitError(APIError):
    def __init__(self, retry_after=60):
        self.retry_after = retry_after
        super().__init__(429, "Privat FOP rate limit; retry later")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def _private_file(path, flags):
    descriptor = os.open(path, flags | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    info = os.fstat(descriptor)
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid()
            or stat.S_IMODE(info.st_mode) != 0o600):
        os.close(descriptor)
        raise PermissionError("Privat FOP credential and state files must have mode 0600")
    return descriptor


def _retry_after(value):
    try:
        delay = float(value)
        if not math.isfinite(delay):
            raise ValueError
    except (ValueError, TypeError):
        try:
            delay = parsedate_to_datetime(value).timestamp() - time.time()
        except (ValueError, TypeError, AttributeError, OverflowError):
            return 60
    return max(1, math.ceil(delay))


def _iso_date(value):
    if not isinstance(value, str):
        raise ValueError("Privat FOP dates must use YYYY-MM-DD")
    try:
        parsed = date.fromisoformat(value)
        if parsed.isoformat() != value:
            raise ValueError
    except ValueError:
        raise ValueError("Privat FOP dates must use YYYY-MM-DD") from None
    return parsed


class Client:
    ORIGIN = "https://acp.privatbank.ua"
    PATHS = frozenset({"/api/statements/settings", "/api/statements/balance",
                       "/api/statements/transactions"})
    MAX_PAGES = 1000
    MAX_ROWS = 100_000
    MAX_CALL_SECONDS = 120
    MAX_RESPONSE_BYTES = 4 * 1024 * 1024
    READ_CHUNK_BYTES = 64 * 1024

    def __init__(self, token_file=None, state_dir=None):
        project = Path(__file__).resolve().parent
        token_path = Path(token_file) if token_file else project / ".state/privat-fop-token"
        self._state_dir = Path(state_dir) if state_dir else project / ".state/privat-fop"
        self._state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        info = self._state_dir.lstat()
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid()
                or stat.S_IMODE(info.st_mode) != 0o700):
            raise PermissionError("The Privat FOP state directory must have mode 0700")
        with os.fdopen(_private_file(token_path, os.O_RDONLY), encoding="ascii") as stream:
            try:
                self._token = stream.read(16_385).strip()
            except UnicodeDecodeError:
                raise ValueError("Invalid Privat FOP token file") from None
        if (not self._token or len(self._token) > 16_384
                or any(not 33 <= ord(character) <= 126 for character in self._token)):
            raise ValueError("Invalid Privat FOP token file")
        self._http = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def __repr__(self):
        return "<privat_fop_api.Client>"

    def settings(self):
        result = self._get("/api/statements/settings", {}, time.monotonic() + self.MAX_CALL_SECONDS)
        self._validate_result(result, "settings")
        if not isinstance(result.get("settings"), dict):
            raise APIError(message="Privat FOP returned invalid settings")
        return result

    def balances(self, start_date, end_date=None, account=None):
        return self._rows("balance", "balances", start_date, end_date, account)

    def transactions(self, start_date, end_date=None, account=None):
        return self._rows("transactions", "transactions", start_date, end_date, account)

    def _rows(self, endpoint, kind, start_date, end_date, account):
        start = _iso_date(start_date)
        end = _iso_date(end_date) if end_date is not None else None
        if end is not None and end < start:
            raise ValueError("Privat FOP end date must not precede start date")
        if account is not None and (not isinstance(account, str) or not account
                                    or len(account) > 256 or any(c.isspace() for c in account)
                                    or any(ord(c) < 32 for c in account)):
            raise ValueError("Invalid Privat FOP account identifier")
        parameters = {"startDate": f"{start.day:02d}-{start.month:02d}-{start.year:04d}", "limit": 100}
        if end is not None:
            parameters["endDate"] = f"{end.day:02d}-{end.month:02d}-{end.year:04d}"
        if account is not None:
            parameters["acc"] = account
        deadline = time.monotonic() + self.MAX_CALL_SECONDS
        result = []
        seen = set()
        for _ in range(self.MAX_PAGES):
            page = self._get("/api/statements/" + endpoint, parameters, deadline)
            self._validate_result(page, kind)
            rows = page.get(kind)
            if (not isinstance(rows, list) or len(rows) > 500
                    or any(not isinstance(row, dict) for row in rows)):
                raise APIError(message="Privat FOP returned invalid statement rows")
            result.extend(rows)
            if len(result) > self.MAX_ROWS:
                raise APIError(message="Privat FOP statement exceeds row limit")
            more = page.get("exist_next_page", False)
            if type(more) is not bool:
                raise APIError(message="Privat FOP returned invalid pagination")
            if not more:
                return result
            cursor = page.get("next_page_id")
            if (not isinstance(cursor, str) or not cursor or len(cursor) > 2048
                    or cursor in seen or any(ord(c) < 32 for c in cursor)):
                raise APIError(message="Privat FOP returned invalid pagination")
            seen.add(cursor)
            parameters["followId"] = cursor
        raise APIError(message="Privat FOP statement exceeds page limit")

    @staticmethod
    def _validate_result(result, kind):
        if not isinstance(result, dict) or result.get("status") != "SUCCESS":
            raise APIError(message="Privat FOP did not return a successful response")
        if result.get("type") != kind:
            raise APIError(message="Privat FOP returned an unexpected response type")

    def _timestamp(self, filename, key):
        try:
            descriptor = _private_file(self._state_dir / filename, os.O_RDONLY)
        except FileNotFoundError:
            return 0
        with os.fdopen(descriptor) as stream:
            try:
                content = stream.read(4097)
                if len(content) > 4096:
                    raise ValueError
                value = json.loads(content)[key]
            except (ValueError, KeyError, TypeError):
                raise ValueError("Invalid Privat FOP rate-limit state") from None
        if (type(value) not in (int, float) or not math.isfinite(value) or value < 0):
            raise ValueError("Invalid Privat FOP rate-limit state")
        return value

    def _save_timestamp(self, filename, key, value):
        descriptor, temporary = tempfile.mkstemp(prefix=".request-timing-", dir=self._state_dir)
        try:
            with os.fdopen(descriptor, "w") as stream:
                json.dump({key: value}, stream)
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, self._state_dir / filename)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

    def _wait_spacing(self, deadline):
        remaining = self._timestamp("request-spacing.json", "next_request_at") - time.time()
        if remaining > 0:
            now = time.monotonic()
            if remaining >= deadline - now:
                raise RateLimitError(math.ceil(remaining))
            eligible = now + remaining
            while True:
                now = time.monotonic()
                if now >= eligible:
                    break
                if now >= deadline:
                    raise APIError(message="Privat FOP request deadline exceeded")
                time.sleep(min(eligible - now, deadline - now, 30))
        if time.monotonic() >= deadline:
            raise APIError(message="Privat FOP request deadline exceeded")
        self._save_timestamp("request-spacing.json", "next_request_at", time.time() + 1)

    def _get(self, path, parameters, deadline):
        if (path not in self.PATHS
                or not set(parameters) <= {"acc", "startDate", "endDate", "limit", "followId"}
                or (path.endswith("/settings") and parameters)):
            raise ValueError("Only read-only Privat FOP statement endpoints are allowed")
        query = urllib.parse.urlencode(parameters)
        request = urllib.request.Request(self.ORIGIN + path + ("?" + query if query else ""),
                                         headers={"token": self._token,
                                                  "User-Agent": "Firefly-III-Privat-FOP-Sync/1.0",
                                                  "Content-Type": "application/json;charset=cp1251",
                                                  "Accept": "application/json"}, method="GET")
        descriptor = _private_file(self._state_dir / "request.lock", os.O_RDWR | os.O_CREAT)
        with os.fdopen(descriptor, "a+") as lock:
            self._acquire_lock(lock, deadline)
            remaining = self._timestamp("cooldown.json", "retry_until") - time.time()
            if remaining > 0:
                raise RateLimitError(math.ceil(remaining))
            self._wait_spacing(deadline)
            budget = deadline - time.monotonic()
            if budget <= 0:
                raise APIError(message="Privat FOP request deadline exceeded")
            try:
                with self._http.open(request, timeout=min(budget, 30)) as response:
                    content_type = Message()
                    content_type["Content-Type"] = response.headers.get("Content-Type", "")
                    charset = (content_type.get_content_charset() or "cp1251").lower()
                    if charset not in {"utf8", "utf-8", "cp1251", "windows-1251"}:
                        raise APIError(message="Privat FOP returned an unsupported charset")
                    content = self._read_body(response, deadline)
            except urllib.error.HTTPError as error:
                status = error.code
                retry_after = error.headers.get("Retry-After") if error.headers else None
                error.close()
                if status == 429:
                    delay = _retry_after(retry_after)
                    self._save_timestamp("cooldown.json", "retry_until", time.time() + delay)
                    raise RateLimitError(delay) from None
                raise APIError(status) from None
            except (urllib.error.URLError, http.client.HTTPException, TimeoutError, OSError):
                raise APIError(message="Privat FOP connection failed") from None
            finally:
                self._save_timestamp("request-spacing.json", "next_request_at", time.time() + 1)
        try:
            result = json.loads(content.decode(charset))
        except (ValueError, UnicodeDecodeError, RecursionError):
            raise APIError(message="Privat FOP returned invalid JSON") from None
        return result

    def _read_body(self, response, deadline):
        content = bytearray()
        while True:
            if time.monotonic() >= deadline:
                raise APIError(message="Privat FOP response deadline exceeded")
            chunk = response.read1(min(self.READ_CHUNK_BYTES, self.MAX_RESPONSE_BYTES + 1 - len(content)))
            if time.monotonic() >= deadline:
                raise APIError(message="Privat FOP response deadline exceeded")
            if not chunk:
                return content
            content.extend(chunk)
            if len(content) > self.MAX_RESPONSE_BYTES:
                raise APIError(message="Privat FOP response exceeds size limit")

    @staticmethod
    def _acquire_lock(lock, deadline):
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                return
            except BlockingIOError:
                budget = deadline - time.monotonic()
                if budget <= 0:
                    raise APIError(message="Privat FOP client is busy") from None
                time.sleep(min(0.1, budget))
