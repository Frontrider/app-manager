#!/bin/sh
set -eu

domain_file=/global-env/DOMAIN
if [ ! -s "$domain_file" ]; then
    domain_file=/global-env/domain
fi
if [ ! -r "$domain_file" ]; then
    echo "The global environment domain file is missing or unreadable." >&2
    exit 1
fi

raw_domain=$(tr -d '[:space:]' < "$domain_file")

# The core may publish a full origin such as https://example.com:8443/.
case "$raw_domain" in
    *://*) raw_domain=${raw_domain#*://};;
esac
raw_domain=${raw_domain%%/*}

# Accept an optional port, and drop a trailing DNS dot from the host.
case "$raw_domain" in
    \[*\]:*)
        domain=${raw_domain%:*}
        domain=${domain#[}
        domain=${domain%]}
        domain_port=${raw_domain##*:}
        ;;
    *:*)
        domain=${raw_domain%:*}
        domain_port=${raw_domain##*:}
        ;;
    *)
        domain=$raw_domain
        domain_port=
        ;;
esac
domain=${domain%.}

if [ -z "$domain" ] || ! printf '%s\n' "$domain" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$'; then
    echo "The global environment domain is invalid: '$raw_domain'" >&2
    exit 1
fi

if [ -n "$domain_port" ]; then
    case "$domain_port" in
        *[!0-9]*)
            echo "The global environment domain has an invalid port: '$raw_domain'" >&2
            exit 1
            ;;
    esac
    if [ "$domain_port" -lt 1 ] || [ "$domain_port" -gt 65535 ]; then
        echo "The global environment domain has an invalid port: '$raw_domain'" >&2
        exit 1
    fi
    public_domain="$domain:$domain_port"
else
    public_domain="$domain"
fi

scheme=https
https_file=/global-env/HTTPS_ENABLED
if [ -f "$https_file" ] && [ "$(tr -d '[:space:]' < "$https_file")" = "off" ]; then
    scheme=http
fi

ldap_host=${LDAP_HOST:-core}
ldap_port=${LDAP_PORT:-3890}
user_base=${LDAP_USER_BASE_DN:-ou=people,dc=example,dc=com}
group_base=${LDAP_GROUP_BASE_DN:-ou=groups,dc=example,dc=com}
service_dn=${LDAP_SERVICE_DN:-uid=service,$user_base}
admin_group=${LDAP_ADMIN_GROUP:-lldap_admin}

case "$admin_group" in
    ''|*[!A-Za-z0-9._-]*)
        echo "LDAP_ADMIN_GROUP must use letters, numbers, dots, underscores, or hyphens." >&2
        exit 1
        ;;
esac

ldap_tls=false
case "${LDAP_START_TLS:-false}" in
    true|TRUE|1|yes|YES)
        ldap_tls=true
        ;;
esac

password_file=/global-env/lldap_service_password
if [ ! -r "$password_file" ] || [ ! -s "$password_file" ]; then
    echo "The LLDAP service password file is missing or empty: $password_file" >&2
    exit 1
fi
ldap_password=$(tr -d '\r\n' < "$password_file")

export PROSODY_DOMAIN="$domain"
export PROSODY_PUBLIC_DOMAIN="$public_domain"
export PROSODY_SCHEME="$scheme"
export PROSODY_LDAP_SERVER="$ldap_host:$ldap_port"
export PROSODY_LDAP_BASE="$user_base"
export PROSODY_LDAP_GROUP_BASE="$group_base"
export PROSODY_LDAP_ROOTDN="$service_dn"
export PROSODY_LDAP_PASSWORD="$ldap_password"
export PROSODY_LDAP_TLS="$ldap_tls"
export PROSODY_ADMIN_GROUP="$admin_group"

admins=$(lua5.4 /usr/local/share/prosody-list-admins.lua) || {
    echo "Could not read the LLDAP administrator group." >&2
    exit 1
}
export PROSODY_ADMINS="$admins"

exec /entrypoint.sh "$@"
