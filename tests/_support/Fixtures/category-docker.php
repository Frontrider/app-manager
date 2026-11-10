#!/usr/bin/env php
<?php

if (getenv('CATEGORY_TEST_ACTIVITY') !== false) {
    if (($argv[1] ?? '') === 'ps') {
        echo "activity-container\n";
        exit(0);
    }
    if (($argv[1] ?? '') === 'inspect') {
        if (getenv('CATEGORY_TEST_ACTIVITY') === 'error') {
            fwrite(STDERR, "Docker unavailable\n");
            exit(1);
        }
        foreach (json_decode(getenv('CATEGORY_TEST_ACTIVITY'), true, 512, JSON_THROW_ON_ERROR) as $container) {
            echo json_encode($container, JSON_THROW_ON_ERROR), "\n";
        }
        exit(0);
    }
}
if (getenv('CATEGORY_TEST_OPERATION') && in_array($argv[1] ?? '', ['compose', 'stop'], true)) {
    $states = [];
    foreach (['starting', 'stopping', 'deleting'] as $state) {
        $path = dirname(__DIR__, 3) . '/writable/app-operations/' . getenv('CATEGORY_TEST_OPERATION') . '.' . $state;
        if (is_file($path)) {
            $handle = fopen($path, 'r');
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                $states[] = ucfirst($state);
            }
            fclose($handle);
        }
    }
    file_put_contents(getenv('CATEGORY_TEST_CALLS') . '.operations', json_encode($states) . "\n", FILE_APPEND);
}

if (($argv[1] ?? '') === 'ps') {
    if (in_array('--quiet', $argv, true) && in_array('--all', $argv, true)) {
        file_put_contents(getenv('CATEGORY_TEST_CALLS'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
        if (in_array('label=com.docker.compose.project=' . getenv('CATEGORY_TEST_PROJECT'), $argv, true)) {
            echo "category-container\n";
        }
        exit(0);
    }
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
if (($argv[1] ?? '') === 'stop') {
    file_put_contents(getenv('CATEGORY_TEST_CALLS'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
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
