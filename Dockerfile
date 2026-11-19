FROM docker:29-cli AS docker-cli
FROM composer:2 AS composer-cli
FROM busybox:1.37.0-musl AS static-server

FROM node:22-alpine AS documentation
WORKDIR /docs
COPY docs/package.json docs/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY docs/docusaurus.config.js docs/sidebars.js ./
COPY docs/pages ./pages
RUN npm run build

FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl libfcgi-bin libicu-dev libldap2-dev libonig-dev libzip-dev unzip xz-utils \
    && docker-php-ext-install -j"$(nproc)" intl ldap mbstring opcache zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

FROM php-base AS dependencies
COPY --from=composer-cli /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
COPY app ./app
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && composer check-platform-reqs --no-dev

FROM php-base AS runtime

ARG S6_OVERLAY_VERSION=3.2.3.2
ARG TARGETARCH

RUN set -eux; \
    case "${TARGETARCH:-$(uname -m)}" in \
        amd64|x86_64) s6_arch=x86_64 ;; \
        arm64|aarch64) s6_arch=aarch64 ;; \
        *) echo "Unsupported architecture: ${TARGETARCH:-$(uname -m)}" >&2; exit 1 ;; \
    esac; \
    base="https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}"; \
    curl -fsSL -o /tmp/s6-overlay-noarch.tar.xz "${base}/s6-overlay-noarch.tar.xz"; \
    curl -fsSL -o "/tmp/s6-overlay-${s6_arch}.tar.xz" "${base}/s6-overlay-${s6_arch}.tar.xz"; \
    curl -fsSL -o /tmp/s6-overlay-noarch.tar.xz.sha256 "${base}/s6-overlay-noarch.tar.xz.sha256"; \
    curl -fsSL -o "/tmp/s6-overlay-${s6_arch}.tar.xz.sha256" "${base}/s6-overlay-${s6_arch}.tar.xz.sha256"; \
    (cd /tmp && sha256sum -c s6-overlay-noarch.tar.xz.sha256 "s6-overlay-${s6_arch}.tar.xz.sha256"); \
    tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz; \
    tar -C / -Jxpf "/tmp/s6-overlay-${s6_arch}.tar.xz"; \
    rm -f /tmp/s6-overlay-*.tar.xz /tmp/s6-overlay-*.sha256

COPY docker/s6/ /etc/s6-overlay/
COPY --from=static-server /bin/busybox /usr/local/bin/busybox
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app-manager.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app-manager.ini
COPY docker/entrypoint.sh /usr/local/bin/app-manager-entrypoint
RUN chmod +x /usr/local/bin/app-manager-entrypoint \
    /etc/s6-overlay/s6-rc.d/app-manager-php-fpm/run \
    /etc/s6-overlay/s6-rc.d/app-manager-php-fpm/finish \
    /etc/s6-overlay/s6-rc.d/app-manager-docs/run \
    /etc/s6-overlay/s6-rc.d/app-manager-public/run \
    && groupmod --gid 1000 www-data \
    && usermod --uid 1000 --gid 1000 www-data

COPY --from=dependencies /var/www/html/vendor ./vendor
COPY app ./app
COPY public ./public
COPY --from=documentation /docs/build /srv/documentation/docs
COPY spark ./spark
COPY apps /apps
RUN mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar \
    && chown -R www-data:www-data writable

ENV CI_ENVIRONMENT=production \
    APPS_DIR=/apps \
    DOCKER_BIN=/usr/local/bin/docker \
    DOCKER_HOST=unix:///var/run/docker.sock \
    S6_BEHAVIOUR_IF_STAGE2_FAILS=2

ENTRYPOINT ["app-manager-entrypoint"]
EXPOSE 9000 8080 8081
