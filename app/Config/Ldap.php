<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Ldap extends BaseConfig
{
    public string $uri = 'ldap://lldap:3890';
    public string $userBaseDN = 'ou=people,dc=example,dc=com';
    public string $groupBaseDN = 'ou=groups,dc=example,dc=com';
    public string $userAttribute = 'uid';
    public string $adminGroup = 'lldap_admin';
    public bool $startTLS = false;
    public int $timeout = 5;
    public int $sessionLifetime = 3600;
}
