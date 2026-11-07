FROM alpine:3.22
COPY fix-permissions.sh /fix-permissions.sh
ENTRYPOINT ["/bin/sh", "/fix-permissions.sh"]
CMD ["/data", "/cfg"]
