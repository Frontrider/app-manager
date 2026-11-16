FROM ikatson/rqbit:latest AS upstream

FROM alpine:3.22
RUN apk add --no-cache su-exec
COPY --from=upstream /bin/rqbit /bin/rqbit
COPY --from=upstream /etc/ssl/cacerts.pem /localhost-certificates/cacerts.pem
RUN chmod 0755 /localhost-certificates && chmod 0644 /localhost-certificates/cacerts.pem
COPY entrypoint.sh /localhost-entrypoint.sh
ENV SSL_CERT_FILE=/localhost-certificates/cacerts.pem
ENTRYPOINT ["/bin/sh", "/localhost-entrypoint.sh"]
CMD ["server", "start", "/data"]
