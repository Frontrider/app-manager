FROM eclipse-temurin:21-jre
RUN curl -fsSL https://download.geysermc.org/v2/projects/geyser/versions/latest/builds/latest/downloads/standalone \
        -o /opt/geyser.jar \
    && mkdir -p /data && chown 1000:1000 /data
COPY entrypoint.sh /opt/entrypoint.sh
ENV HOME=/data
WORKDIR /data
USER 1000:1000
ENTRYPOINT ["/bin/sh", "/opt/entrypoint.sh"]
