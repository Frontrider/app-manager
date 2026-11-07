#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
prefix="file-permissions-smoke-$$"
cleanup() {
    docker volume rm "$prefix-data" "$prefix-private" "$prefix-outside" >/dev/null
    docker image rm "$prefix-copyparty" "$prefix-sftpgo" "$prefix-tiny-file-manager" >/dev/null
}
trap cleanup EXIT
for volume in data private outside; do
    docker volume create "$prefix-$volume" >/dev/null
done
for app in copyparty sftpgo tiny-file-manager; do
    if [ "$app" = copyparty ]; then
        docker build --quiet -t "$prefix-$app" "apps/singleton/files/$app" >/dev/null
    else
        docker build --quiet --target permissions -t "$prefix-$app" "apps/singleton/files/$app" >/dev/null
    fi
    docker run --rm -v "$prefix-data:/data" -v "$prefix-private:/private" \
        -v "$prefix-outside:/outside" alpine:3.22 sh -ec '
        mkdir -p /data/nested/child
        touch /data/nested/child/file /private/config /outside/untouched
        ln -sf /outside/untouched /data/link
        chown -R 999:999 /data /private /outside
        chmod 000 /data/nested /data/nested/child/file /private/config
        chmod 600 /outside/untouched
    '
    docker run --rm --network none -v "$prefix-data:/data" -v "$prefix-private:/private" \
        -v "$prefix-outside:/outside" "$prefix-$app" /data /private
    docker run --rm --user 1000:1000 -v "$prefix-data:/data" -v "$prefix-private:/private" \
        -v "$prefix-outside:/outside" alpine:3.22 sh -ec '
        [ "$(stat -c "%u:%g" /data)" = 1000:1000 ]
        [ "$(stat -c "%u:%g" /data/nested/child/file)" = 1000:1000 ]
        test -r /data/nested/child/file
        test -w /data/nested/child/file
        test -w /private/config
        [ "$(stat -c "%u:%g:%a" /outside/untouched)" = 999:999:600 ]
        rm /data/nested/child/file
        rmdir /data/nested/child /data/nested
    '
done
