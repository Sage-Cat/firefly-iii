"""Read-only monobank client with private credentials and shared rate limits."""

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
from email.utils import parsedate_to_datetime
from pathlib import Path


class APIError(RuntimeError):
    def __init__(self, status=None, message=None):
        self.status = status
        super().__init__(message or f"Monobank HTTP {status}")


class RateLimitError(APIError):
    def __init__(self, retry_after=60):
        self.retry_after = retry_after
        super().__init__(429, f"Monobank rate limit; retry after {retry_after} seconds")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def _private_file(path, flags):
    descriptor = os.open(path, flags | os.O_NOFOLLOW, 0o600)
    info = os.fstat(descriptor)
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid()
            or stat.S_IMODE(info.st_mode) & 0o077):
        os.close(descriptor)
        raise PermissionError("Monobank credential and state files must be private")
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


class Client:
    MAX_STATEMENT_SECONDS = 2_682_000
    MAX_WAIT_SECONDS = 120
    LOCK_POLL_SECONDS = 0.1
    MAX_RESPONSE_BYTES = 4 * 1024 * 1024
    READ_CHUNK_BYTES = 64 * 1024

    def __init__(self, token_file=None, state_dir=None):
        project = Path(__file__).resolve().parent
        token_path = Path(token_file) if token_file else project / ".state/monobank-token"
        self._state_dir = Path(state_dir) if state_dir else project / ".state/monobank"
        self._state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        info = self._state_dir.lstat()
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid()
                or stat.S_IMODE(info.st_mode) != 0o700):
            raise PermissionError("The monobank state directory must have mode 0700")
        with os.fdopen(_private_file(token_path, os.O_RDONLY)) as stream:
            self._token = stream.read().strip()
        if not self._token or any(c.isspace() for c in self._token):
            raise ValueError("Invalid monobank token file")
        self._http = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def __repr__(self):
        return "<monobank_api.Client>"

    def client_info(self):
        return self._get("/personal/client-info", "client-info")

    def statement(self, account_id, from_ts, to_ts):
        if not isinstance(account_id, str) or not account_id or account_id in {".", ".."}:
            raise ValueError("A monobank account identifier is required")
        if (type(from_ts) is not int or type(to_ts) is not int or from_ts < 0
                or not 0 < to_ts - from_ts <= self.MAX_STATEMENT_SECONDS):
            raise ValueError("Statement timestamps must define a positive window of at most 2682000 seconds")
        account = urllib.parse.quote(account_id, safe="")
        return self._get(f"/personal/statement/{account}/{from_ts}/{to_ts}", "statement")

    def _limits(self):
        try:
            descriptor = _private_file(self._state_dir / "rate-limits.json", os.O_RDONLY)
        except FileNotFoundError:
            return {}
        with os.fdopen(descriptor) as stream:
            limits = json.load(stream)
        if (not isinstance(limits, dict)
                or any(not isinstance(v, (int, float)) or isinstance(v, bool)
                       or not math.isfinite(v) or v < 0 for v in limits.values())):
            raise ValueError("Invalid monobank rate-limit state")
        return limits

    def _save_limits(self, limits):
        descriptor, temporary = tempfile.mkstemp(prefix=".rate-limits-", dir=self._state_dir)
        try:
            with os.fdopen(descriptor, "w") as stream:
                json.dump(limits, stream)
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, self._state_dir / "rate-limits.json")
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

    def _get(self, path, family):
        deadline = time.monotonic() + self.MAX_WAIT_SECONDS
        request = urllib.request.Request("https://api.monobank.ua" + path,
                                         headers={"X-Token": self._token, "Accept": "application/json"},
                                         method="GET")
        descriptor = _private_file(self._state_dir / "request.lock", os.O_RDWR | os.O_CREAT)
        with os.fdopen(descriptor, "a+") as lock:
            while True:
                self._acquire_lock(lock, deadline)
                limits = self._limits()
                remaining = limits.get(family, 0) - time.time()
                if remaining <= 0:
                    break
                fcntl.flock(lock, fcntl.LOCK_UN)
                budget = deadline - time.monotonic()
                if remaining >= budget:
                    raise RateLimitError(math.ceil(remaining))
                time.sleep(min(remaining, budget, 30))
            budget = deadline - time.monotonic()
            if budget <= 0:
                raise RateLimitError(1)
            limits[family] = time.time() + 60
            self._save_limits(limits)
            try:
                with self._http.open(request, timeout=min(budget, 30)) as response:
                    result = self._read_body(response, deadline)
            except urllib.error.HTTPError as error:
                if error.code == 429:
                    delay = _retry_after(error.headers.get("Retry-After") if error.headers else None)
                    limits[family] = max(limits[family], time.time() + delay)
                    self._save_limits(limits)
                    raise RateLimitError(delay) from None
                raise APIError(error.code) from None
            except (urllib.error.URLError, http.client.HTTPException, TimeoutError, OSError):
                raise APIError(message="Monobank connection failed") from None
            try:
                return json.loads(result)
            except (ValueError, UnicodeDecodeError):
                raise APIError(message="Monobank returned invalid JSON") from None

    def _read_body(self, response, deadline):
        result = bytearray()
        while True:
            if time.monotonic() >= deadline:
                raise APIError(message="Monobank response deadline exceeded")
            chunk = response.read1(min(self.READ_CHUNK_BYTES, self.MAX_RESPONSE_BYTES + 1 - len(result)))
            if time.monotonic() >= deadline:
                raise APIError(message="Monobank response deadline exceeded")
            if not chunk:
                return result
            result.extend(chunk)
            if len(result) > self.MAX_RESPONSE_BYTES:
                raise APIError(message="Monobank response exceeds size limit")

    def _acquire_lock(self, lock, deadline):
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                return
            except BlockingIOError:
                budget = deadline - time.monotonic()
                if budget <= 0:
                    raise RateLimitError(1) from None
                time.sleep(min(self.LOCK_POLL_SECONDS, budget))
