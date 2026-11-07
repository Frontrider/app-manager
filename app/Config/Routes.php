<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('sidebar', 'Home::sidebar');
$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::authenticate');
$routes->get('/', 'Home::index', ['filter' => 'ldapSession']);
$routes->get('category/(:any)', 'Home::index/$1', ['filter' => 'ldapSession']);
$routes->get('apps/(:segment)/logo', 'Home::logo/$1', ['filter' => 'ldapSession']);
$routes->post('logout', 'Auth::logout', ['filter' => 'ldapSession']);
$routes->post('apps/(:segment)/install', 'Home::install/$1', ['filter' => 'ldapSession:admin']);
$routes->post('apps/(:segment)/uninstall', 'Home::uninstall/$1', ['filter' => 'ldapSession:admin']);
