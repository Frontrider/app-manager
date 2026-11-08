FROM python:3.12-slim-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends calibre libmagickwand-6.q16-6 libmagic1 \
        build-essential libldap2-dev libsasl2-dev \
    && rm -rf /var/lib/apt/lists/*
COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt \
    && apt-get purge -y build-essential libldap2-dev libsasl2-dev \
    && rm /tmp/requirements.txt
COPY setup.py importer.py /opt/localhost/
ENV HOME=/config \
    CALIBRE_DBPATH=/config \
    CACHE_DIRECTORY=/config/cache \
    QT_QPA_PLATFORM=offscreen \
    PYTHONUNBUFFERED=1
WORKDIR /config
USER 1000:1000
CMD ["python", "/opt/localhost/importer.py"]
