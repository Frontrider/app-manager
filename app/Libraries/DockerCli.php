<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Small wrapper around the Docker CLI.
 *
 * Output is written to temporary files instead of pipes so verbose image pulls
 * cannot fill a pipe and deadlock the PHP worker.
 */
class DockerCli
{
    private string $binary;

    public function __construct(?string $binary = null)
    {
        $this->binary = $binary ?? (getenv('DOCKER_BIN') ?: 'docker');
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * @param list<string> $command
     */
    public function run(
        array $command,
        ?array $environment = null,
        bool $mergeOutput = false,
        ?string $logFile = null,
    ): string {
        $logHandle = null;
        $logErrorHandle = null;
        if ($logFile !== null) {
            $directory = dirname($logFile);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Could not create the deployment logs directory.');
            }
            $logHandle = fopen($logFile, 'ab');
            $logErrorHandle = fopen($logFile, 'ab');
            if ($logHandle === false || $logErrorHandle === false) {
                if (is_resource($logHandle)) {
                    fclose($logHandle);
                }
                if (is_resource($logErrorHandle)) {
                    fclose($logErrorHandle);
                }
                throw new RuntimeException('Could not open the deployment log.');
            }
            fwrite($logHandle, '$ ' . implode(' ', $command) . "\n");
        }

        $stdoutFile = tmpfile();
        if ($stdoutFile === false) {
            if (is_resource($logHandle)) {
                fclose($logHandle);
            }
            if (is_resource($logErrorHandle)) {
                fclose($logErrorHandle);
            }
            throw new RuntimeException('Could not create Docker output file.');
        }
        $stderrFile = tmpfile();
        if ($stderrFile === false) {
            fclose($stdoutFile);
            if (is_resource($logHandle)) {
                fclose($logHandle);
            }
            if (is_resource($logErrorHandle)) {
                fclose($logErrorHandle);
            }
            throw new RuntimeException('Could not create Docker error file.');
        }

        try {
            $pipes = [];
            // File-backed output cannot fill a pipe and deadlock a verbose image pull.
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => $logHandle ?? $stdoutFile,
                2 => $logHandle !== null ? $logErrorHandle : ($mergeOutput ? ['redirect', 1] : $stderrFile),
            ], $pipes, ROOTPATH, $environment === null ? null : array_replace(getenv(), $environment));

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the Docker CLI.');
            }
            fclose($pipes[0]);
            $status = proc_close($process);
            if (is_resource($logHandle)) {
                fflush($logHandle);
            }
            if (is_resource($logErrorHandle)) {
                fflush($logErrorHandle);
            }
            rewind($stdoutFile);
            rewind($stderrFile);
            $stdout = stream_get_contents($stdoutFile);
            $stderr = stream_get_contents($stderrFile);
            if ($stdout === false || $stderr === false) {
                throw new RuntimeException('Could not read Docker command output.');
            }
        } finally {
            fclose($stdoutFile);
            fclose($stderrFile);
            if (is_resource($logHandle)) {
                fclose($logHandle);
            }
            if (is_resource($logErrorHandle)) {
                fclose($logErrorHandle);
            }
        }

        if ($status !== 0) {
            $detail = '';
            if ($logFile !== null) {
                try {
                    $detail = trim($this->readTail($logFile, 16384));
                } catch (RuntimeException $exception) {
                    log_message('error', 'Could not read deployment log: {message}', [
                        'message' => $exception->getMessage(),
                    ]);
                }
            } else {
                $detail = trim($stderr !== '' ? $stderr : $stdout);
            }
            throw new RuntimeException($detail === '' ? 'Docker command failed.' : $detail);
        }

        return $logFile === null ? $stdout : '';
    }

    /**
     * Read the last part of a log file without loading the whole file.
     */
    public function readTail(string $path, int $maxBytes = 262144): string
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Could not read the deployment log size.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not read the deployment log.');
        }
        try {
            $offset = max(0, $size - $maxBytes);
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new RuntimeException('Could not seek in the deployment log.');
            }
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new RuntimeException('Could not read the deployment log contents.');
            }
            if ($offset > 0) {
                $newline = strpos($contents, "\n");
                if ($newline !== false) {
                    $contents = substr($contents, $newline + 1);
                }
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }
}
