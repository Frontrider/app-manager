FROM alpine:3.22 AS converse

ARG CONVERSE_VERSION=14.0.1

RUN apk add --no-cache curl tar \
    && curl -fsSL "https://github.com/conversejs/converse.js/releases/download/v${CONVERSE_VERSION}/converse.js-${CONVERSE_VERSION}.tgz" -o /tmp/converse.tgz \
    && mkdir -p /converse \
    && tar -xzf /tmp/converse.tgz -C /converse --strip-components=1 \
    && rm -f /tmp/converse.tgz

FROM nginxinc/nginx-unprivileged:alpine

COPY --from=converse /converse/dist /usr/share/nginx/html/dist
COPY index.html init.js /usr/share/nginx/html/
COPY entrypoint.sh /usr/local/bin/localhost-converse-entrypoint.sh

USER root
RUN chmod +x /usr/local/bin/localhost-converse-entrypoint.sh \
    && chown -R nginx:nginx /usr/share/nginx/html
USER nginx

ENTRYPOINT ["/usr/local/bin/localhost-converse-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
