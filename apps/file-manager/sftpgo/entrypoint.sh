#!/bin/sh
set -eu

file=/var/environment/global-env/lldap_service_password
if [ ! -r "$file" ]; then
    echo "LLDAP service password file is missing or unreadable: $file" >&2
    exit 1
fi
SFTPGO_PLUGIN_AUTH_LDAP_PASSWORD=$(cat "$file")
if [ -z "$SFTPGO_PLUGIN_AUTH_LDAP_PASSWORD" ]; then
    echo "LLDAP service password file is empty." >&2
    exit 1
fi
export SFTPGO_PLUGIN_AUTH_LDAP_PASSWORD

credentials=/var/lib/sftpgo/bootstrap-admin-password
if [ ! -e "$credentials" ]; then
    umask 077
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$credentials"
fi
SFTPGO_DEFAULT_ADMIN_PASSWORD=$(cat "$credentials")
if [ -z "$SFTPGO_DEFAULT_ADMIN_PASSWORD" ]; then
    echo "SFTPGo bootstrap administrator password is empty." >&2
    exit 1
fi
export SFTPGO_DEFAULT_ADMIN_PASSWORD
exec "$@"
