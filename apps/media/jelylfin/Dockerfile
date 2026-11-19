FROM alpine:3.22 AS init
RUN apk add --no-cache curl unzip su-exec xmlstarlet
COPY seed.sh /seed.sh
ENTRYPOINT ["/bin/sh", "/seed.sh"]

FROM python:3.13-alpine AS library
COPY library-setup.py /library-setup.py
USER 1000:1000
CMD ["python", "/library-setup.py"]

FROM jellyfin/jellyfin:latest AS app
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 unzip xmlstarlet \
    && rm -rf /var/lib/apt/lists/*
COPY seed.sh library-setup.py entrypoint.sh /
USER 1000:1000
ENTRYPOINT ["/bin/sh", "/entrypoint.sh"]
CMD ["/jellyfin/jellyfin"]
