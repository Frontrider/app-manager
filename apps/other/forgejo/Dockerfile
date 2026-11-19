FROM data.forgejo.org/forgejo/forgejo:17

COPY entrypoint.sh /usr/local/bin/localhost-forgejo-entrypoint.sh

USER root
RUN chmod +x /usr/local/bin/localhost-forgejo-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/localhost-forgejo-entrypoint.sh"]
