#!/bin/sh
set -eu

: "${JAVA_SERVER_HOST:?Set JAVA_SERVER_HOST in env}"
: "${JAVA_SERVER_PORT:?Set JAVA_SERVER_PORT in env}"
: "${AUTH_TYPE:?Set AUTH_TYPE in env}"
: "${SERVER_PORT:?Set SERVER_PORT in env}"
case "$AUTH_TYPE" in
    online|offline|floodgate) ;;
    *) echo "AUTH_TYPE must be online, offline, or floodgate." >&2; exit 1 ;;
esac
case "$JAVA_SERVER_HOST" in
    *[!a-zA-Z0-9.:-]*|'') echo "Invalid JAVA_SERVER_HOST." >&2; exit 1 ;;
esac
for port in "$JAVA_SERVER_PORT" "$SERVER_PORT"; do
    case "$port" in
        *[!0-9]*|'') echo "Invalid server port: $port." >&2; exit 1 ;;
    esac
    if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
        echo "Server ports must be between 1 and 65535." >&2
        exit 1
    fi
done
cat > config.yml <<EOF
config-version: 4
bedrock:
  address: 0.0.0.0
  port: $SERVER_PORT
remote:
  address: "$JAVA_SERVER_HOST"
  port: $JAVA_SERVER_PORT
  auth-type: $AUTH_TYPE
EOF
exec java -Xms128M -Xmx512M -jar /opt/geyser.jar --nogui
