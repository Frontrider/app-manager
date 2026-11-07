#!/bin/sh
# Runs once in the jellyfin-init container before Jellyfin starts. Jellyfin's config volume is /config.
set -eu

if [ "$(id -u)" = 0 ]; then
    mkdir -p /data/videos
    chown 1000:1000 /data/videos
    chown -R 1000:1000 /config
    exec su-exec 1000:1000 /bin/sh "$0" "$@"
fi

LDAP_PLUGIN_VERSION=24.0.0.0
LDAP_PLUGIN_SHA256=3be1f9d6a6ce9ea375e556dd30136d178a8dbe35cbe866d30d3451dc3ff7e804
JF_CONFIG=/config/config
PLUGIN_DIR=/config/plugins/LDAP-Auth_$LDAP_PLUGIN_VERSION
PLUGIN_CONFIG=/config/plugins/configurations/Jellyfin.Plugin.LDAP_Auth.xml

mkdir -p "$JF_CONFIG" /config/data /config/cache /config/log /config/plugins/configurations

# Install the plugin once. Later boots keep the installed copy.
if [ ! -d "$PLUGIN_DIR" ]; then
    curl -fsSL "https://repo.jellyfin.org/files/plugin/ldap-authentication/ldap-authentication_$LDAP_PLUGIN_VERSION.zip" -o /tmp/ldap-plugin.zip
    echo "$LDAP_PLUGIN_SHA256  /tmp/ldap-plugin.zip" | sha256sum -c -
    mkdir -p "$PLUGIN_DIR"
    unzip -q /tmp/ldap-plugin.zip -d "$PLUGIN_DIR"
    rm /tmp/ldap-plugin.zip
fi

# LDAP settings point at LLDAP in the core container. Settings are created once so UI changes survive restarts.
# Only the bind password is refreshed each boot, since ADMIN_PASS can change.
ESCAPED_PASS=$(printf '%s' "$LDAP_BIND_PASSWORD" | sed -e 's/[&|\\]/\\&/g' -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g')
if [ ! -s "$PLUGIN_CONFIG" ]; then
    cat > "$PLUGIN_CONFIG" <<XML
<?xml version="1.0" encoding="utf-8"?>
<PluginConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <LdapServer>$LDAP_HOST</LdapServer>
  <LdapPort>3890</LdapPort>
  <UseSsl>false</UseSsl>
  <UseStartTls>false</UseStartTls>
  <SkipSslVerify>false</SkipSslVerify>
  <LdapBindUser>uid=admin,ou=people,dc=example,dc=com</LdapBindUser>
  <LdapBindPassword>$ESCAPED_PASS</LdapBindPassword>
  <LdapBaseDn>dc=example,dc=com</LdapBaseDn>
  <LdapSearchFilter>(uid={0})</LdapSearchFilter>
  <LdapAdminBaseDn>dc=example,dc=com</LdapAdminBaseDn>
  <LdapAdminFilter>(&amp;(uid={0})(memberOf=cn=lldap_admin,ou=groups,dc=example,dc=com))</LdapAdminFilter>
  <LdapSearchAttributes>uid, cn, mail</LdapSearchAttributes>
  <CreateUsersFromLdap>true</CreateUsersFromLdap>
  <LdapUidAttribute>uid</LdapUidAttribute>
  <LdapUsernameAttribute>uid</LdapUsernameAttribute>
  <LdapPasswordAttribute>userPassword</LdapPasswordAttribute>
  <AllowPassChange>false</AllowPassChange>
  <EnableAllFolders>true</EnableAllFolders>
  <PasswordResetUrl></PasswordResetUrl>
</PluginConfiguration>
XML
else
    sed -i "s|<LdapBindPassword>.*</LdapBindPassword>|<LdapBindPassword>$ESCAPED_PASS</LdapBindPassword>|" "$PLUGIN_CONFIG"
fi

# Jellyfin reads its base URL from network.xml. Set it on every boot so the /jellyfin prefix survives.
NETWORK_XML=$JF_CONFIG/network.xml
if [ ! -s "$NETWORK_XML" ]; then
    cat > "$NETWORK_XML" <<XML
<?xml version="1.0" encoding="utf-8"?>
<NetworkConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <BaseUrl>/jellyfin</BaseUrl>
</NetworkConfiguration>
XML
else
    sed -i -e 's|<BaseUrl />|<BaseUrl>/jellyfin</BaseUrl>|' -e 's|<BaseUrl>[^<]*</BaseUrl>|<BaseUrl>/jellyfin</BaseUrl>|' "$NETWORK_XML"
fi

# The API bootstrap completes the wizard. Never reset its state on later starts.
SYSTEM_XML=$JF_CONFIG/system.xml
if [ ! -s "$SYSTEM_XML" ]; then
    cat > "$SYSTEM_XML" <<XML
<?xml version="1.0" encoding="utf-8"?>
<ServerConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <IsStartupWizardCompleted>false</IsStartupWizardCompleted>
</ServerConfiguration>
XML
fi
