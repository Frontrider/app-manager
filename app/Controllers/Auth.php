<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class Auth extends BaseController
{
    public function login(): ResponseInterface
    {
        return $this->response->setHeader('Cache-Control', 'no-store')
            ->setBody(view('login', ['error' => null]));
    }

    public function authenticate(): ResponseInterface
    {
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');
        if (! is_string($username) || ! is_string($password) || trim($username) === '' || $password === '') {
            return $this->loginError('Enter your LDAP username and password.', 400);
        }

        $username = trim($username);
        $throttler = service('throttler');
        if (! $throttler->check('ldap-ip-' . hash('sha256', $this->request->getIPAddress()), 20, 60)
            || ! $throttler->check('ldap-user-' . hash('sha256', strtolower($username)), 5, 60)) {
            return $this->loginError('Too many login attempts. Try again in a minute.', 429);
        }

        try {
            $user = service('ldapAuth')->authenticate($username, $password);
        } catch (RuntimeException $exception) {
            log_message('error', 'LDAP provider error: {message}', ['message' => $exception->getMessage()]);
            return $this->loginError('LDAP login is unavailable. Contact the administrator.', 503);
        }

        if ($user === null) {
            return $this->loginError('Invalid username or password.', 401);
        }

        session()->regenerate(true);
        session()->set('ldap_user', $user);
        return redirect()->to(site_url('/'))->withCookies();
    }

    public function logout(): ResponseInterface
    {
        session()->remove('ldap_user');
        session()->destroy();
        return redirect()->to(site_url('login'));
    }

    private function loginError(string $message, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
            ->setBody(view('login', ['error' => $message]));
    }
}
