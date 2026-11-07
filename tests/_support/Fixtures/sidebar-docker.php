#!/usr/bin/env php
<?php

if (($argv[1] ?? '') === 'ps') {
    if (getenv('SIDEBAR_TEST_PROJECT')) {
        echo "sidebar-test-container\n";
    }
    exit(0);
}

if (($argv[1] ?? '') === 'inspect') {
    echo json_encode([
        'com.docker.compose.project' => getenv('SIDEBAR_TEST_PROJECT'),
    ], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

fwrite(STDERR, "Unexpected Docker command in sidebar test.\n");
exit(1);
