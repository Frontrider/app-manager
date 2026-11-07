#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
books=apps/singleton/books/komga/docker-compose.yaml
videos=apps/singleton/video/jelylfin/docker-compose.yaml
export DATA_VOLUME="app-manager-media-smoke-$$"
books_project="app-manager-books-smoke-$$"
videos_project="app-manager-videos-smoke-$$"
cleanup() {
    docker compose -p "$books_project" -f "$books" down --volumes --rmi local >/dev/null 2>&1
    docker compose -p "$videos_project" -f "$videos" down --volumes --rmi local >/dev/null 2>&1
    docker volume rm "$DATA_VOLUME" >/dev/null
}
trap cleanup EXIT
docker volume create "$DATA_VOLUME" >/dev/null
docker compose -p "$books_project" -f "$books" run --rm --no-deps komga-init
ADMIN_PASS=validation-only docker compose -p "$videos_project" -f "$videos" run \
    --build --rm --no-deps jellyfin-init
docker run --rm --user 1000:1000 \
    --mount "type=volume,source=$DATA_VOLUME,target=/data" alpine:3.22 sh -ec '
    [ "$(stat -c "%u:%g" /data/books)" = 1000:1000 ]
    [ "$(stat -c "%u:%g" /data/videos)" = 1000:1000 ]
    test -w /data/books
    test -w /data/videos
'
for folder in books videos; do
    case "$folder" in
        books) other=videos ;;
        videos) other=books ;;
    esac
    docker run --rm --user 1000:1000 \
        --mount "type=volume,source=$DATA_VOLUME,target=/data/$folder,volume-subpath=$folder,readonly" \
        alpine:3.22 sh -ec '
        [ "$(stat -c "%u:%g" "$1")" = 1000:1000 ]
        test -r "$1"
        test ! -e "$2"
    ' sh "/data/$folder" "/data/$other"
done
