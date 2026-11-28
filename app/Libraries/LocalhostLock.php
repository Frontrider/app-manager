<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Non-blocking process-wide lock shared by catalog imports and deployments.
 */
class LocalhostLock
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? ROOTPATH . 'writable/cache/localhost.lock';
    }

    public function run(callable $operation): mixed
    {
        $handle = fopen($this->path, 'c');

        if ($handle === false) {
            throw new RuntimeException('Could not open the localhost operation lock.');
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another app is being started or stopped. Try again when it finishes.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
