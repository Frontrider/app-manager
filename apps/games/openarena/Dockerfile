FROM debian:trixie-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends openarena-server \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data && chown 1000:1000 /data
ENV HOME=/data
USER 1000:1000
CMD ["/usr/games/openarena-server", "+set", "dedicated", "2", "+set", "fs_homepath", "/data", "+map", "oa_dm1"]
