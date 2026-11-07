FROM python:3.13-alpine
COPY requirements.txt /requirements.txt
RUN pip install --no-cache-dir -r /requirements.txt
COPY library-setup.py /library-setup.py
COPY oidc-setup.py /oidc-setup.py
USER 1000:1000
CMD ["python", "/library-setup.py"]
