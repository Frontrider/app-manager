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
websocket_scheme=wss
if [ "$scheme" = "http" ]; then
    websocket_scheme=ws
fi

cat > /usr/share/nginx/html/config.js <<EOF
window.localhostConverseConfig = {
    assets_path: '/converse/dist',
    authentication: 'login',
    bosh_service_url: '${scheme}://${domain}/ejabberd/bosh',
    websocket_url: '${websocket_scheme}://${domain}/ejabberd/websocket',
    default_domain: '${domain}',
    discover_connection_methods: true,
    show_connection_url_input: true,
    view_mode: 'fullscreen',
    allow_registration: false,
    auto_away: 300,
    auto_reconnect: true,
    message_archiving: 'always',
    show_background: true
};
EOF

exec /docker-entrypoint.sh "$@"
