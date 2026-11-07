<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/files/' && $path !== '/files/index.php') {
    http_response_code(404);
    exit('Not found');
}

$_SERVER['PHP_SELF'] = '/files/';
$_SERVER['SCRIPT_NAME'] = '/files/';
require __DIR__ . '/index.php';
