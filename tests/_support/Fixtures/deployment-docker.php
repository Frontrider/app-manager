#!/usr/bin/env php
<?php

if (($argv[1] ?? '') === 'ps') {
    exit(0);
}
if (($argv[1] ?? '') === 'compose') {
    file_put_contents('/tmp/deployment-started', 'started');
    fwrite(STDERR, str_repeat("Pulling image...\n", 32768));
    sleep(5);
    exit(0);
}
fwrite(STDERR, "Unexpected Docker command in deployment smoke test.\n");
exit(1);
