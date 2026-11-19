FROM prosodyim/prosody:13.0

# mod_net_proxy implements the PROXY protocol, which lets Caddy terminate
# client TLS and forward plaintext XMPP to Prosody with the real client address.
# The community module is not part of the official image. This pinned copy is
# the current mod_net_proxy vendored by the xmpp-proxy project.
ADD https://raw.githubusercontent.com/moparisthebest/xmpp-proxy/bcb9de2f61b323013d6c9588c8e45ee45a7e22fa/contrib/prosody-modules/mod_net_proxy.lua /etc/prosody/modules/mod_net_proxy.lua

COPY prosody.cfg.lua /etc/prosody/prosody.cfg.lua
COPY list-admins.lua /usr/local/share/prosody-list-admins.lua
COPY entrypoint.sh /usr/local/bin/localhost-prosody-entrypoint.sh

RUN chmod 0644 /etc/prosody/modules/mod_net_proxy.lua \
 && chmod +x /usr/local/bin/localhost-prosody-entrypoint.sh

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/localhost-prosody-entrypoint.sh"]
CMD ["prosody", "-F"]
