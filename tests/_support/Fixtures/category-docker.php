#!/usr/bin/env php
<?php

if (($argv[1] ?? '') === 'ps') {
    if (getenv('CATEGORY_TEST_PROJECT')) {
        echo "category-container\n";
    }
    exit(0);
}
if (($argv[1] ?? '') === 'inspect') {
    echo json_encode(['com.docker.compose.project' => getenv('CATEGORY_TEST_PROJECT')]), "\n";
    exit(0);
}
if (($argv[1] ?? '') === 'compose') {
    if (in_array('--services', $argv, true)) {
        echo "category-service\n";
        exit(0);
    }
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
