#!/bin/sh
set -eu

run_as() {
    if [ "$(id -u)" = 0 ]; then
        su -p www-data -s /bin/sh -c "$1"
    else
        sh -c "$1"
    fi
}

config=/var/www/html/config/zz-localhost.config.php

cat > "$config" <<'PHP'
<?php
$trustedDomains = getenv('NEXTCLOUD_TRUSTED_DOMAINS');
if (is_string($trustedDomains) && trim($trustedDomains) !== '') {
    $CONFIG['trusted_domains'] = array_values(array_filter(preg_split('/\s+/', trim($trustedDomains))));
}
PHP

chown www-data:www-data "$config"
chmod 640 "$config"

password_file=/global-env/lldap_service_password
if [ ! -r "$password_file" ] || [ ! -s "$password_file" ]; then
    echo "The LLDAP service password file is missing or empty: $password_file" >&2
    exit 1
fi
LDAP_PASSWORD=$(cat "$password_file")
export LDAP_PASSWORD
if [ -z "$LDAP_PASSWORD" ]; then
    echo "The LLDAP service password is empty." >&2
    exit 1
fi

case "${LDAP_START_TLS:-false}" in
    true|TRUE|1|yes|YES) export LDAP_TLS_VALUE=1 ;;
    *) export LDAP_TLS_VALUE=0 ;;
esac

run_as 'php /var/www/html/occ app:install user_ldap' || true
run_as 'php /var/www/html/occ app:enable user_ldap'

if ! run_as 'php /var/www/html/occ ldap:show-config s01' >/dev/null 2>&1; then
    run_as 'php /var/www/html/occ ldap:create-empty-config -p' >/dev/null
fi

run_as 'php /var/www/html/occ ldap:set-config s01 ldapHost "ldap://$LDAP_HOST"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapPort "$LDAP_PORT"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapAgentName "$LDAP_SERVICE_DN"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapAgentPassword "$LDAP_PASSWORD"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapBase "$LDAP_BASE_DN"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapBaseUsers "$LDAP_USER_BASE_DN"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapBaseGroups "$LDAP_GROUP_BASE_DN"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapConfigurationActive 1'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapTLS "$LDAP_TLS_VALUE"'
run_as 'php /var/www/html/occ ldap:set-config s01 turnOnPasswordChange 0'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapCacheTTL 600'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapExperiencedAdmin 0'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapGidNumber gidNumber'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapLoginFilter "(&(objectclass=person)(uid=%uid))"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapLoginFilterMode 0'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapLoginFilterEmail 0'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapLoginFilterUsername 1'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapUserFilter "(&(objectclass=person))"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapUserFilterMode 0'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapUserFilterObjectclass person'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapUserDisplayName displayname'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapEmailAttribute mail'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapUserAvatarRule default'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapGroupFilter "(&(objectclass=groupOfUniqueNames))"'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapGroupFilterMode 0'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapGroupFilterObjectclass groupOfUniqueNames'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapGroupDisplayName cn'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapGroupMemberAssocAttr uniqueMember'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapNestedGroups 0'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapPagingSize 500'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapMatchingRuleInChainState unknown'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapExpertUsernameAttr user_id'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapUuidUserAttribute auto'
run_as 'php /var/www/html/occ ldap:set-config s01 ldapUuidGroupAttribute auto'
run_as 'php /var/www/html/occ ldap:set-config s01 useMemberOfToDetectMembership 1'
run_as 'php /var/www/html/occ ldap:test-config s01'
run_as 'php /var/www/html/occ ldap:promote-group --yes "$LDAP_ADMIN_GROUP"' \
    || echo "Warning: could not promote the LLDAP admin group. LLDAP users can still log in." >&2
