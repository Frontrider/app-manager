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

basic_auth=$(printf '%s' "admin@$domain:$admin_password" | base64 | tr -d '\n')
if [ -z "$basic_auth" ]; then
    echo "Could not build the ejabberd administrator credentials." >&2
    exit 1
fi

cat > /etc/nginx/conf.d/default.conf <<EOF
server {
    listen 8081;
    resolver 127.0.0.11 valid=10s ipv6=off;
    set \$ejabberd http://ejabberd:5280;

    location / {
        proxy_pass \$ejabberd;
        proxy_set_header Host \$host;
        proxy_set_header Authorization "Basic ${basic_auth}";
    }
}
EOF

exec "$@"
