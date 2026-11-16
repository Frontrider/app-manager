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

admin_password=${ADMIN_PASSWORD:-}
if [ -z "$admin_password" ]; then
    echo "ADMIN_PASSWORD must be set." >&2
    exit 1
fi
case "$admin_password" in
    *[!A-Za-z0-9._~+/=@-]*)
        echo "ADMIN_PASSWORD may only contain letters, numbers, and . _ ~ + / = @ -" >&2
        exit 1
        ;;
esac

sed \
    -e "s|__DOMAIN__|$domain|g" \
    -e "s|__SCHEME__|$scheme|g" \
    /usr/local/share/ejabberd.yml > /opt/ejabberd/conf/ejabberd.yml

export EJABBERD_MACRO_HOST="$domain"
export EJABBERD_MACRO_ADMIN="admin@$domain"
export REGISTER_ADMIN_PASSWORD="$admin_password"

exec /usr/local/bin/ejabberdctl "$@"
