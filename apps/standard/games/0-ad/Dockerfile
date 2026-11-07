FROM debian:trixie-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends 0ad \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data && chown 1000:1000 /data
ENV HOME=/data
WORKDIR /data
USER 1000:1000
CMD ["/usr/games/pyrogenesis", "-autostart-nonvisual", "-autostart=random/mainland", "-autostart-host", "-autostart-players=2", "-autostart-port=20595"]
