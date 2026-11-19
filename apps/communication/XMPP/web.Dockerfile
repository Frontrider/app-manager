FROM nioc/xmpp-web:0.12.0

# The stock image's local.js updater relies on exact default strings and on
# environment variables. Ship a fixed configuration instead.
RUN rm -f /docker-entrypoint.d/998-update-local.js.sh
COPY local.js /usr/share/nginx/html/local.js
