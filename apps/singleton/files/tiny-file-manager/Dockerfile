FROM alpine:3.22 AS permissions
COPY fix-permissions.sh /fix-permissions.sh
ENTRYPOINT ["/bin/sh", "/fix-permissions.sh"]
CMD ["/data"]

FROM php:8.4-cli-alpine AS app
RUN apk add --no-cache libzip-dev \
    && docker-php-ext-install zip \
    && curl -fsSL https://raw.githubusercontent.com/prasathmani/tinyfilemanager/2.6/tinyfilemanager.php \
        -o /var/www/html/index.php
WORKDIR /var/www/html
COPY config.php router.php ./
USER 1000:1000
EXPOSE 8080
CMD ["php", "-d", "upload_max_filesize=1G", "-d", "post_max_size=1G", "-S", "0.0.0.0:8080", "router.php"]
