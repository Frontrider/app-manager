<?php

$groups = explode(',', $_SERVER['HTTP_REMOTE_GROUPS'] ?? '');
if (empty($_SERVER['HTTP_REMOTE_USER']) || ! in_array('lldap_admin', array_map('trim', $groups), true)) {
    http_response_code(403);
    exit('Forbidden');
}

$use_auth = false;
$auth_users = [];
$root_path = '/data';
$root_url = '';
$online_viewer = false;
$max_upload_size_bytes = 1024 * 1024 * 1024;
$edit_files = true;
