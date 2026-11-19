#!/bin/sh
set -eu

fail() {
    echo "$1" >&2
    exit 1
}

domain_file=/global-env/domain
if [ ! -f "$domain_file" ]; then
    domain_file=/global-env/DOMAIN
fi
if [ ! -r "$domain_file" ]; then
    fail "The global environment domain file is missing or unreadable."
fi

domain=$(tr -d '[:space:]' < "$domain_file")
if ! printf '%s\n' "$domain" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$'; then
    fail "The global environment domain is invalid."
fi

scheme=https
https_file=/global-env/HTTPS_ENABLED
if [ -f "$https_file" ] && [ "$(tr -d '[:space:]' < "$https_file")" = "off" ]; then
    scheme=http
fi

admin_username=${ADMIN_USERNAME:-forgejo}
admin_password=${ADMIN_PASSWORD:-}
admin_email=${ADMIN_EMAIL:-}
if [ -z "$admin_password" ]; then
    fail "ADMIN_PASSWORD must be set."
fi
case "$admin_username" in
    ''|admin|*[!A-Za-z0-9._-]*)
        fail "ADMIN_USERNAME must use letters, numbers, dots, underscores, or hyphens and must not be 'admin'."
        ;;
esac
if [ -z "$admin_email" ]; then
    admin_email="$admin_username@$domain"
fi

config=/data/gitea/conf/app.ini
work_path=/data/gitea

users=$(forgejo --config "$config" --work-path "$work_path" admin user list) \
    || fail "Could not list Forgejo users."
if ! printf '%s\n' "$users" | tail -n +2 | awk '{print $2}' | grep -Fxq "$admin_username"; then
    forgejo --config "$config" --work-path "$work_path" admin user create \
        --admin --username "$admin_username" --email "$admin_email" \
        --password "$admin_password" --must-change-password=false \
        || fail "Could not create the Forgejo administrator."
fi
if ! forgejo --config "$config" --work-path "$work_path" admin user list \
    | tail -n +2 | awk '{print $2}' | grep -Fxq "$admin_username"; then
    fail "The Forgejo administrator was not created."
fi

labels=${RUNNER_LABELS:-docker:docker://node:24-bookworm,ubuntu-latest:docker://node:24-bookworm}
if [ -z "$labels" ]; then
    fail "RUNNER_LABELS must not be empty."
fi

secret_file=/runner/secret
if [ ! -s "$secret_file" ]; then
    umask 077
    od -An -N20 -tx1 /dev/urandom | tr -d ' \n' > "$secret_file"
fi
secret=$(cat "$secret_file")
if ! printf '%s\n' "$secret" | grep -Eq '^[0-9a-f]{40}$'; then
    fail "The bundled runner secret is invalid."
fi

uuid=""
attempt=0
while [ "$attempt" -lt 10 ]; do
    if uuid=$(forgejo --config "$config" --work-path "$work_path" forgejo-cli actions register \
        --name default --secret "$secret" --labels "$labels" 2>/tmp/runner-register.err); then
        break
    fi
    attempt=$((attempt + 1))
    sleep 2
done
if ! printf '%s' "$uuid" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'; then
    if [ -s /tmp/runner-register.err ]; then
        cat /tmp/runner-register.err >&2
    fi
    fail "Could not register the bundled Forgejo runner."
fi

labels_yaml=""
old_ifs=$IFS
IFS=','
for label in $labels; do
    label=$(printf '%s' "$label" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    case "$label" in
        ''|*'"'*|*'\'*|*'['*|*']'*)
            fail "RUNNER_LABELS contains an invalid label."
            ;;
    esac
    labels_yaml="${labels_yaml}${labels_yaml:+, }\"$label\""
done
IFS=$old_ifs

umask 077
cat > /runner/config.yml <<EOF
log:
  level: info
runner:
  file: /data/.runner
  capacity: 1
  labels: [${labels_yaml}]
cache:
  enabled: true
  dir: /data/cache
container:
  docker_host: tcp://docker:2376
host:
  workdir_parent: /data
server:
  connections:
    forgejo:
      url: ${scheme}://${domain}/forgejo/
      uuid: ${uuid}
      token: ${secret}
EOF
chmod 600 /runner/config.yml
