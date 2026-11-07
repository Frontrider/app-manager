<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('sidebar', 'Sidebar::index');
$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::authenticate');
$routes->get('/', 'Home::index', ['filter' => 'ldapSession']);
$routes->get('category/(:any)', 'Home::index/$1', ['filter' => 'ldapSession']);
$routes->post('catalog/upload', 'Home::uploadCatalog', ['filter' => 'ldapSession:admin']);
$routes->post('catalog/search', 'Home::index', ['filter' => 'ldapSession']);
$routes->get('apps/(:segment)/logo', 'Home::logo/$1', ['filter' => 'ldapSession']);
$routes->get('apps/(:segment)/logs', 'Home::logs/$1', ['filter' => 'ldapSession:admin']);
$routes->get('apps/(:segment)/logs/output', 'Home::logOutput/$1', ['filter' => 'ldapSession:admin']);
$routes->post('logout', 'Auth::logout', ['filter' => 'ldapSession']);
$routes->post('apps/(:segment)/install', 'Home::install/$1', ['filter' => 'ldapSession:admin']);
$routes->post('apps/(:segment)/uninstall', 'Home::uninstall/$1', ['filter' => 'ldapSession:admin']);
