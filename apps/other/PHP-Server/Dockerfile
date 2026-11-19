FROM php:8.4-apache

RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's#ErrorLog .*#ErrorLog /proc/self/fd/2#; s#CustomLog .*#CustomLog /proc/self/fd/1 combined#' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's#^ErrorLog .*#ErrorLog /proc/self/fd/2#' /etc/apache2/apache2.conf \
    && a2enmod rewrite

COPY apache.conf /etc/apache2/conf-available/php-site.conf
RUN a2enconf php-site

COPY entrypoint.sh /usr/local/bin/localhost-php-site-entrypoint.sh
RUN chmod +x /usr/local/bin/localhost-php-site-entrypoint.sh

WORKDIR /var/www/html

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/localhost-php-site-entrypoint.sh"]
CMD ["apache2", "-DFOREGROUND"]
