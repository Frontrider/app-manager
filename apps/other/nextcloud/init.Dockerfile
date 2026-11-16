FROM alpine:3.22

COPY init.sh /usr/local/bin/nextcloud-init.sh
RUN chmod +x /usr/local/bin/nextcloud-init.sh

ENTRYPOINT ["/bin/sh", "/usr/local/bin/nextcloud-init.sh"]
