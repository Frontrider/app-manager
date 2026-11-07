import json
import os
import sys
import time
import secrets
import base64
import ssl
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen
from urllib.parse import urlsplit

from ldap3 import Connection, Server, SUBTREE, Tls
from ldap3.core.exceptions import LDAPException
from ldap3.utils.conv import escape_filter_chars


auth_headers = {}


def api(method, path, body=None, headers=None, version="v1"):
    req = Request(
        "http://komga:25600/books/api/" + version + path,
        data=json.dumps(body).encode() if body is not None else None,
        headers={"Content-Type": "application/json", **(headers if headers is not None else auth_headers)},
        method=method,
    )
    try:
        with urlopen(req, timeout=10) as response:
            content = response.read()
            return json.loads(content) if content else None
    except HTTPError as error:
        detail = error.read().decode("utf-8", errors="replace")
        if body is not None and isinstance(body.get("password"), str):
            detail = detail.replace(body["password"], "[redacted]")
        for value in (headers if headers is not None else auth_headers).values():
            detail = detail.replace(value, "[redacted]")
        raise RuntimeError(
            f"Komga API {method} /api/{version}{path} returned HTTP {error.code}: {detail}"
        ) from error


def request(method, path, body=None):
    return api(method, "/libraries" + path, body)


def bootstrap():
    global auth_headers
    deadline = time.monotonic() + 300
    while True:
        try:
            status = api("GET", "/claim", headers={})
            break
        except (RuntimeError, URLError) as error:
            cause = error.__cause__
            if isinstance(cause, HTTPError) and cause.code not in (502, 503, 504):
                raise
            if time.monotonic() >= deadline:
                raise TimeoutError("Komga did not become ready within 300 seconds.") from error
            print("Waiting for Komga...", flush=True)
            time.sleep(5)
    key = os.environ.get("KOMGA_API_KEY")
    if key:
        auth_headers = {"X-API-Key": key}
        return
    file = Path("/config/localhost-bootstrap.json")
    if file.exists():
        credentials = json.loads(file.read_text())
    else:
        if status["isClaimed"]:
            raise ValueError("Existing Komga has no saved bootstrap credentials; provide KOMGA_API_KEY. No accounts were changed.")
        credentials = {"username": "localhost-admin@example.invalid", "password": secrets.token_urlsafe(32)}
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


def directory_users():
    uri = urlsplit(os.environ.get("LDAP_URI", "ldap://core:3890"))
    if uri.scheme not in ("ldap", "ldaps") or not uri.hostname:
        raise ValueError("LDAP_URI must be an LDAP or LDAPS URL.")
    base = os.environ.get("LDAP_USER_BASE_DN", "ou=people,dc=example,dc=com")
    groups = os.environ.get("LDAP_GROUP_BASE_DN", "ou=groups,dc=example,dc=com")
    password = Path("/var/environment/global-env/lldap_service_password").read_text().strip()
    if not password:
        raise ValueError("Core's LDAP service password is empty.")
    server = Server(uri.hostname, port=uri.port or (636 if uri.scheme == "ldaps" else 389),
                    use_ssl=uri.scheme == "ldaps", connect_timeout=10,
                    tls=Tls(validate=ssl.CERT_REQUIRED))
    with Connection(server, user="uid=service," + base, password=password,
                    auto_bind=True, raise_exceptions=True, receive_timeout=10) as connection:
        connection.search(base, "(objectClass=person)", SUBTREE, attributes=["mail"])
        users = {}
        for entry in connection.entries:
            email = str(entry.mail).strip().lower()
            if not email:
                raise ValueError("An LDAP user has no email; OIDC requires an email for every Komga account.")
            if email == "localhost-admin@example.invalid":
                raise ValueError("An LDAP user has the reserved internal Komga bootstrap email.")
            if email in users:
                raise ValueError("Multiple LDAP users share an email; cannot safely assign Komga permissions.")
            users[email] = False
        admin_dn = "cn=lldap_admin," + groups
        connection.search(base, "(memberOf=" + escape_filter_chars(admin_dn) + ")", SUBTREE, attributes=["mail"])
        for entry in connection.entries:
            email = str(entry.mail).strip().lower()
            if email not in users:
                raise ValueError("LDAP administrator is missing from the user directory.")
            users[email] = True
        return users


def synchronize_users(users):
    existing = api("GET", "/users", version="v2")
    by_email = {user["email"].lower(): user for user in existing}
    failures = []
    for email, administrator in sorted(users.items(), key=lambda item: not item[1]):
        roles = ["FILE_DOWNLOAD", "PAGE_STREAMING"]
        if administrator:
            roles.append("ADMIN")
        user = by_email.get(email)
        try:
            if user is None:
                api("POST", "/users", {
                    "email": email, "password": secrets.token_urlsafe(32),
                    "roles": roles, "sharedLibraries": {"all": True, "libraryIds": []},
                }, version="v2")
            elif set(user["roles"]) - {"USER"} != set(roles) or not user["sharedAllLibraries"]:
                api("PATCH", "/users/" + quote(user["id"], safe=""), {
                    "roles": roles, "sharedLibraries": {"all": True, "libraryIds": []},
                }, version="v2")
        except RuntimeError as error:
            print(f"Komga {'administrator' if administrator else 'reader'} synchronization failed: {error}",
                  file=sys.stderr, flush=True)
            failures.append(str(error))
    for user in existing:
        if user["email"].lower() not in users and "ADMIN" in user["roles"]:
            if user["email"].lower() == "localhost-admin@example.invalid":
                continue
            try:
                api("PATCH", "/users/" + quote(user["id"], safe=""), {
                    "roles": [role for role in user["roles"] if role not in ("ADMIN", "USER")],
                }, version="v2")
            except RuntimeError as error:
                print(f"Komga administrator revocation failed: {error}", file=sys.stderr, flush=True)
                failures.append(str(error))
    if failures:
        raise RuntimeError("Komga permission synchronization failed:\n" + "\n".join(failures))
    print("Komga reader and administrator permissions synchronized.", flush=True)


if __name__ == "__main__":
    try:
        setup()
        if "--library-only" not in sys.argv:
            while True:
                synchronize_users(directory_users())
                time.sleep(60)
    except (ValueError, KeyError, OSError, RuntimeError, LDAPException) as error:
        print(f"Komga library setup failed: {error}", file=sys.stderr)
        sys.exit(1)
