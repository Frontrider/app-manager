FROM dokuwiki/dokuwiki:stable

# Configuration is applied by the container on every start.
COPY entrypoint.sh /usr/local/bin/localhost-dokuwiki-entrypoint.sh
RUN chmod +x /usr/local/bin/localhost-dokuwiki-entrypoint.sh \
    && rm -f /var/www/html/install.php

ENTRYPOINT ["/usr/local/bin/localhost-dokuwiki-entrypoint.sh"]
CMD ["apache2-foreground"]
