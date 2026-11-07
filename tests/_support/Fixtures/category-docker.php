#!/usr/bin/env php
<?php

if (($argv[1] ?? '') === 'ps') {
    if (in_array('--format', $argv, true)) {
        file_put_contents(getenv('CATEGORY_TEST_CALLS'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
        if (getenv('CATEGORY_TEST_PROJECT')) {
            echo "category-container\tlocalhost-first-server-1\n";
            echo "category-init\tlocalhost-first-init-1\n";
        }
        exit(0);
    }
    if (getenv('CATEGORY_TEST_PROJECT')) {
        echo "category-container\n";
    }
    if (($argv[1] ?? '') === 'logs') {
        file_put_contents(getenv('CATEGORY_TEST_CALLS'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
        if (getenv('CATEGORY_TEST_VERBOSE')) {
            fwrite(STDERR, "simulated log failure\n");
            exit(1);
        }
        echo "2026-11-07T17:00:00Z server ready <script>alert(1)</script>\n";
        fwrite(STDERR, "2026-11-07T17:00:01Z server warning\n");
        exit(0);
    }
    exit(0);
}
if (($argv[1] ?? '') === 'inspect') {
    echo json_encode(['com.docker.compose.project' => getenv('CATEGORY_TEST_PROJECT')]), "\n";
    exit(0);
}
if (($argv[1] ?? '') === 'compose') {
    if (getenv('CATEGORY_TEST_SESSION_LOCK') && file_exists(getenv('CATEGORY_TEST_SESSION_LOCK'))) {
        fwrite(STDERR, "Session is still locked during deployment.\n");
        exit(1);
    }
    if (getenv('CATEGORY_TEST_VERBOSE')) {
        fwrite(STDERR, str_repeat('image pull progress ', 16384) . "\nsimulated failure\n");
        exit(1);
    }
    file_put_contents(getenv('CATEGORY_TEST_CALLS'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
    exit(0);
}
fwrite(STDERR, "Unexpected Docker command in category test.\n");
exit(1);
