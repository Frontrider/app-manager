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

ssh_port=${SSH_PORT:-2222}
case "$ssh_port" in
    ''|*[!0-9]*)
        echo "SSH_PORT must be a number between 1 and 65535." >&2
        exit 1
        ;;
esac
if [ "$ssh_port" -lt 1 ] || [ "$ssh_port" -gt 65535 ]; then
    echo "SSH_PORT must be a number between 1 and 65535." >&2
    exit 1
fi

root_url="$scheme://$domain/forgejo/"

export USER_UID=1000
export USER_GID=1000
export DOMAIN="$domain"
export SSH_DOMAIN="$domain"
export ROOT_URL="$root_url"
export SSH_PORT="$ssh_port"
export SSH_LISTEN_PORT="$ssh_port"
export HTTP_PORT=3000
export INSTALL_LOCK=true
export DISABLE_REGISTRATION=true
export LFS_START_SERVER=true

export FORGEJO__server__DOMAIN="$domain"
export FORGEJO__server__ROOT_URL="$root_url"
export FORGEJO__server__SSH_DOMAIN="$domain"
export FORGEJO__server__SSH_PORT="$ssh_port"
export FORGEJO__server__SSH_LISTEN_PORT="$ssh_port"
export FORGEJO__server__HTTP_PORT=3000
export FORGEJO__security__INSTALL_LOCK=true
export FORGEJO__service__DISABLE_REGISTRATION=true
export FORGEJO__actions__ENABLED=true
export FORGEJO__server__LFS_START_SERVER=true

exec /usr/bin/entrypoint "$@"
