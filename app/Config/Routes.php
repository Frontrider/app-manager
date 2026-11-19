<?php
// do not touch
use App\Controllers\UserSettingsController;
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('sidebar', 'Sidebar::index');
$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::authenticate');
$routes->post('logout', 'Auth::logout', ['filter' => 'ldapSession']);
$routes->get('/', 'Home::index', ['filter' => 'ldapSession']);
$routes->get('activity', 'Home::activity', ['filter' => 'ldapSession']);
$routes->post('settings/dark_mode', 'UserSettingsController::set_dark_mode', ['filter' => 'ldapSession']);
$routes->get('activity/output', 'ApplicationController::activityOutput', ['filter' => 'ldapSession']);
$routes->get('host/stats', 'HostStatsController::index', ['filter' => 'ldapSession']);
$routes->get('category/(:any)', 'Home::index/$1', ['filter' => 'ldapSession']);
$routes->get('catalog/add', 'CatalogController::index', ['filter' => 'ldapSession:admin']);
$routes->post('catalog/upload', 'CatalogController::upload', ['filter' => 'ldapSession:admin']);
$routes->post('catalog/create', 'CatalogController::create', ['filter' => 'ldapSession:admin']);
$routes->post('catalog/search', 'Home::index', ['filter' => 'ldapSession']);
$routes->get('apps/(:segment)/logo', 'Home::logo/$1', ['filter' => 'ldapSession']);
$routes->get('apps/(:segment)/configure', 'ApplicationController::configure/$1', ['filter' => 'ldapSession:admin']);
$routes->get('apps/(:segment)/logs', 'LoggingController::logs/$1', ['filter' => 'ldapSession:admin']);
$routes->get('apps/(:segment)/logs/output', 'LoggingController::logOutput/$1', ['filter' => 'ldapSession:admin']);

$routes->post('apps/(:segment)/install', 'ApplicationController::install/$1', ['filter' => 'ldapSession:admin']);
$routes->post('apps/(:segment)/uninstall', 'ApplicationController::uninstall/$1', ['filter' => 'ldapSession:admin']);
$routes->get('apps/(:segment)/delete-data', 'ApplicationController::confirmDeleteData/$1', ['filter' => 'ldapSession:admin']);
$routes->post('apps/(:segment)/delete-data', 'ApplicationController::deleteData/$1', ['filter' => 'ldapSession:admin']);
$routes->post('apps/(:segment)/refresh', 'ApplicationController::refresh/$1', ['filter' => 'ldapSession:admin']);
$routes->post('catalog/stop-all', 'ApplicationController::stopAll', ['filter' => 'ldapSession:admin']);
