<?php

use App\Libraries\ApplicationModel;
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('seed', static function () {
    session()->set('ldap_user', [
        'username' => 'test-admin', 'isAdmin' => true, 'expiresAt' => time() + 3600,
    ]);
    return 'Session ready';
});
$routes->get('/', 'Home::index');
$routes->get('deploy/(:segment)', 'Home::install/$1');
$routes->get('app-id', static fn () => (new ApplicationModel())->catalog()[0]['id']);
