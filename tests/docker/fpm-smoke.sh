#!/bin/sh
set -eu

image="${1:-app-manager:fpm-validation}"
container="app-manager-fpm-smoke-$$"
trap 'docker rm -f "$container" >/dev/null 2>&1' EXIT

docker run -d --name "$container" \
    --mount type=bind,source="${DOCKER_SOCKET:-/var/run/docker.sock}",target=/var/run/docker.sock \
    --env app_baseURL=http://localhost:90/ --env app_indexPage= \
    "$image" >/dev/null

ready=false
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if docker exec "$container" sh -c \
        'SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000' \
        | grep -q pong; then
        ready=true
        break
    fi
    sleep 1
done
[ "$ready" = true ]

request() {
    docker exec \
        --env SCRIPT_FILENAME=/var/www/html/public/index.php \
        --env SCRIPT_NAME=/index.php \
        --env REQUEST_METHOD=GET \
        --env REQUEST_URI="$1" \
        --env SERVER_NAME=localhost \
        --env SERVER_PORT=90 \
        --env SERVER_PROTOCOL=HTTP/1.1 \
        "$container" cgi-fcgi -bind -connect 127.0.0.1:9000
}

request /login | grep -q 'Sign in with LDAP'
request / | grep -qi 'Location: http://localhost:90/login'
request /sidebar | grep -q '^\[.*\]'
docker exec "$container" sh -ec '
    busybox wget -q -O - http://127.0.0.1:8080/docs/ | grep -qi "Localhost"
    busybox wget -q -O - http://127.0.0.1:8080/docs/obs-bridge/ | grep -qi "OBS bridge"
    for file in /srv/documentation/docs/assets/css/*.css; do
        busybox wget -S -O /dev/null "http://127.0.0.1:8080/docs/assets/css/$(basename "$file")" 2>&1 \
            | grep -qi "Content-Type: text/css"
    done
    for file in /srv/documentation/docs/assets/js/*.js; do
        busybox wget -q -O /dev/null "http://127.0.0.1:8080/docs/assets/js/$(basename "$file")"
    done
    busybox wget -S -O /dev/null http://127.0.0.1:8080/docs/obs-bridge 2>&1 | grep -qi "Location: /docs/obs-bridge/"
    busybox wget -S -O /dev/null http://127.0.0.1:8080/docs/missing-page/ 2>&1 | grep -q "404"
    busybox wget -S -O /dev/null http://127.0.0.1:8080/app/Config/Routes.php 2>&1 | grep -q "404"
'
docker exec --user www-data "$container" docker ps --quiet >/dev/null
docker exec --user www-data "$container" sh -ec '
    [ "$(id -u)" = 1000 ]
    [ "$(id -g)" = 1000 ]
    [ "$(stat -c "%u:%g" /var/www/html/writable/session)" = 1000:1000 ]
'
docker exec "$container" sh -ec '
    found=false
    for status in /proc/[0-9]*/status; do
        if grep -q "^Uid:[[:space:]]*1000[[:space:]]" "$status"; then
            grep -q "^Gid:[[:space:]]*1000[[:space:]]" "$status"
            found=true
        fi
    done
    [ "$found" = true ]
'
docker exec "$container" docker compose version
docker exec "$container" php -r '
foreach (["intl", "ldap", "mbstring", "fileinfo"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Missing extension: " . $extension . PHP_EOL);
        exit(1);
    }
}
'
