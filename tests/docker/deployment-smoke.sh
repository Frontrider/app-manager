#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
image="${1:-app-manager:latest}"
container="app-manager-deployment-smoke-$$"
deployment_pid=
cleanup() {
    if [ -n "$deployment_pid" ]; then
        wait "$deployment_pid" || true
    fi
    docker rm -f "$container" >/dev/null
}
trap cleanup EXIT
docker run -d --name "$container" \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mount "type=bind,source=$root/tests/_support/Fixtures/deployment-routes.php,target=/var/www/html/app/Config/Routes.php,readonly" \
    --mount "type=bind,source=$root/tests/_support/Fixtures/deployment-docker.php,target=/test-docker.php,readonly" \
    --env app_baseURL=http://localhost/ --env app_indexPage= --env DOCKER_BIN=/test-docker.php \
    --env CI_ENVIRONMENT=production "$image" >/dev/null
request() {
    docker exec \
        --env SCRIPT_FILENAME=/var/www/html/public/index.php \
        --env SCRIPT_NAME=/index.php --env REQUEST_METHOD=GET --env REQUEST_URI="$1" \
        --env SERVER_NAME=localhost --env SERVER_PORT=80 --env SERVER_PROTOCOL=HTTP/1.1 \
        --env HTTP_COOKIE="${cookie:-}" \
        "$container" cgi-fcgi -bind -connect 127.0.0.1:9000
}
ready=false
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if request /seed >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
[ "$ready" = true ]
seed=$(request /seed)
cookie=$(printf '%s\n' "$seed" | sed -n 's/^Set-Cookie: \(ci_session=[^;]*\).*/\1/p' | head -1)
test -n "$cookie"
id=$(request /app-id | tail -1 | tr -d '\r\n')
request "/deploy/$id" >/dev/null &
deployment_pid=$!
started=false
for attempt in 1 2 3 4 5; do
    if docker exec "$container" test -f /tmp/deployment-started; then
        started=true
        break
    fi
    sleep 1
done
[ "$started" = true ]
start=$(date +%s)
page=$(request /)
duration=$(($(date +%s) - start))
[ "$duration" -lt 2 ]
printf '%s' "$page" | grep -q 'Available apps'
wait "$deployment_pid"
deployment_pid=
page=$(request /)
printf '%s' "$page" | grep -q 'started.'
