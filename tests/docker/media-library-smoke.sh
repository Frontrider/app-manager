#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
prefix="localhost-library-smoke-$$"
cleanup() {
    docker rm -f "$prefix-komga" "$prefix-jellyfin" >/dev/null 2>&1 || true
    docker network rm "$prefix" >/dev/null
    docker volume rm "$prefix-books" "$prefix-videos" "$prefix-komga" "$prefix-jellyfin" >/dev/null
    docker image rm "$prefix-komga-setup" "$prefix-jellyfin-setup" "$prefix-jellyfin-init" >/dev/null
}
trap cleanup EXIT
docker network create "$prefix" >/dev/null
for volume in books videos komga jellyfin; do
    docker volume create "$prefix-$volume" >/dev/null
done
docker build --quiet -t "$prefix-komga-setup" apps/singleton/books/komga >/dev/null
docker build --quiet --target library -t "$prefix-jellyfin-setup" apps/singleton/video/jelylfin >/dev/null
docker build --quiet --target init -t "$prefix-jellyfin-init" apps/singleton/video/jelylfin >/dev/null
docker run --rm -v "$prefix-books:/data" -v "$prefix-komga:/config" alpine:3.22 sh -ec \
    'mkdir -p /data/books; chown 1000:1000 /data/books /config'
docker run --rm -e LDAP_HOST=core -e LDAP_BIND_PASSWORD=validation-only \
    -v "$prefix-videos:/data" -v "$prefix-jellyfin:/config" "$prefix-jellyfin-init"
docker run -d --name "$prefix-komga" --network "$prefix" --network-alias komga \
    --user 1000:1000 -e SERVER_SERVLET_CONTEXT_PATH=/books \
    -v "$prefix-komga:/config" \
    --mount "type=volume,source=$prefix-books,target=/data/books,volume-subpath=books,readonly" \
    gotson/komga:latest >/dev/null
docker run -d --name "$prefix-jellyfin" --network "$prefix" --network-alias jellyfin \
    --user 1000:1000 -v "$prefix-jellyfin:/config" \
    --mount "type=volume,source=$prefix-videos,target=/data/videos,volume-subpath=videos,readonly" \
    jellyfin/jellyfin:latest >/dev/null
for attempt in 1 2; do
    docker run --rm --network "$prefix" -v "$prefix-komga:/config" "$prefix-komga-setup" \
        python /library-setup.py --library-only
    docker run --rm --network "$prefix" -v "$prefix-jellyfin:/config" "$prefix-jellyfin-setup"
done
docker run --rm --network "$prefix" -v "$prefix-komga:/config" "$prefix-komga-setup" \
    python -c 'import runpy; m=runpy.run_path("/library-setup.py"); m["bootstrap"](); libraries=m["request"]("GET", ""); assert len(libraries)==1; assert libraries[0]["root"]=="/data/books"; assert libraries[0]["scanOnStartup"]'
docker run --rm --network "$prefix" -v "$prefix-jellyfin:/config" "$prefix-jellyfin-setup" \
    python -c 'import runpy; m=runpy.run_path("/library-setup.py"); m["bootstrap"](); libraries=m["request"]("GET", "/Library/VirtualFolders"); assert len(libraries)==1; assert libraries[0]["Locations"]==["/data/videos"]'
