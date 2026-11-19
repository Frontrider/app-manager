FROM alpine:3.22

COPY init.sh /init.sh

ENTRYPOINT ["/bin/sh", "/init.sh"]
