#!/bin/bash
set -eu

socket=/var/run/docker.sock
if [ ! -S "$socket" ]; then
    echo "Docker socket is missing or is not a Unix socket: $socket" >&2
    exit 1
fi

socket_gid=$(stat -c '%g' "$socket")
socket_group=$(getent group "$socket_gid" | cut -d: -f1)
if [ -z "$socket_group" ]; then
    socket_group=app-manager-docker
    groupadd --gid "$socket_gid" "$socket_group"
fi
usermod --append --groups "$socket_group" www-data

chown -R www-data:www-data /var/www/html/writable

if [ "${1:-}" != php-fpm ]; then
    exec docker-php-entrypoint "$@"
fi

docs_pid=
php_pid=
cleanup() {
    trap - EXIT TERM INT
    if [ -n "$docs_pid" ]; then
        kill "$docs_pid" 2>/dev/null || true
    fi
    if [ -n "$php_pid" ]; then
        kill "$php_pid" 2>/dev/null || true
    fi
    wait || true
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

/usr/local/bin/busybox httpd -f -p 8080 -u 1000:1000 -h /srv/documentation &
docs_pid=$!
docker-php-entrypoint "$@" &
php_pid=$!

status=0
wait -n "$docs_pid" "$php_pid" || status=$?
echo "Application manager process exited (status $status); stopping both services." >&2
if [ "$status" -eq 0 ]; then
    status=1
fi
exit "$status"
