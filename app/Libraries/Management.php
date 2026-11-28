<?php

namespace App\Libraries;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Catalog administration.
 *
 * Management writes new catalog entries from ZIP uploads and pasted Compose
 * files. Deployment owns the generated logs for those applications.
 */
class Management
{
    private Catalog $catalog;
    private LocalhostLock $lock;

    public function __construct(?Catalog $catalog = null, ?LocalhostLock $lock = null)
    {
        $this->catalog = $catalog ?? new Catalog();
        $this->lock = $lock ?? new LocalhostLock();
    }

    public function importCatalog(string $archive): int
    {
        return $this->lock->run(function () use ($archive): int {
            if (! class_exists(\ZipArchive::class)) {
                throw new RuntimeException('ZIP uploads require the PHP zip extension.');
            }
            $size = filesize($archive);
            if ($size === false || $size > 50 * 1024 * 1024) {
                throw new RuntimeException('Catalog ZIP files must be at most 50 MiB.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($archive, \ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('The upload is not a valid ZIP archive.');
            }
            $staging = WRITEPATH . 'cache/catalog-' . bin2hex(random_bytes(16));
            try {
                if ($zip->numFiles < 1 || $zip->numFiles > 2000) {
                    throw new RuntimeException('A catalog ZIP must contain between 1 and 2000 entries.');
                }
                $entries = [];
                $total = 0;
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entry = $zip->statIndex($index);
                    if ($entry === false) {
                        throw new RuntimeException('Could not inspect a ZIP entry.');
                    }
                    $name = $entry['name'];
                    if ($name === 'apps/' || $name === 'apps') {
                        continue;
                    }
                    if (str_starts_with($name, 'apps/')) {
                        $name = substr($name, 5);
                    }
                    $path = rtrim($name, '/');
                    $parts = explode('/', $path);
                    if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)
                        || array_intersect($parts, ['', '.', '..']) !== []
                        || in_array($parts[0], ['standard', 'singleton'], true)) {
                        throw new RuntimeException('ZIP paths must use category/app folders without unsafe paths.');
                    }
                    if (isset($entries[$path])) {
                        throw new RuntimeException('The ZIP contains a duplicate path: ' . $path);
                    }
                    $directory = str_ends_with($name, '/');
                    if (! $directory && count($parts) < 3) {
                        throw new RuntimeException('ZIP files must be inside a category/app folder.');
                    }
                    $opsys = 0;
                    $attributes = 0;
                    if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                        throw new RuntimeException('Could not inspect ZIP file attributes.');
                    }
                    $mode = $opsys === \ZipArchive::OPSYS_UNIX ? (($attributes >> 16) & 0xffff) : 0;
                    $type = $mode & 0170000;
                    if (! in_array($type, [0, 0100000, 0040000], true)
                        || ($type === 0040000 && ! $directory)
                        || ($type === 0100000 && $directory)
                        || ($entry['encryption_method'] ?? 0) !== 0) {
                        throw new RuntimeException('ZIP entries must be unencrypted regular files or directories, not links.');
                    }
                    $total += $entry['size'];
                    if ($total > 200 * 1024 * 1024) {
                        throw new RuntimeException('The extracted catalog must be at most 200 MiB.');
                    }
                    $entries[$path] = ['index' => $index, 'size' => $entry['size'], 'directory' => $directory, 'mode' => $mode];
                }
                foreach ($entries as $path => $entry) {
                    $parent = dirname($path);
                    while ($parent !== '.') {
                        if (isset($entries[$parent]) && ! $entries[$parent]['directory']) {
                            throw new RuntimeException('A ZIP file conflicts with an extracted directory.');
                        }
                        $parent = dirname($parent);
                    }
                }
                if (! mkdir($staging, 0700)) {
                    throw new RuntimeException('Could not create catalog upload staging.');
                }
                foreach ($entries as $path => $entry) {
                    $destination = $staging . '/' . $path;
                    $directory = $entry['directory'] ? $destination : dirname($destination);
                    if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
                        throw new RuntimeException('Could not create uploaded catalog directories.');
                    }
                    if ($entry['directory']) {
                        continue;
                    }
                    $input = $zip->getStreamIndex($entry['index']);
                    $output = fopen($destination, 'xb');
                    if ($input === false || $output === false) {
                        if (is_resource($input)) {
                            fclose($input);
                        }
                        if (is_resource($output)) {
                            fclose($output);
                        }
                        throw new RuntimeException('Could not extract a catalog file.');
                    }
                    try {
                        $written = stream_copy_to_stream($input, $output, $entry['size'] + 1);
                        if ($written !== $entry['size']) {
                            throw new RuntimeException('A ZIP entry has an invalid extracted size.');
                        }
                    } finally {
                        fclose($input);
                        fclose($output);
                    }
                    if (! chmod($destination, ($entry['mode'] & 0111) ? 0755 : 0644)) {
                        throw new RuntimeException('Could not set catalog file permissions.');
                    }
                }
                $existing = array_column($this->catalog->catalog(), null, 'path');
                $projectNames = [];
                foreach ($existing as $app) {
                    $projectNames[$this->catalog->projectSlug($app['name'])] = true;
                    $projectNames[$this->catalog->projectSlug(basename(dirname($app['path'])))] = true;
                }
                $count = 0;
                foreach ($entries as $path => $entry) {
                    if ($entry['directory']
                        || ! in_array(basename($path), ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'], true)) {
                        continue;
                    }
                    if (count(explode('/', $path)) < 3 || isset($existing[$path])) {
                        throw new RuntimeException('Uploaded apps need a category/app folder and must not replace existing catalog items.');
                    }
                    $projectName = $this->catalog->projectSlug(basename(dirname($path)));
                    if ($projectName === '' || isset($projectNames[$projectName])) {
                        throw new RuntimeException('Uploaded app names must be unique: ' . basename(dirname($path)));
                    }
                    $projectNames[$projectName] = true;
                    $this->catalog->metadata(dirname($staging . '/' . $path), $staging);
                    $env = dirname($staging . '/' . $path) . '/env';
                    if (is_file($env)) {
                        $this->catalog->readEnvironment($env);
                    }
                    $header = dirname($staging . '/' . $path) . '/header.png';
                    if (is_file($header) && (new \finfo(FILEINFO_MIME_TYPE))->file($header) !== 'image/png') {
                        throw new RuntimeException('Uploaded header.png must be a PNG image.');
                    }
                    $count++;
                }
                if ($count === 0) {
                    throw new RuntimeException('The ZIP has no catalog items. Include category/app/compose.yaml.');
                }
                $catalog = WRITEPATH . 'catalog';
                if (! is_dir($catalog) && ! mkdir($catalog, 0755)) {
                    throw new RuntimeException('Could not create uploaded catalog storage.');
                }
                if (! chmod($staging, 0755) || ! rename($staging, $catalog . '/' . bin2hex(random_bytes(16)))) {
                    throw new RuntimeException('Could not save the uploaded catalog.');
                }

                return $count;
            } finally {
                $zip->close();
                if (is_dir($staging)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($staging, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST,
                    );
                    foreach ($files as $file) {
                        $removed = $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                        if (! $removed) {
                            log_message('error', 'Could not remove staged catalog entry: {path}', ['path' => $file->getPathname()]);
                        }
                    }
                    if (! rmdir($staging)) {
                        log_message('error', 'Could not remove catalog staging directory.');
                    }
                }
            }
        });
    }

    public function importCompose(string $name, string $subpath, string $compose): string
    {
        $name = trim($name);
        $subpath = trim($subpath);
        if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new RuntimeException('Enter an application name of at most 120 characters.');
        }
        if ($subpath === '') {
            throw new RuntimeException('Enter the application subpath.');
        }
        if (trim($compose) === '' || strlen($compose) > 1024 * 1024) {
            throw new RuntimeException('Paste a Compose file of at most 1 MiB.');
        }
        $slug = $this->catalog->projectSlug($name);
        if ($slug === '') {
            throw new RuntimeException('The application name must contain letters or numbers.');
        }

        return $this->lock->run(function () use ($name, $subpath, $compose, $slug): string {
            $relative = 'other/' . $slug . '/compose.yaml';
            $apps = $this->catalog->catalog();
            $existing = array_column($apps, null, 'path');
            if (isset($existing[$relative])) {
                throw new RuntimeException('An application named ' . $name . ' already exists.');
            }
            foreach ($apps as $app) {
                if ($this->catalog->projectSlug($app['name']) === $slug
                    || $this->catalog->projectSlug(basename(dirname($app['path']))) === $slug) {
                    throw new RuntimeException('An application named ' . $name . ' already exists.');
                }
            }

            $staging = WRITEPATH . 'cache/catalog-' . bin2hex(random_bytes(16));
            $directory = $staging . '/other/' . $slug;
            if (! mkdir($directory, 0755, true)) {
                throw new RuntimeException('Could not create the application staging directory.');
            }

            try {
                $normalizedSubpath = $this->catalog->normalizeCaddyPath($subpath);
                if ($normalizedSubpath === null) {
                    throw new RuntimeException('Enter the application subpath.');
                }
                $subpath = $normalizedSubpath;

                $composeFile = $directory . '/compose.yaml';
                if (file_put_contents($composeFile, $compose) !== strlen($compose)
                    || ! chmod($composeFile, 0644)) {
                    throw new RuntimeException('Could not write the Compose file.');
                }

                try {
                    $contents = json_encode(
                        ['name' => $name, 'caddy_path' => $subpath],
                        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ) . "\n";
                } catch (\JsonException $exception) {
                    throw new RuntimeException('The application metadata contains invalid text.', 0, $exception);
                }
                $metadataFile = $directory . '/metadata.json';
                if (file_put_contents($metadataFile, $contents) !== strlen($contents)
                    || ! chmod($metadataFile, 0644)) {
                    throw new RuntimeException('Could not write the application metadata.');
                }

                $this->catalog->metadata($directory, $staging);

                $catalog = WRITEPATH . 'catalog';
                if (! is_dir($catalog) && ! mkdir($catalog, 0755)) {
                    throw new RuntimeException('Could not create uploaded catalog storage.');
                }
                if (! chmod($staging, 0755)
                    || ! rename($staging, $catalog . '/' . bin2hex(random_bytes(16)))) {
                    throw new RuntimeException('Could not save the application.');
                }

                return $relative;
            } finally {
                if (is_dir($staging)) {
                    $this->removeDirectory($staging);
                }
            }
        });
    }

    private function removeDirectory(string $directory): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $removed = $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            if (! $removed) {
                log_message('error', 'Could not remove staged catalog entry: {path}', ['path' => $file->getPathname()]);
            }
        }
        if (! rmdir($directory)) {
            log_message('error', 'Could not remove catalog staging directory.');
        }
    }
}
