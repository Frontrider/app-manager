#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
count=0
for compose in apps/games/*/docker-compose.yaml; do
    docker compose --env-file "$(dirname "$compose")/env" -f "$compose" config --quiet
    count=$((count + 1))
done
[ "$count" -eq 12 ]
sh -n apps/games/geysermc/entrypoint.sh
