FROM eclipse-temurin:17-jre
RUN curl -fsSL https://github.com/Pugmatt/BedrockConnect/releases/latest/download/BedrockConnect-1.0-SNAPSHOT.jar \
        -o /opt/bedrockconnect.jar \
    && mkdir -p /data && chown 1000:1000 /data
WORKDIR /data
USER 1000:1000
CMD ["java", "-Xms256M", "-Xmx256M", "-jar", "/opt/bedrockconnect.jar", "port=19132", "nodb=true"]
