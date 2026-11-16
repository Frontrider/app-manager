#!/bin/sh
set -eu

storage=/storage
conf="$storage/conf"

for directory in "$conf" "$storage/data" "$storage/lib/plugins" "$storage/lib/tpl"; do
    if [ ! -d "$directory" ]; then
        echo "DokuWiki storage directory is missing: $directory" >&2
        exit 1
    fi
done

password_file=/global-env/lldap_service_password
if [ ! -r "$password_file" ] || [ ! -s "$password_file" ]; then
    echo "The LLDAP service password file is missing or empty: $password_file" >&2
    exit 1
fi

# Configuration may contain the LDAP service password; keep it private.
umask 077

cat > "$conf/local.php" <<'PHP'
<?php
// Managed by the app catalog; overwritten on every start.
$conf['title'] = getenv('DOKUWIKI_TITLE') ?: 'DokuWiki';
$conf['basedir'] = '/dokuwiki/';
$conf['baseurl'] = '';
$conf['useacl'] = 1;
$conf['disableactions'] = 'register';
PHP

cat > "$conf/local.protected.php" <<'PHP'
<?php
// Managed by the app catalog; overwritten on every start.
$conf['authtype'] = 'authldap';
$conf['superuser'] = '@' . (getenv('LDAP_ADMIN_GROUP') ?: 'lldap_admin');

$ldapHost = getenv('LDAP_HOST') ?: 'core';
$ldapPort = getenv('LDAP_PORT') ?: '3890';
$userBaseDn = getenv('LDAP_USER_BASE_DN') ?: 'ou=people,dc=example,dc=com';
$groupBaseDn = getenv('LDAP_GROUP_BASE_DN') ?: 'ou=groups,dc=example,dc=com';
$serviceDn = getenv('LDAP_SERVICE_DN') ?: 'uid=service,' . $userBaseDn;

$conf['plugin']['authldap']['server'] = 'ldap://' . $ldapHost . ':' . $ldapPort;
$conf['plugin']['authldap']['usertree'] = $userBaseDn;
$conf['plugin']['authldap']['grouptree'] = $groupBaseDn;
$conf['plugin']['authldap']['userfilter'] = '(&(uid=%{user})(objectClass=person))';
$conf['plugin']['authldap']['groupfilter'] = '(&(member=%{dn})(objectClass=groupOfUniqueNames))';
$conf['plugin']['authldap']['attributes'] = ['cn', 'displayname', 'mail', 'givenname', 'objectclass', 'sn', 'uid', 'memberof'];
$conf['plugin']['authldap']['version'] = 3;
$conf['plugin']['authldap']['starttls'] = filter_var(
    getenv('LDAP_START_TLS') ?: 'false',
    FILTER_VALIDATE_BOOL,
) ? 1 : 0;
$conf['plugin']['authldap']['modPass'] = 0;
$conf['plugin']['authldap']['binddn'] = $serviceDn;
$conf['plugin']['authldap']['bindpw'] = trim(file_get_contents('/global-env/lldap_service_password'));
PHP

cat > "$conf/acl.auth.php" <<'PHP'
# acl.auth.php
# <?php exit()?>
# Managed by the app catalog; overwritten on every start.
*               @ALL        1
PHP

umask 022
exec /dokuwiki-entrypoint.sh "$@"
