#!/bin/sh
set -eu

domain_file=/global-env/domain
if [ ! -f "$domain_file" ]; then
    domain_file=/global-env/DOMAIN
fi
if [ ! -r "$domain_file" ]; then
    echo "The global environment domain file is missing or unreadable." >&2
    exit 1
fi

domain=$(tr -d '[:space:]' < "$domain_file")
if ! printf '%s\n' "$domain" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$'; then
    echo "The global environment domain is invalid." >&2
    exit 1
fi

scheme=https
https_file=/global-env/HTTPS_ENABLED
if [ -f "$https_file" ] && [ "$(tr -d '[:space:]' < "$https_file")" = "off" ]; then
    scheme=http
fi

admin_password_file=/run/secrets/nextcloud_admin_password
if [ ! -r "$admin_password_file" ] || [ ! -s "$admin_password_file" ]; then
    echo "The local Nextcloud administrator password is missing or empty." >&2
    exit 1
fi
admin_password=$(cat "$admin_password_file")
case "$admin_password" in
    *[!A-Za-z0-9._~+/=@-]*)
        echo "The local Nextcloud administrator password contains unsupported characters." >&2
        exit 1
        ;;
esac

ldap_password_file=/global-env/lldap_service_password
if [ ! -r "$ldap_password_file" ] || [ ! -s "$ldap_password_file" ]; then
    echo "The LLDAP service password file is missing or empty: $ldap_password_file" >&2
    exit 1
fi

export NEXTCLOUD_ADMIN_USER=admin
export NEXTCLOUD_ADMIN_PASSWORD="$admin_password"
export NEXTCLOUD_TRUSTED_DOMAINS="$domain"
export OVERWRITEWEBROOT=/nextcloud
export OVERWRITEPROTOCOL="$scheme"
export OVERWRITEHOST="$domain"
export OVERWRITECLIURL="${scheme}://${domain}/nextcloud"
export TRUSTED_PROXIES="10.0.0.0/8 172.16.0.0/12 192.168.0.0/16"
export APACHE_DISABLE_REWRITE_IP=1

# The before-starting hook uses these to configure the LLDAP backend.
export LDAP_HOST="${LDAP_HOST:-core}"
export LDAP_PORT="${LDAP_PORT:-3890}"
export LDAP_USER_BASE_DN="${LDAP_USER_BASE_DN:-ou=people,dc=example,dc=com}"
export LDAP_GROUP_BASE_DN="${LDAP_GROUP_BASE_DN:-ou=groups,dc=example,dc=com}"
export LDAP_BASE_DN="${LDAP_BASE_DN:-${LDAP_USER_BASE_DN#ou=people,}}"
export LDAP_SERVICE_DN="${LDAP_SERVICE_DN:-uid=service,${LDAP_USER_BASE_DN}}"
export LDAP_ADMIN_GROUP="${LDAP_ADMIN_GROUP:-lldap_admin}"
export LDAP_START_TLS="${LDAP_START_TLS:-false}"

exec /entrypoint.sh "$@"
