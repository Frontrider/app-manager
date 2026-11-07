FROM node:16-bullseye AS build
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg git git-lfs default-jre-headless \
        autoconf automake make g++ \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /src
RUN git clone https://github.com/tobspr-games/shapez.io.git . \
    && git checkout 8a79de5255fb6756f3a1d26579b1e9bc36e0c811 \
    && git lfs pull \
    && yarn install --frozen-lockfile \
    && cd gulp && yarn install --frozen-lockfile \
    && yarn gulp build.web-localhost

FROM nginxinc/nginx-unprivileged:alpine
COPY --from=build /src/build /usr/share/nginx/html
USER 1000:1000
