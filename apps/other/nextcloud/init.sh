#!/bin/sh
set -eu

mkdir -p /app /data /run/secrets
chown -R 33:33 /app /data

umask 077

printf 'nextcloud\n' > /run/secrets/nextcloud_db_name
printf 'nextcloud\n' > /run/secrets/nextcloud_db_user

if [ ! -s /run/secrets/nextcloud_db_password ]; then
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > /run/secrets/nextcloud_db_password
fi
if [ ! -s /run/secrets/nextcloud_db_root_password ]; then
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > /run/secrets/nextcloud_db_root_password
fi

if [ ! -s /run/secrets/nextcloud_admin_password ]; then
    if [ -n "${ADMIN_PASSWORD:-}" ]; then
        printf '%s' "$ADMIN_PASSWORD" > /run/secrets/nextcloud_admin_password
    else
        od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > /run/secrets/nextcloud_admin_password
    fi
fi

chown 33:33 /run/secrets/nextcloud_db_name /run/secrets/nextcloud_db_user /run/secrets/nextcloud_db_password
chmod 400 /run/secrets/nextcloud_db_name /run/secrets/nextcloud_db_user /run/secrets/nextcloud_db_password
chown 0:0 /run/secrets/nextcloud_db_root_password /run/secrets/nextcloud_admin_password
chmod 400 /run/secrets/nextcloud_db_root_password /run/secrets/nextcloud_admin_password
