<?php

namespace App\Libraries;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class AppStore
{
    private string $appsDirectory;
    private string $dockerBinary;
    private string $lockFile;

    public function __construct()
    {
        $directory = realpath(getenv('APPS_DIR') ?: ROOTPATH . 'apps');

        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('The apps directory is unavailable.');
        }

        $this->appsDirectory = $directory;
        $this->dockerBinary  = getenv('DOCKER_BIN') ?: 'docker';
        $this->lockFile      = ROOTPATH . 'writable/cache/app-store.lock';
    }

    /**
     * @return list<array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string}>
     */
    public function catalog(): array
    {
        $apps = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->appsDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            if (! in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                continue;
            }

            $path = realpath($file->getPathname());
            if ($path === false || ! str_starts_with($path, $this->appsDirectory . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relativePath = substr($path, strlen($this->appsDirectory) + 1);
            $id           = hash('sha256', $relativePath);
            $name         = pathinfo($file->getFilename(), PATHINFO_FILENAME);

            if (in_array(strtolower($name), ['compose', 'docker-compose'], true)) {
                $name = basename(dirname($relativePath));
            }

            $metadata = $this->metadata(dirname($path));
            $apps[] = [
                'id'      => $id,
                'name'    => ucwords(str_replace(['-', '_'], ' ', $name)),
                'path'    => $relativePath,
                'file'    => $path,
                'project' => 'appstore-' . substr($id, 0, 16),
                'website' => $metadata['website'],
                'caddyPath' => $metadata['caddyPath'],
            ];
        }

        usort($apps, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $apps;
    }

    /**
     * @param list<array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string}> $apps
     * @return array<string, bool>
     */
    public function runningApps(array $apps): array
    {
        $running = array_fill_keys(array_column($apps, 'id'), false);
        $ids     = $this->run([$this->dockerBinary, 'ps', '--quiet']);
        $ids     = array_values(array_filter(array_map('trim', explode("\n", $ids))));

        if ($ids === []) {
            return $running;
        }

        $output = $this->run(array_merge(
            [$this->dockerBinary, 'inspect', '--format', '{{json .Config.Labels}}'],
            $ids,
        ));
        $labelsByContainer = array_map(
            static fn (string $labels): array => json_decode($labels, true, 512, JSON_THROW_ON_ERROR) ?? [],
            array_filter(array_map('trim', explode("\n", trim($output)))),
        );

        foreach ($apps as $app) {
            foreach ($labelsByContainer as $labels) {
                if (($labels['com.docker.compose.project'] ?? null) === $app['project']) {
                    $running[$app['id']] = true;
                    break;
                }

                $configFiles = explode(',', $labels['com.docker.compose.config_files'] ?? '');
                foreach ($configFiles as $configFile) {
                    if ($this->samePath(trim($configFile), $app['file'])) {
                        $running[$app['id']] = true;
                        break 2;
                    }
                }
            }
        }

        return $running;
    }

    /**
     * @return array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string}
     */
    public function find(string $id): array
    {
        foreach ($this->catalog() as $app) {
            if (hash_equals($app['id'], $id)) {
                return $app;
            }
        }

        throw new RuntimeException('That app is not available in the catalog.');
    }

    public function category(array $app): string
    {
        $singleton = $this->singletonCategory($app);
        if ($singleton !== null) {
            return $singleton;
        }

        $parts = explode('/', str_replace('\\', '/', $app['path']));

        $folders = array_slice($parts, 0, -2);
        if (($folders[0] ?? null) === 'standard') {
            array_shift($folders);
        }

        return $folders === [] ? 'General' : implode(' / ', $folders);
    }

    private function singletonCategory(array $app): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $app['path']));

        return $parts[0] === 'singleton' && count($parts) >= 3 ? $parts[1] : null;
    }

    /**
     * @return array<string, string> App IDs mapped to the running app blocking installation.
     */
    public function installationBlocks(array $apps, array $running): array
    {
        $occupied = [];
        foreach ($apps as $app) {
            $category = $this->singletonCategory($app);
            if ($category !== null && $running[$app['id']]) {
                $occupied[$category] = $app['name'];
            }
        }

        $blocked = [];
        foreach ($apps as $app) {
            $category = $this->singletonCategory($app);
            if ($category !== null && ! $running[$app['id']] && isset($occupied[$category])) {
                $blocked[$app['id']] = $occupied[$category];
            }
        }

        return $blocked;
    }

    /**
     * @return array{website: ?string, caddyPath: ?string}
     */
    private function metadata(string $directory): array
    {
        $file = $directory . '/metadata.properties';
        if (! file_exists($file)) {
            return ['website' => null, 'caddyPath' => null];
        }

        $path = realpath($file);
        if ($path === false || ! str_starts_with($path, $this->appsDirectory . DIRECTORY_SEPARATOR)
            || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('App metadata must be a readable file within the apps directory.');
        }

        $properties = parse_ini_file($path, false, INI_SCANNER_RAW);
        if ($properties === false) {
            throw new RuntimeException('Could not parse app metadata: ' . $file);
        }

        $website = $properties['website'] ?? null;
        $caddyPath = $properties['caddy_path'] ?? null;
        if ($website !== null && (! is_string($website)
            || filter_var($website, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($website, PHP_URL_SCHEME), ['https', 'http'], true))) {
            throw new RuntimeException('App metadata website must be an HTTP or HTTPS URL: ' . $file);
        }

        if ($caddyPath !== null && (! is_string($caddyPath)
            || ! str_starts_with($caddyPath, '/') || str_starts_with($caddyPath, '//')
            || str_contains($caddyPath, '\\') || preg_match('/[\x00-\x20\x7f]/', $caddyPath)
            || parse_url($caddyPath, PHP_URL_PATH) !== $caddyPath)) {
            throw new RuntimeException('App metadata caddy_path must be a same-domain absolute path: ' . $file);
        }

        return ['website' => $website, 'caddyPath' => $caddyPath];
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string} $app
     */
    public function headerFile(array $app): ?string
    {
        $candidate = dirname($app['file']) . '/header.png';

        if (! file_exists($candidate)) {
            return null;
        }

        $path = realpath($candidate);
        if ($path === false || ! str_starts_with($path, $this->appsDirectory . DIRECTORY_SEPARATOR)
            || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The app header must be a readable file within the apps directory.');
        }

        if ((new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'image/png') {
            throw new RuntimeException('The app header.png must be a PNG image.');
        }

        return $path;
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string} $app
     */
    public function install(array $app, ?array $environment = null): void
    {
        $this->withLock(function () use ($app, $environment): void {
            $apps = $this->catalog();
            $running = $this->runningApps($apps);
            if ($running[$app['id']]) {
                throw new RuntimeException($app['name'] . ' is already running and cannot be deployed again.');
            }

            $blocks = $this->installationBlocks($apps, $running);
            if (isset($blocks[$app['id']])) {
                throw new RuntimeException(
                    $blocks[$app['id']] . ' is already running in the ' . $this->category($app)
                    . ' category. Stop it before starting ' . $app['name'] . '.',
                );
            }

            if ($environment !== null) {
                $this->saveEnvironment($app, $environment);
            }
            $this->compose($app, 'up', '-d');
        });
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string} $app
     */
    public function uninstall(array $app): void
    {
        $this->withLock(fn () => $this->compose($app, 'stop'));
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string} $app
     */
    private function compose(array $app, string ...$arguments): void
    {
        $command = [
            $this->dockerBinary,
            'compose',
            '--project-name',
            $app['project'],
            '--file',
            $app['file'],
        ];
        $environment = $this->environmentTemplate($app);
        if ($environment !== null) {
            array_push($command, '--env-file', $environment);
        }

        $saved = $this->savedEnvironmentPath($app);
        if ($environment === null || ! is_file($saved)) {
            $this->run(array_merge($command, $arguments));
            return;
        }

        array_push($command, '--env-file', $saved);
        $services = array_filter(explode("\n", trim($this->run(array_merge($command, ['config', '--services'])))));
        if ($services === []) {
            throw new RuntimeException('The app Compose file has no services.');
        }
        $override = ['services' => []];
        foreach ($services as $service) {
            $override['services'][$service] = ['env_file' => [$saved]];
        }
        $file = tempnam(WRITEPATH . 'cache', 'app-env-');
        if ($file === false) {
            throw new RuntimeException('Could not create the app environment override.');
        }
        try {
            if (file_put_contents($file, json_encode($override, JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Could not write the app environment override.');
            }
            $this->run(array_merge($command, ['--file', $file], $arguments));
        } finally {
            if (! unlink($file)) {
                log_message('error', 'Could not remove app environment override: {file}', ['file' => $file]);
            }
        }
    }

    private function environmentTemplate(array $app): ?string
    {
        $file = dirname($app['file']) . '/env';
        if (! file_exists($file) && ! is_link($file)) {
            return null;
        }
        $resolved = realpath($file);
        if ($resolved === false || ! str_starts_with($resolved, $this->appsDirectory . DIRECTORY_SEPARATOR)
            || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new RuntimeException('The app env must be a readable file within the apps directory.');
        }

        return $resolved;
    }

    private function savedEnvironmentPath(array $app): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $app['id'])) {
            throw new RuntimeException('Invalid app ID for environment settings.');
        }

        return WRITEPATH . 'app-environments/' . $app['id'] . '.env';
    }

    /**
     * @return ?array<string, array{value: string, description: string}>
     */
    public function environmentFields(array $app): ?array
    {
        $template = $this->environmentTemplate($app);
        if ($template === null) {
            return null;
        }
        $fields = $this->readEnvironment($template);
        $saved = $this->savedEnvironmentPath($app);
        if (is_file($saved)) {
            foreach ($this->readEnvironment($saved) as $name => $field) {
                if (isset($fields[$name])) {
                    $fields[$name]['value'] = $field['value'];
                }
            }
        }

        return $fields;
    }

    private function readEnvironment(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Could not read app environment settings.');
        }
        $fields = [];
        $comments = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#')) {
                $comments[] = ltrim(substr($line, 1));
                continue;
            }
            if ($line === '') {
                $comments = [];
                continue;
            }
            if (! preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/D', $line, $match)) {
                throw new RuntimeException('App env must contain one NAME=value assignment per line.');
            }
            $value = trim($match[2]);
            if (str_starts_with($value, "'")) {
                if (! preg_match("/^'((?:\\\\'|[^'])*)'\\s*(?:#.*)?$/D", $value, $quoted)) {
                    throw new RuntimeException('Invalid single-quoted app environment value.');
                }
                $value = str_replace("\\'", "'", $quoted[1]);
            } elseif (str_starts_with($value, '"')) {
                if (! preg_match('/^"((?:\\\\.|[^"\\\\])*)"\\s*(?:#.*)?$/D', $value, $quoted)) {
                    throw new RuntimeException('Invalid double-quoted app environment value.');
                }
                $value = strtr($quoted[1], ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '$$' => '$']);
            } else {
                $value = rtrim(explode(' #', $value, 2)[0]);
            }
            $fields[$match[1]] = ['value' => $value, 'description' => implode("\n", $comments)];
            $comments = [];
        }

        return $fields;
    }

    private function saveEnvironment(array $app, array $values): void
    {
        $fields = $this->environmentFields($app);
        if ($fields === null || array_diff_key($values, $fields) !== [] || array_diff_key($fields, $values) !== []) {
            throw new RuntimeException('Submit exactly the variables defined in the app env file.');
        }
        $contents = '';
        foreach ($values as $name => $value) {
            if (! is_string($value) || strpbrk($value, "\r\n\0") !== false || strlen($value) > 16384) {
                throw new RuntimeException('Environment values must be single-line text of at most 16384 bytes.');
            }
            $contents .= $name . '="' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '$$']) . "\"\n";
        }
        $path = $this->savedEnvironmentPath($app);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the app environment settings directory.');
        }
        $temporary = tempnam($directory, 'env-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create app environment settings.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not save app environment settings.');
            }
        } finally {
            if (is_file($temporary) && ! unlink($temporary)) {
                log_message('error', 'Could not remove temporary app environment settings.');
            }
        }
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): string
    {
        $stdoutFile = tmpfile();
        if ($stdoutFile === false) {
            throw new RuntimeException('Could not create Docker output file.');
        }
        $stderrFile = tmpfile();
        if ($stderrFile === false) {
            fclose($stdoutFile);
            throw new RuntimeException('Could not create Docker error file.');
        }

        try {
            $pipes = [];
            // File-backed output cannot fill a pipe and deadlock a verbose image pull.
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => $stdoutFile,
                2 => $stderrFile,
            ], $pipes, ROOTPATH);

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the Docker CLI.');
            }
            fclose($pipes[0]);
            $status = proc_close($process);
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
        }

        if ($status !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException($detail === '' ? 'Docker command failed.' : $detail);
        }

        return $stdout;
    }

    private function samePath(string $first, string $second): bool
    {
        $resolved = realpath($first);

        return $resolved !== false && $resolved === $second;
    }

    private function withLock(callable $operation): void
    {
        $handle = fopen($this->lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException('Could not open the app-store operation lock.');
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another app is being started or stopped. Try again when it finishes.');
            }

            $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
