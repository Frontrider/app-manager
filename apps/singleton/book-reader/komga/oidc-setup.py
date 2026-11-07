import json
import os
import re
import sys
import tempfile
import time
from pathlib import Path
from urllib.parse import urlsplit


def configure(global_env=Path("/var/environment/global-env"), config=Path("/config")):
    deadline = time.monotonic() + 300
    domain_file = global_env / "DOMAIN"
    while not domain_file.is_file() or not domain_file.read_text().strip():
        if time.monotonic() >= deadline:
            raise TimeoutError("Core did not publish DOMAIN within 300 seconds.")
        time.sleep(2)
    domain = domain_file.read_text().strip()
    parsed = urlsplit("https://" + domain)
    if (parsed.netloc != domain or not parsed.hostname or parsed.path or parsed.query
            or parsed.fragment or parsed.username or re.search(r"[\s\\]", domain)):
        raise ValueError("Core DOMAIN must contain only a hostname and optional port.")

    client_id = "localhost-komga"
    redirect = f"https://{domain}/books/login/oauth2/code/authelia"
    requests = global_env / "oidc/requests"
    requests.mkdir(parents=True, exist_ok=True)
    request = requests / client_id
    contents = redirect + "\n"
    if request.exists() and request.read_text() != contents:
        raise ValueError("Existing Komga OIDC registration has different redirect URIs; update it in core first.")
    # Closing the request file notifies core's registration watcher.
    with request.open("w") as stream:
        stream.write(contents)
        stream.flush()
        os.fsync(stream.fileno())

    secret_file = global_env / "oidc/secrets" / client_id
    while True:
        secret = secret_file.read_text().strip() if secret_file.is_file() else ""
        if re.fullmatch(r"[0-9a-fA-F]{64}", secret):
            break
        if time.monotonic() >= deadline:
            raise TimeoutError("Core did not publish a valid Komga OIDC secret within 300 seconds.")
        time.sleep(2)
    settings = {
        "spring": {"security": {"oauth2": {"client": {
            "registration": {"authelia": {
                "client-id": client_id,
                "client-secret": secret,
                "client-name": "Authelia",
                "authorization-grant-type": "authorization_code",
                "client-authentication-method": "client_secret_basic",
                "redirect-uri": redirect,
                "scope": ["openid", "profile", "email"],
            }},
            "provider": {"authelia": {"issuer-uri": f"https://{domain}/auth"}},
        }}}},
    }
    descriptor, temporary = tempfile.mkstemp(prefix="oidc-", dir=config)
    try:
        with os.fdopen(descriptor, "w") as stream:
            json.dump(settings, stream)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(temporary, 1000, 1000)
        os.replace(temporary, config / "application-localhost.yml")
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    print("Komga OIDC client configured.", flush=True)


if __name__ == "__main__":
    try:
        configure()
    except (ValueError, OSError) as error:
        print(f"Komga OIDC setup failed: {error}", file=sys.stderr)
        sys.exit(1)
