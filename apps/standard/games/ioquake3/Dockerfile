FROM debian:trixie-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends ioquake3-server \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data/baseq3 \
    && chown -R 1000:1000 /data
ENV HOME=/data
WORKDIR /data
USER 1000:1000
ENTRYPOINT ["/usr/lib/ioquake3/ioq3ded"]
CMD ["+set", "dedicated", "2", "+set", "fs_basepath", "/data", "+set", "fs_homepath", "/data", "+map", "q3dm17"]
