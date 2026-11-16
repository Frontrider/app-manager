FROM debian:trixie-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends freeorion \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data && chown 1000:1000 /data
ENV HOME=/data
WORKDIR /usr/lib/freeorion
USER 1000:1000
CMD ["/usr/lib/freeorion/freeoriond", "--resource.path", "/usr/share/games/freeorion/default", "--hostless"]
