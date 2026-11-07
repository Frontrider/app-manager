#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
prefix="file-browser-smoke-$$"
cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        docker logs --tail 30 "$prefix-tiny" >&2
        docker logs --tail 30 "$prefix-sftpgo" >&2
    fi
    docker rm -f "$prefix-tiny" "$prefix-sftpgo" >/dev/null 2>&1 || true
    docker volume rm "$prefix-data" "$prefix-state" "$prefix-env" >/dev/null
    docker image rm "$prefix-tiny" "$prefix-sftpgo" >/dev/null
}
trap cleanup EXIT
for volume in data state env; do
    docker volume create "$prefix-$volume" >/dev/null
done
docker build --quiet -t "$prefix-tiny" apps/singleton/files/tiny-file-manager >/dev/null
docker build --quiet -t "$prefix-sftpgo" apps/singleton/files/sftpgo >/dev/null
docker run --rm -v "$prefix-data:/data" -v "$prefix-state:/state" -v "$prefix-env:/env" alpine:3.22 sh -ec '
    mkdir -p /data/books /data/videos /data/copyparty
    chown 1000:1000 /data/books /data/videos /data/copyparty /state
    printf "smoke-test-not-a-real-secret" > /env/lldap_service_password
    chmod 644 /env/lldap_service_password
'
docker run -d --name "$prefix-tiny" -v "$prefix-data:/data" "$prefix-tiny" >/dev/null
docker run -d --name "$prefix-sftpgo" -v "$prefix-data:/data" -v "$prefix-state:/var/lib/sftpgo" \
    -v "$prefix-env:/var/environment/global-env:ro" \
    -e SFTPGO_HTTPD__WEB_ROOT=/files -e SFTPGO_HTTPD__BINDINGS__0__ENABLE_WEB_ADMIN=false \
    -e SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true -e SFTPGO_DEFAULT_ADMIN_USERNAME=appstore-bootstrap \
    -e SFTPGO_LOADDATA_FROM=/etc/sftpgo/appstore-groups.json -e SFTPGO_LOADDATA_MODE=1 \
    -e SFTPGO_PLUGINS__0__TYPE=auth -e SFTPGO_PLUGINS__0__CMD=/usr/local/bin/sftpgo-plugin-auth \
    -e SFTPGO_PLUGINS__0__ARGS=serve -e SFTPGO_PLUGINS__0__AUTH_OPTIONS__SCOPE=5 \
    -e SFTPGO_PLUGINS__0__AUTO_MTLS=1 \
    -e SFTPGO_PLUGIN_AUTH_LDAP_URL=ldap://127.0.0.1:3890 \
    -e SFTPGO_PLUGIN_AUTH_LDAP_BASE_DN=ou=people,dc=example,dc=com \
    -e SFTPGO_PLUGIN_AUTH_LDAP_BIND_DN=uid=service,ou=people,dc=example,dc=com \
    -e 'SFTPGO_PLUGIN_AUTH_LDAP_SEARCH_QUERY=(uid=%username%)' \
    "$prefix-sftpgo" >/dev/null
ready=false
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if docker exec "$prefix-sftpgo" curl -fsS http://127.0.0.1:8080/files/web/client/login >/dev/null; then
        ready=true
        break
    fi
    sleep 1
done
[ "$ready" = true ]
docker exec "$prefix-tiny" sh -ec '
    [ "$(id -u):$(id -g)" = 1000:1000 ]
    test -w /data/books
    [ "$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/files/)" = 403 ]
    [ "$(curl -s -o /dev/null -w "%{http_code}" -H "Remote-User: admin" -H "Remote-Groups: users" http://127.0.0.1:8080/files/)" = 403 ]
    curl -fLsS -o /tmp/browser.html -H "Remote-User: admin" -H "Remote-Groups: users,lldap_admin" http://127.0.0.1:8080/files/
    grep -q books /tmp/browser.html
    [ "$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/files/config.php)" = 404 ]
'
docker exec "$prefix-sftpgo" sh -ec '
    [ "$(id -u):$(id -g)" = 1000:1000 ]
    test -w /data/videos
    token=$(curl -fsS -u "appstore-bootstrap:$(cat /var/lib/sftpgo/bootstrap-admin-password)" \
        http://127.0.0.1:8080/api/v2/token | sed -n "s/.*\"access_token\":\"\([^\"]*\)\".*/\1/p")
    test -n "$token"
    curl -fsS -H "Authorization: Bearer $token" \
        http://127.0.0.1:8080/api/v2/groups/lldap_admin > /tmp/group.json
    grep -q lldap_admin /tmp/group.json
    grep -q /data /tmp/group.json
'
