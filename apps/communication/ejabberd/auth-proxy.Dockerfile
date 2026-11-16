FROM nginx:alpine

COPY auth-proxy-entrypoint.sh /usr/local/bin/localhost-ejabberd-auth-entrypoint.sh
RUN chmod +x /usr/local/bin/localhost-ejabberd-auth-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/localhost-ejabberd-auth-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
