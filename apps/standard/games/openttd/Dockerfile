FROM debian:trixie-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends openttd openttd-opengfx \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data && chown 1000:1000 /data
ENV HOME=/data
WORKDIR /data
USER 1000:1000
CMD ["/usr/games/openttd", "-D", "0.0.0.0:3979", "-c", "/data/openttd.cfg"]
