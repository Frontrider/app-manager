FROM alpine:3.22 AS init
RUN apk add --no-cache curl unzip su-exec
COPY seed.sh /seed.sh
ENTRYPOINT ["/bin/sh", "/seed.sh"]

FROM python:3.13-alpine AS library
COPY library-setup.py /library-setup.py
USER 1000:1000
CMD ["python", "/library-setup.py"]
