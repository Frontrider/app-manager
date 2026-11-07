#!/bin/sh
set -eu

address=$(getent hosts core | awk 'index($1, ":") == 0 { print $1; exit }')
if [ -z "$address" ]; then
    echo "Could not resolve core's Pi-hole address through Docker DNS." >&2
    exit 1
fi
printf 'nameserver %s\n' "$address" > /etc/resolv.conf
exec su-exec 1000:1000 /bin/rqbit "$@"
