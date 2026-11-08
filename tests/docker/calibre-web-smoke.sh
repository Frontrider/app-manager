#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
image="${1:-localhost-calibre-web-validation:task}"
web_image="${2:-lscr.io/linuxserver/calibre-web:version-0.6.27}"
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
docker run --rm --network none --user 1000:1000 \
    -v "$config_volume:/config:nocopy" -v "$data_volume:/Data:nocopy" \
    --mount "type=bind,source=$root,target=/repo,readonly" \
    --entrypoint python "$image" /repo/tests/docker/test_calibre_web.py

docker run -d --name "$container" --network none \
    -e PUID=1000 -e PGID=1000 -e COOKIE_PREFIX=localhost-calibre- \
    -v "$config_volume:/config:nocopy" -v "$data_volume:/Data:nocopy" "$web_image" >/dev/null

ready=false
for attempt in $(seq 1 30); do
    if docker exec "$container" python -c '
from urllib.request import Request, urlopen
request = Request("http://127.0.0.1:8083/login", headers={"X-Script-Name": "/calibre-web"})
with urlopen(request, timeout=2) as response:
    body = response.read().decode()
    assert response.status == 200
    assert "/calibre-web/" in body
'; then
        ready=true
        break
    fi
    sleep 1
done
if [ "$ready" != true ]; then
    docker logs "$container" >&2
    exit 1
fi
