FROM busybox:1.37.0-musl AS shell
FROM bluenviron/mediamtx:latest
COPY --from=shell /bin/busybox /busybox
COPY mediamtx.json /mediamtx.json
COPY entrypoint.sh /localhost-entrypoint.sh
USER 1000:1000
ENTRYPOINT ["/busybox", "sh", "/localhost-entrypoint.sh"]
CMD ["/mediamtx.json"]
