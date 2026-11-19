<?php

if (! function_exists('user_id')) {
    /**
     * Return the signed-in LDAP username for user-scoped settings.
     */
    function user_id(): ?string
    {
        $user = session()->get('ldap_user');

        return is_array($user) && is_string($user['username'] ?? null) ? $user['username'] : null;
    }
}
