FROM ghcr.io/processone/ejabberd:latest

COPY ejabberd.yml.template /usr/local/share/ejabberd.yml
COPY entrypoint.sh /usr/local/bin/localhost-ejabberd-entrypoint.sh

USER root
RUN chmod +x /usr/local/bin/localhost-ejabberd-entrypoint.sh
USER ejabberd

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/localhost-ejabberd-entrypoint.sh"]
CMD ["foreground"]
