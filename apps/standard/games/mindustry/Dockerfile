FROM eclipse-temurin:21-jre
RUN mkdir -p /opt/mindustry /data \
    && curl -fsSL https://github.com/Anuken/Mindustry/releases/latest/download/server-release.jar \
        -o /opt/mindustry/server.jar \
    && chown 1000:1000 /data
WORKDIR /data
USER 1000:1000
CMD ["java", "-jar", "/opt/mindustry/server.jar", "host"]
