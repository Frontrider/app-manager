FROM alpine:3.22 AS permissions
COPY fix-permissions.sh /fix-permissions.sh
ENTRYPOINT ["/bin/sh", "/fix-permissions.sh"]
CMD ["/data", "/state"]

FROM drakkan/sftpgo:latest AS app
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && arch="$(dpkg --print-architecture)" \
    && curl -fsSL "https://github.com/sftpgo/sftpgo-plugin-auth/releases/latest/download/sftpgo-plugin-auth-linux-${arch}" \
        -o /usr/local/bin/sftpgo-plugin-auth \
    && chmod 755 /usr/local/bin/sftpgo-plugin-auth \
    && rm -rf /var/lib/apt/lists/*
COPY entrypoint.sh /usr/local/bin/app-entrypoint
COPY groups.json /etc/sftpgo/appstore-groups.json
RUN chmod 755 /usr/local/bin/app-entrypoint
USER 1000:1000
ENTRYPOINT ["app-entrypoint"]
CMD ["sftpgo", "serve"]
