#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
image="${1:-localhost-calibre-web-validation:task}"
container="localhost-calibre-web-smoke-$$"
config_volume="$container-config"
data_volume="$container-data"
cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    docker volume rm "$config_volume" "$data_volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker volume create "$config_volume" >/dev/null
docker volume create "$data_volume" >/dev/null
docker run --rm --network none --user 0:0 \
    -v "$config_volume:/config:nocopy" -v "$data_volume:/Data:nocopy" \
    --entrypoint sh "$image" -ec 'chown -R 1000:1000 /config /Data'
docker run --rm --network none --user 0:0 \
    -v "$config_volume:/config:nocopy" --entrypoint sh "$image" \
    -ec 'printf "validation-password" > /config/lldap_service_password; chmod 644 /config/lldap_service_password'
docker run --rm --network none --user 1000:1000 \
    -v "$config_volume:/config:nocopy" -v "$data_volume:/Data:nocopy" \
    --mount "type=bind,source=$root,target=/repo,readonly" \
    --entrypoint python "$image" /repo/tests/docker/test_calibre_web.py

docker run -d --name "$container" --network none --user 1000:1000 \
    -e COOKIE_PREFIX=localhost-calibre- \
    -v "$config_volume:/config:nocopy" -v "$data_volume:/Data:nocopy" \
    -v "$config_volume:/global-env:ro" \
    --mount "type=bind,source=$root,target=/repo,readonly" \
    --entrypoint python "$image" /repo/tests/docker/test_calibre_web.py --serve >/dev/null

wait_ready() {
    for attempt in $(seq 1 30); do
        if docker exec "$container" python -c '
from urllib.request import Request, urlopen
request = Request("http://127.0.0.1:8083/login", headers={"X-Script-Name": "/calibre-web"})
with urlopen(request, timeout=2) as response:
    body = response.read().decode()
    assert response.status == 200
    assert "/calibre-web/" in body
' 2>/dev/null; then
            return
        fi
        sleep 1
    done
    docker logs "$container" >&2
    return 1
}
wait_ready
docker stop "$container" >/dev/null
docker run --rm --network none --user 0:0 -v "$config_volume:/config:nocopy" \
    --entrypoint python "$image" -c '
from pathlib import Path
import sqlite3
Path("/config/lldap_service_password").write_text("rotated-validation-password")
with sqlite3.connect("/config/app.db") as db:
    db.execute("UPDATE settings SET config_ldap_provider_url = ?, config_ldap_port = ?",
               ("ldap-server.contoso.com", 389))
'
docker start "$container" >/dev/null
wait_ready
docker exec "$container" python -c '
from pathlib import Path
import sqlite3
assert Path("/config/ldap-password").read_text() == "rotated-validation-password"
with sqlite3.connect("/config/app.db") as db:
    assert db.execute("SELECT config_ldap_provider_url, config_ldap_port FROM settings").fetchone() == ("core", 3890)
    assert db.execute("SELECT count(*) FROM user WHERE name = ?", ("directory-admin",)).fetchone()[0] == 1
'
