<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class LdapSession implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = session()->get('ldap_user');
        if (! is_array($user) || ! is_string($user['username'] ?? null)
            || ! is_bool($user['isAdmin'] ?? null) || ! is_int($user['expiresAt'] ?? null)
            || $user['expiresAt'] <= time()) {
            session()->remove('ldap_user');
            return redirect()->to(site_url('login'));
        }

        if (in_array('admin', $arguments ?? [], true) && ! $user['isAdmin']) {
            return service('response')->setStatusCode(403)
                ->setBody('Only members of lldap_admin can manage applications.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
