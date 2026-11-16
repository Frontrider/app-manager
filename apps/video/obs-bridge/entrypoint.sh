#!/bin/sh
set -eu

if [ -n "${MEDIAMTX_CONFIG_FILE:-}" ]; then
    case "$MEDIAMTX_CONFIG_FILE" in
        /*) ;;
        *) printf '%s\n' 'MEDIAMTX_CONFIG_FILE must be an absolute Docker host path.' >&2; exit 1 ;;
    esac
    if [ ! -f /custom-mediamtx.yml ] || [ ! -r /custom-mediamtx.yml ] || [ ! -s /custom-mediamtx.yml ]; then
        printf '%s\n' 'The custom MediaMTX configuration must be a nonempty file readable by UID 1000.' >&2
        exit 1
    fi
    unset MTX_AUTHINTERNALUSERS_0_PASS MTX_AUTHINTERNALUSERS_1_PASS
    exec /mediamtx /custom-mediamtx.yml
fi

: "${MTX_AUTHINTERNALUSERS_0_PASS:?Set PUBLISH_PASSWORD in the app configuration}"
: "${MTX_AUTHINTERNALUSERS_1_PASS:?Set READ_PASSWORD in the app configuration}"
exec /mediamtx "$@"
