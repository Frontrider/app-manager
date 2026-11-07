#!/bin/sh
set -eu

mkdir -p /data/copyparty /data/videos /data/books
for directory in "$@"; do
    echo "Fixing ownership and owner permissions in $directory"
    chown -hR 1000:1000 "$directory"
    find "$directory" -type d -exec chmod u+rwx {} +
    find "$directory" -type f -exec chmod u+rw {} +
done
