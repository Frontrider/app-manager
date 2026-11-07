import json
import os
import sys
import time
import secrets
import base64
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen


auth_headers = {}


def api(method, path, body=None, headers=None):
    req = Request(
        "http://komga:25600/books/api/v1" + path,
        data=json.dumps(body).encode() if body is not None else None,
        headers={"Content-Type": "application/json", **(headers if headers is not None else auth_headers)},
        method=method,
    )
    with urlopen(req, timeout=10) as response:
        content = response.read()
        return json.loads(content) if content else None


def request(method, path, body=None):
    return api(method, "/libraries" + path, body)


def bootstrap():
    global auth_headers
    deadline = time.monotonic() + 300
    while True:
        try:
            status = api("GET", "/claim", headers={})
            break
        except (HTTPError, URLError) as error:
            if isinstance(error, HTTPError) and error.code not in (502, 503, 504):
                raise
            if time.monotonic() >= deadline:
                raise TimeoutError("Komga did not become ready within 300 seconds.") from error
            print("Waiting for Komga...", flush=True)
            time.sleep(5)
    key = os.environ.get("KOMGA_API_KEY")
    if key:
        auth_headers = {"X-API-Key": key}
        return
    file = Path("/config/appstore-bootstrap.json")
    if file.exists():
        credentials = json.loads(file.read_text())
    else:
        if status["isClaimed"]:
            raise ValueError("Existing Komga has no saved bootstrap credentials; provide KOMGA_API_KEY. No accounts were changed.")
        credentials = {"username": "appstore-admin@example.invalid", "password": secrets.token_urlsafe(32)}
        with open(file, "x", opener=lambda path, flags: os.open(path, flags, 0o600)) as stream:
            json.dump(credentials, stream)
            stream.flush()
            os.fsync(stream.fileno())
    if not status["isClaimed"]:
        api("POST", "/claim", headers={
            "X-Komga-Email": credentials["username"],
            "X-Komga-Password": credentials["password"],
        })
    token = base64.b64encode(f'{credentials["username"]}:{credentials["password"]}'.encode()).decode()
    auth_headers = {"Authorization": "Basic " + token}


def setup():
    bootstrap()
    libraries = request("GET", "")
    library = next((item for item in libraries if item["root"].rstrip("/") == "/data/books"), None)
    if library is None:
        if any(item["name"] == "Books" for item in libraries):
            raise ValueError("A Books library already exists with a different path.")
        library = request("POST", "", {
            "name": "Books", "root": "/data/books", "scanOnStartup": True,
            "scanInterval": "EVERY_6H",
        })
    request("POST", "/" + quote(library["id"], safe="") + "/scan")
    print("Books library configured and scan requested.", flush=True)


if __name__ == "__main__":
    try:
        setup()
    except (ValueError, KeyError, OSError) as error:
        print(f"Komga library setup failed: {error}", file=sys.stderr)
        sys.exit(1)
