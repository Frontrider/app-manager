#!/bin/sh
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

exec docker-php-entrypoint "$@"
