"""Client for the local Firefly III instance."""

import json
import stat
import urllib.error
import urllib.request
from pathlib import Path


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

    def _request(self, method, path, body=None, content_type=None):
        if not path.startswith("/") or path.startswith("//") or "://" in path:
            raise ValueError("Only relative API paths are supported")
        if ".." in path.split("/"):
            raise ValueError("Parent path segments are not supported")
        headers = {"Authorization": "Bearer " + self._token, "Accept": "application/json"}
        if content_type:
            headers["Content-Type"] = content_type
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

    def request(self, method, path, data=None):
        body = json.dumps(data, ensure_ascii=False).encode() if data is not None else None
        result = self._request(method, path, body, "application/json")
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
