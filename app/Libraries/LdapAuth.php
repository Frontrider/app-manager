<?php

namespace App\Libraries;

use Config\Ldap;
use RuntimeException;

class LdapAuth
{
    public function __construct(private Ldap $config)
    {
    }

    /**
     * @return array{username: string, isAdmin: bool, expiresAt: int}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        if ($username === '' || $password === '') {
            return null;
        }

        if (! extension_loaded('ldap')) {
            throw new RuntimeException('LDAP login requires the PHP LDAP extension.');
        }

        $scheme = parse_url($this->config->uri, PHP_URL_SCHEME);
        if (! in_array($scheme, ['ldap', 'ldaps'], true)
            || $this->config->userBaseDN === '' || $this->config->groupBaseDN === ''
            || $this->config->adminGroup === ''
            || ! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $this->config->userAttribute)
            || $this->config->timeout <= 0 || $this->config->sessionLifetime <= 0) {
            throw new RuntimeException('LDAP login is not configured correctly.');
        }

        $connection = @ldap_connect($this->config->uri);
        if ($connection === false) {
            throw new RuntimeException('Could not connect to the LDAP provider.');
        }

        try {
            if (! ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3)
                || ! ldap_set_option($connection, LDAP_OPT_REFERRALS, 0)
                || ! ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $this->config->timeout)
                || ! ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $this->config->timeout)) {
                throw new RuntimeException('Could not configure the LDAP connection.');
            }

            if ($scheme === 'ldap' && $this->config->startTLS && ! @ldap_start_tls($connection)) {
                throw new RuntimeException('Could not establish LDAP TLS. Check the provider certificate and TLS settings.');
            }

            $userDN = $this->config->userAttribute . '=' . ldap_escape($username, '', LDAP_ESCAPE_DN)
                . ',' . $this->config->userBaseDN;
            if (! @ldap_bind($connection, $userDN, $password)) {
                if (ldap_errno($connection) === 49) {
                    return null;
                }
                throw new RuntimeException('LDAP user authentication failed due to a provider error.');
            }

            // LLDAP users can read their own entry and memberOf without a search account.
            $entry = @ldap_read($connection, $userDN, '(objectClass=*)', ['memberOf']);
            if ($entry === false) {
                throw new RuntimeException('Could not read the signed-in LDAP user membership.');
            }
            $entries = ldap_get_entries($connection, $entry);
            if ($entries === false || $entries['count'] !== 1) {
                throw new RuntimeException('Could not read the signed-in LDAP user entry.');
            }

            $adminDN = 'cn=' . ldap_escape($this->config->adminGroup, '', LDAP_ESCAPE_DN)
                . ',' . $this->config->groupBaseDN;
            $memberships = $entries[0]['memberof'] ?? ['count' => 0];
            $isAdmin = false;
            for ($index = 0; $index < $memberships['count']; $index++) {
                if (strcasecmp($memberships[$index], $adminDN) === 0) {
                    $isAdmin = true;
                    break;
                }
            }

            return [
                'username' => $username,
                'isAdmin' => $isAdmin,
                'expiresAt' => time() + $this->config->sessionLifetime,
            ];
        } finally {
            ldap_unbind($connection);
        }
    }
}
