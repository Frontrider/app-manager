#!/bin/sh
set -eu

/bin/sh /seed.sh
"$@" &
server=$!
stop() {
    kill -TERM "$server" 2>/dev/null || true
    wait "$server" || true
}
trap 'stop; exit 143' TERM
trap 'stop; exit 130' INT
if JELLYFIN_URL=http://127.0.0.1:8096/jellyfin python3 /library-setup.py; then
    wait "$server"
else
    status=$?
    echo "Jellyfin startup configuration failed." >&2
    stop
    exit "$status"
fi
