"""Client for the local Firefly III instance."""

import hashlib
import json
import re
import stat
import urllib.error
import urllib.request
from datetime import datetime
from decimal import Decimal, InvalidOperation, localcontext
from pathlib import Path


def tag_fingerprint(tags):
    """Hash the exact set of API tag labels for a conditional tags-only update."""
    if not isinstance(tags, list) or not all(isinstance(tag, str) for tag in tags):
        raise ValueError("Invalid transaction tags")
    return hashlib.sha256(json.dumps(sorted(tags), ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def payment_fingerprint(group_id, journal):
    """Guard caller-visible owner metadata and financial scope before classification."""
    def identifier(value, optional=False):
        if optional and value in (None, ""):
            return ""
        value = str(value)
        if not re.fullmatch(r"[1-9][0-9]*", value):
            raise ValueError("Invalid payment identity")
        return value
    def amount(value, optional=False):
        if optional and value in (None, ""):
            return ""
        with localcontext() as context:
            context.prec = 80
            number = abs(Decimal(str(value)))
            fixed = number.quantize(Decimal("0.0000000000000001"))
            if not number.is_finite() or number != fixed or (not optional and not number):
                raise ValueError("Invalid payment amount")
            return format(fixed, ".16f") if number else ""
    date = datetime.fromisoformat(journal["date"])
    if date.tzinfo is None or date.microsecond:
        raise ValueError("Payment date requires whole seconds and timezone")
    tags = journal.get("tags") or []
    tag_fingerprint(tags)
    fields = [journal.get(key) or "" for key in ("source_name", "destination_name", "description", "notes")]
    if not all(isinstance(value, str) for value in fields):
        raise ValueError("Invalid payment text")
    state = ["payment-metadata-v1", identifier(group_id), identifier(journal["transaction_journal_id"]),
             journal["type"].lower(), str(int(date.timestamp())), amount(journal["amount"]), identifier(journal["currency_id"]),
             identifier(journal["source_id"]), identifier(journal["destination_id"]), *fields, sorted(tags)]
    state.extend(identifier(journal.get(key), True) for key in ("category_id", "budget_id", "bill_id", "foreign_currency_id"))
    state.append(amount(journal.get("foreign_amount"), True))
    state.extend(journal.get(key) or "" for key in ("external_id", "internal_reference", "original_source", "import_hash_v2"))
    return hashlib.sha256(json.dumps(state, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def internal_card_fingerprint(group_id, journal):
    """Hash an API journal for the narrow conditional staging DELETE contract."""
    def identifier(value):
        value = str(value)
        if not re.fullmatch(r"[1-9][0-9]*", value):
            raise ValueError("Invalid internal card identifier")
        return value

    date = datetime.fromisoformat(journal["date"])
    if date.tzinfo is None or date.microsecond:
        raise ValueError("Internal card date requires a timezone and whole seconds")
    try:
        with localcontext() as context:
            context.prec = 80
            amount = abs(Decimal(journal["amount"]))
            fixed = amount.quantize(Decimal("0.0000000000000001"))
            if not amount.is_finite() or amount != fixed or not amount:
                raise ValueError("Invalid internal card amount")
            amount = format(fixed, ".16f")
    except (InvalidOperation, TypeError) as error:
        raise ValueError("Invalid internal card amount") from error
    tags = journal.get("tags")
    if tags is None:
        tags = []
    if not isinstance(tags, list) or not all(isinstance(tag, str) for tag in tags):
        raise ValueError("Invalid internal card tags")
    description = journal["description"]
    notes = journal.get("notes")
    if notes is None:
        notes = ""
    if not isinstance(description, str) or not isinstance(notes, str):
        raise ValueError("Invalid internal card text")
    state = ["internal-card-v1", identifier(group_id), identifier(journal["transaction_journal_id"]),
             journal["type"].lower(), str(int(date.timestamp())), amount,
             identifier(journal["currency_id"]), identifier(journal["source_id"]),
             identifier(journal["destination_id"]), description, notes, sorted(tags)]
    for field in ("external_id", "internal_reference", "original_source", "import_hash_v2"):
        value = journal.get(field)
        if value is None:
            value = ""
        if not isinstance(value, str):
            raise ValueError("Invalid internal card metadata")
        state.append(value)
    return hashlib.sha256(json.dumps(state, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def bank_authorization_fingerprint(group_id, journal):
    """Hash the complete API snapshot used to revise or retire a bank hold."""
    def identifier(value, optional=False):
        if optional and value is None:
            return ""
        value = str(value)
        if not re.fullmatch(r"[1-9][0-9]*", value):
            raise ValueError("Invalid bank authorization identifier")
        return value

    date = datetime.fromisoformat(journal["date"])
    if date.tzinfo is None or date.microsecond:
        raise ValueError("Bank authorization date requires a timezone and whole seconds")
    try:
        with localcontext() as context:
            context.prec = 80
            amount = Decimal(journal["amount"])
            fixed = amount.quantize(Decimal("0.0000000000000001"))
            if not amount.is_finite() or amount != fixed or amount <= 0:
                raise ValueError("Invalid bank authorization amount")
            amount = format(fixed, ".16f")
    except (InvalidOperation, TypeError) as error:
        raise ValueError("Invalid bank authorization amount") from error
    if journal["type"].lower() != "withdrawal":
        raise ValueError("Bank authorization must be a withdrawal")
    tags = journal.get("tags")
    tags = [] if tags is None else tags
    if not isinstance(tags, list) or not all(isinstance(tag, str) for tag in tags):
        raise ValueError("Invalid bank authorization tags")
    description, notes = journal["description"], journal.get("notes")
    notes = "" if notes is None else notes
    if not isinstance(description, str) or not isinstance(notes, str):
        raise ValueError("Invalid bank authorization text")
    state = ["bank-authorization-v1", identifier(group_id), identifier(journal["transaction_journal_id"]),
             "withdrawal", str(int(date.timestamp())), amount, identifier(journal["currency_id"]),
             identifier(journal["source_id"]), identifier(journal["destination_id"]), description, notes, sorted(tags)]
    state.extend(identifier(journal.get(field), optional=True) for field in ("category_id", "budget_id", "bill_id"))
    for field in ("external_id", "internal_reference", "original_source", "import_hash_v2"):
        value = journal.get(field)
        value = "" if value is None else value
        if not isinstance(value, str):
            raise ValueError("Invalid bank authorization metadata")
        state.append(value)
    return hashlib.sha256(json.dumps(state, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, token_file=None):
        self.base = "http://127.0.0.1:8080/api/v1"
        path = Path(token_file) if token_file else Path(__file__).parent / ".state/api-token"
        if stat.S_IMODE(path.stat().st_mode) & 0o077:
            raise PermissionError("The API token file must be private")
        self._token = path.read_text().strip()
        if not self._token or any(c.isspace() for c in self._token):
            raise ValueError("Invalid API token file")
        self._http = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def _request(self, method, path, body=None, content_type=None, notes_hash=None, category_match=None,
                 internal_card_hash=None, tags_hash=None, bank_authorization_hash=None, category_default=False, payment_hash=None):
        if not path.startswith("/") or path.startswith("//") or "://" in path:
            raise ValueError("Only relative API paths are supported")
        if ".." in path.split("/"):
            raise ValueError("Parent path segments are not supported")
        headers = {"Authorization": "Bearer " + self._token, "Accept": "application/json"}
        if content_type:
            headers["Content-Type"] = content_type
        if notes_hash is not None:
            if not re.fullmatch(r"[0-9a-f]{64}", notes_hash):
                raise ValueError("Invalid account notes hash")
            headers["If-Match-Notes"] = notes_hash
        if category_match is not None:
            if not re.fullmatch(r"(?:none|[1-9][0-9]*)", category_match):
                raise ValueError("Invalid expected transaction category")
            headers["If-Match-Category"] = category_match
        if type(category_default) is not bool or (category_default and (
                method != "PUT" or not re.fullmatch(r"/transactions/[1-9][0-9]*", path)
                or category_match is None or any(value is not None for value in (
                    notes_hash, internal_card_hash, tags_hash, bank_authorization_hash)))):
            raise ValueError("Invalid default category update precondition")
        if category_default:
            headers["If-Category-Default"] = "1"
        if payment_hash is not None:
            if (not isinstance(payment_hash, str) or not re.fullmatch(r"[0-9a-f]{64}", payment_hash)
                    or method != "PUT" or not re.fullmatch(r"/transactions/[1-9][0-9]*", path)
                    or category_match is None or bank_authorization_hash is not None or internal_card_hash is not None):
                raise ValueError("Invalid payment metadata precondition")
            headers["If-Match-Payment"] = payment_hash
        if internal_card_hash is not None:
            if (not isinstance(internal_card_hash, str)
                    or not re.fullmatch(r"[0-9a-f]{64}", internal_card_hash)
                    or method != "DELETE" or not re.fullmatch(r"/transactions/[1-9][0-9]*", path)):
                raise ValueError("Invalid internal card delete precondition")
            headers["If-Match-Internal-Card"] = internal_card_hash
        if tags_hash is not None:
            if not isinstance(tags_hash, str) or not re.fullmatch(r"[0-9a-f]{64}", tags_hash):
                raise ValueError("Invalid transaction tags hash")
            headers["If-Match-Tags"] = tags_hash
        if bank_authorization_hash is not None:
            if (not isinstance(bank_authorization_hash, str)
                    or not re.fullmatch(r"[0-9a-f]{64}", bank_authorization_hash)
                    or method not in {"PUT", "DELETE"} or not re.fullmatch(r"/transactions/[1-9][0-9]*", path)
                    or any(value is not None for value in (notes_hash, category_match, internal_card_hash, tags_hash))):
                raise ValueError("Invalid bank authorization precondition")
            headers["If-Match-Bank-Authorization"] = bank_authorization_hash
        request = urllib.request.Request(self.base + path, data=body, headers=headers, method=method)
        try:
            with self._http.open(request, timeout=30) as response:
                return response.read()
        except urllib.error.HTTPError as error:
            details = error.read().decode(errors="replace")
            try:
                details = json.loads(details)
                details = {k: details[k] for k in ("message", "errors") if k in details}
            except ValueError:
                details = "Unexpected non-JSON response"
            raise RuntimeError(f"{method} {path}: HTTP {error.code}: {details}") from None

    def request(self, method, path, data=None, *, notes_hash=None, category_match=None, internal_card_hash=None,
                tags_hash=None, bank_authorization_hash=None, category_default=False, payment_hash=None):
        body = json.dumps(data, ensure_ascii=False).encode() if data is not None else None
        result = self._request(method, path, body, "application/json", notes_hash=notes_hash,
                               category_match=category_match, internal_card_hash=internal_card_hash, tags_hash=tags_hash,
                               bank_authorization_hash=bank_authorization_hash, category_default=category_default, payment_hash=payment_hash)
        return json.loads(result) if result else None

    def all(self, path):
        result = []
        page = 1
        while True:
            separator = "&" if "?" in path else "?"
            response = self.request("GET", f"{path}{separator}limit=100&page={page}")
            result.extend(response["data"])
            pagination = response.get("meta", {}).get("pagination", {})
            if page >= pagination.get("total_pages", 1):
                return result
            page += 1

    def upload(self, attachment_id, content):
        return self._request("POST", f"/attachments/{int(attachment_id)}/upload", content,
                             "application/octet-stream")

    def download(self, attachment_id):
        return self._request("GET", f"/attachments/{int(attachment_id)}/download")
