FROM nextcloud:apache

COPY entrypoint.sh /usr/local/bin/localhost-nextcloud-entrypoint.sh
COPY before-starting.sh /docker-entrypoint-hooks.d/before-starting/10-localhost-config.sh

USER root
RUN chmod +x /usr/local/bin/localhost-nextcloud-entrypoint.sh \
    /docker-entrypoint-hooks.d/before-starting/10-localhost-config.sh

ENTRYPOINT ["/usr/local/bin/localhost-nextcloud-entrypoint.sh"]
CMD ["apache2-foreground"]
