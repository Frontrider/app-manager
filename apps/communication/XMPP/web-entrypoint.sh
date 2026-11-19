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
if [ -f /global-env/HTTPS_ENABLED ] && [ "$(tr -d '[:space:]' < /global-env/HTTPS_ENABLED)" = "off" ]; then
    scheme=http
fi

ws_scheme=wss
if [ "$scheme" = "http" ]; then
    ws_scheme=ws
fi

app_ws="${XMPP_WEB_WS:-$ws_scheme://$public_domain/prosody/xmpp-websocket}"
default_muc="${XMPP_WEB_MUC:-conference.$domain}"

# Keep the image's entrypoint hooks from rewriting the generated file.
export APP_REGISTERED_ACCESS=1
export APP_GUEST_ACCESS=0
export APP_HTTP_AUTODISCOVERY=0
export APP_IS_STYLING_DISABLED=0
export APP_HAS_SENDING_ENTER_KEY=0

# Write the client configuration directly instead of relying on the image's
# sed-based local.js updater, whose default strings can differ by release.
cat > /usr/share/nginx/html/local.js <<EOF
var config = {
  name: 'Prosody Web',
  transports: {
    websocket: '$app_ws',
  },
  hasGuestAccess: false,
  hasRegisteredAccess: true,
  anonymousHost: null,
  isTransportsUserAllowed: false,
  hasHttpAutoDiscovery: false,
  resource: 'Web XMPP',
  defaultDomain: '$domain',
  defaultMuc: '$default_muc',
  isStylingDisabled: false,
  hasSendingEnterKey: false,
  connectTimeout: 5000,
  pinnedMucs: [],
  logoUrl: '',
  sso: {
    endpoint: false,
    jidHeader: 'jid',
    passwordHeader: 'password',
  },
  guestDescription: '',
}
EOF

exec /docker-entrypoint.sh "$@"
