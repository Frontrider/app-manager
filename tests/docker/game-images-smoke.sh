#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
prefix="game-images-smoke-$$"
cleanup() {
    docker rm -f "$prefix" >/dev/null 2>&1 || true
    docker image rm "$prefix" >/dev/null 2>&1 || true
    docker network rm "$prefix" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Build/run only uniquely named validation images. No deployed images, host ports,
# external networks, or existing data volumes are modified.
for app in "$@"; do
    case "$app" in
        mindustry) port=19A7 ;;
        geysermc) port=4ABD ;;
        bedrockconnect) port=4ABC ;;
        0-ad) port=5073 ;;
        freeorion) port=303A ;;
        openarena) port=6D38 ;;
        shapez-io) port=1F90 ;;
        openttd) port=0F8B ;;
        ioquake3) port= ;;
        *) echo "Unknown locally built game: $app" >&2; exit 1 ;;
    esac
    docker build --quiet -t "$prefix" "apps/standard/games/$app" >/dev/null
    docker network create "$prefix" >/dev/null
    docker run -d --name "$prefix" --network "$prefix" \
        --env-file "apps/standard/games/$app/env" \
        -e JAVA_SERVER_HOST=127.0.0.1 -e JAVA_SERVER_PORT=25565 -e AUTH_TYPE=online \
        "$prefix" >/dev/null
    ready=false
    for attempt in $(seq 1 60); do
        if [ "$app" = ioquake3 ]; then
            if docker logs "$prefix" 2>&1 | grep -qi 'pak0.pk3'; then
                ready=true
                break
            fi
        elif docker exec "$prefix" sh -c \
            'cat /proc/net/udp /proc/net/udp6 /proc/net/tcp /proc/net/tcp6' 2>/dev/null | grep -q ":$port "; then
                ready=true
                break
        fi
        if [ "$(docker inspect -f '{{.State.Running}}' "$prefix")" != true ]; then
            break
        fi
        sleep 1
    done
    if [ "$ready" != true ]; then
        docker logs "$prefix" >&2
        echo "$app did not reach its expected startup state." >&2
        exit 1
    fi
    if [ "$app" != ioquake3 ]; then
        sleep 2
        if [ "$(docker inspect -f '{{.State.Running}}' "$prefix")" != true ]; then
            docker logs "$prefix" >&2
            echo "$app exited after startup." >&2
            exit 1
        fi
    fi
    cleanup
    echo "$app startup passed."
done
