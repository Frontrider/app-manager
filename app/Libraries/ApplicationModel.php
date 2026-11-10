<?php

namespace App\Libraries;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class ApplicationModel
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
        $this->lockFile      = ROOTPATH . 'writable/cache/localhost.lock';
    }

    /**
     * @return list<array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string}>
     */
    public function catalog(): array
    {
        $apps = [];
        foreach ($this->catalogDirectories() as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                    continue;
                }
                if (! in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                    continue;
                }
                if ($directory !== $this->appsDirectory
                    && ! in_array($file->getFilename(), ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'], true)) {
                    continue;
                }
                $path = realpath($file->getPathname());
                if ($path === false || ! str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $relativePath = substr($path, strlen($directory) + 1);
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
                    'project' => 'localhost-' . trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name)), '-'),
                    'website' => $metadata['website'],
                    'caddyPath' => $metadata['caddyPath'],
                    'description' => $this->description(dirname($path)),
                ];
            }
        }

        $projectCounts = array_count_values(array_column($apps, 'project'));
        foreach ($apps as &$app) {
            if ($app['project'] === 'localhost-' || $projectCounts[$app['project']] > 1) {
                $app['project'] .= '-' . substr($app['id'], 0, 8);
            }
        }
        unset($app);
        usort($apps, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $apps;
    }

    private function catalogDirectories(): array
    {
        return array_merge([$this->appsDirectory], array_values(array_filter(
            glob(WRITEPATH . 'catalog/*', GLOB_ONLYDIR) ?: [],
            static fn (string $directory): bool => ! is_link($directory),
        )));
    }

    private function isCatalogPath(string $path): bool
    {
        foreach ($this->catalogDirectories() as $directory) {
            if (str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function description(string $directory): ?string
    {
        $file = $directory . '/readme.md';
        if (! file_exists($file) && ! is_link($file)) {
            return null;
        }
        $path = realpath($file);
        if ($path === false || ! $this->isCatalogPath($path)
            || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The app readme.md must be a readable file within the catalog.');
        }
        $description = file_get_contents($path);
        if ($description === false) {
            throw new RuntimeException('Could not read the app description.');
        }

        return trim($description);
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
            }
        }

        return $running;
    }

    /**
     * @return list<array{id: string, name: string, category: string, status: string, containers: list<array{name: string, status: string}>}>
     */
    public function applicationActivity(): array
    {
        $apps = $this->catalog();
        $ids = preg_split('/\s+/', trim($this->run([
            $this->dockerBinary, 'ps', '--all', '--quiet', '--filter', 'label=com.docker.compose.project',
        ])), -1, PREG_SPLIT_NO_EMPTY);
        $containers = [];
        if ($ids !== []) {
            $output = $this->run(array_merge([
                $this->dockerBinary, 'inspect', '--format',
                '{"name":{{json .Name}},"labels":{{json .Config.Labels}},"state":{{json .State}}}',
            ], $ids));
            foreach (array_filter(explode("\n", trim($output))) as $line) {
                $container = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $state = $container['state'];
                $status = match (true) {
                    $state['Status'] === 'exited' => null,
                    $state['Status'] === 'paused' => 'Paused',
                    $state['Status'] === 'removing' => 'Stopping',
                    $state['Status'] === 'dead' => 'Failed',
                    $state['Status'] === 'restarting', $state['Status'] === 'created',
                        $state['Status'] === 'running'
                        && ($state['Health']['Status'] ?? null) === 'starting' => 'Starting',
                    $state['Status'] === 'running'
                        && ($state['Health']['Status'] ?? null) === 'unhealthy' => 'Failed',
                    $state['Status'] === 'running' => 'Running',
                    default => null,
                };
                if ($status !== null) {
                    $containers[$container['labels']['com.docker.compose.project']][] = [
                        'name' => ltrim($container['name'], '/'), 'status' => $status,
                    ];
                }
            }
        }
        $activity = [];
        foreach ($apps as $app) {
            $services = $containers[$app['project']] ?? [];
            $status = null;
            foreach (['Stopping', 'Starting'] as $operation) {
                $path = $this->operationPath($app, $operation);
                if (! is_file($path)) {
                    continue;
                }
                $handle = fopen($path, 'r');
                if ($handle === false) {
                    throw new RuntimeException('Could not read the app operation state.');
                }
                try {
                    $locked = flock($handle, LOCK_SH | LOCK_NB, $wouldBlock);
                    if (! $locked && ! $wouldBlock) {
                        throw new RuntimeException('Could not check the app operation state.');
                    }
                    if (! $locked) {
                        $status = $operation;
                        break;
                    }
                } finally {
                    fclose($handle);
                }
            }
            if ($status === null && $services !== []) {
                foreach (['Stopping', 'Failed', 'Starting', 'Paused', 'Running'] as $candidate) {
                    if (in_array($candidate, array_column($services, 'status'), true)) {
                        $status = $candidate;
                        break;
                    }
                }
            }
            if ($status !== null) {
                $activity[] = [
                    'id' => $app['id'], 'name' => $app['name'], 'category' => $this->category($app),
                    'status' => $status, 'containers' => $services,
                ];
            }
        }

        return $activity;
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
    private function metadata(string $directory, ?string $stagingRoot = null): array
    {
        $file = $directory . '/metadata.properties';
        if (! file_exists($file)) {
            return ['website' => null, 'caddyPath' => null];
        }

        $path = realpath($file);
        if ($path === false || ! ($stagingRoot === null ? $this->isCatalogPath($path)
            : str_starts_with($path, $stagingRoot . DIRECTORY_SEPARATOR))
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
        if ($path === false || ! $this->isCatalogPath($path)
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
        $this->start($app, $environment, false);
    }

    public function refresh(array $app): void
    {
        $this->start($app, null, true);
    }

    private function start(array $app, ?array $environment, bool $refresh): void
    {
        $this->withLock(function () use ($app, $environment, $refresh): void {
            $apps = $this->catalog();
            $running = $this->runningApps($apps);
            if (! $refresh && $running[$app['id']]) {
                throw new RuntimeException($app['name'] . ' is already running and cannot be deployed again.');
            }

            $blocks = $this->installationBlocks($apps, $running);
            if (isset($blocks[$app['id']])) {
                throw new RuntimeException(
                    $blocks[$app['id']] . ' is already running in the ' . $this->category($app)
                    . ' category. Stop it before starting ' . $app['name'] . '.',
                );
            }

            $this->withAppOperation($app, 'Starting', function () use ($app, $environment, $refresh): void {
                try {
                    if ($environment !== null) {
                        $this->saveEnvironment($app, $environment);
                    }
                    if ($refresh) {
                        $this->compose($app, 'pull', '--ignore-buildable');
                        $this->compose($app, 'build', '--pull', '--no-cache');
                        $this->compose($app, 'up', '-d', '--force-recreate', '--always-recreate-deps', '--remove-orphans');
                    } else {
                        $this->compose($app, 'up', '-d');
                    }
                } catch (RuntimeException $exception) {
                    try {
                        $this->saveStartupFailure($app, $exception->getMessage());
                    } catch (RuntimeException $storageException) {
                        log_message('error', 'Could not save startup failure: {message}', [
                            'message' => $storageException->getMessage(),
                        ]);
                        throw new RuntimeException(
                            $exception->getMessage() . "\n" . $storageException->getMessage(), 0, $exception,
                        );
                    }
                    throw $exception;
                }
                $failure = $this->startupFailurePath($app);
                if (is_file($failure) && ! unlink($failure)) {
                    throw new RuntimeException('The app started, but its previous startup failure could not be cleared.');
                }
            });
        });
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string} $app
     */
    public function uninstall(array $app): void
    {
        $this->withLock(fn () => $this->withAppOperation(
            $app, 'Stopping', fn () => $this->compose($app, 'stop'),
        ));
    }

    public function stopAll(): void
    {
        $this->withLock(function (): void {
            $failures = [];
            foreach ($this->catalog() as $app) {
                try {
                    $output = $this->run([
                        $this->dockerBinary, 'ps', '--all', '--quiet', '--filter',
                        'label=com.docker.compose.project=' . $app['project'],
                    ]);
                    $ids = array_values(array_filter(array_map('trim', explode("\n", $output))));
                    if ($ids !== []) {
                        $this->withAppOperation(
                            $app, 'Stopping', fn () => $this->run(array_merge([$this->dockerBinary, 'stop'], $ids)),
                        );
                    }
                } catch (RuntimeException $exception) {
                    $failures[] = $app['name'] . ': ' . $exception->getMessage();
                }
            }
            if ($failures !== []) {
                throw new RuntimeException("Some apps could not be stopped:\n" . implode("\n", $failures));
            }
        });
    }

    public function logs(array $app): string
    {
        $failure = $this->startupFailurePath($app);
        $output = '';
        if (is_file($failure)) {
            $contents = file_get_contents($failure);
            if ($contents === false) {
                throw new RuntimeException('Could not read the saved startup failure.');
            }
            $output = $contents . "\n\n";
        }
        try {
            return $output . $this->containerLogs($app);
        } catch (RuntimeException $exception) {
            if ($output === '') {
                throw $exception;
            }
            log_message('error', 'Could not read container logs: {message}', ['message' => $exception->getMessage()]);
            return $output . 'Could not read container logs: ' . $exception->getMessage();
        }
    }

    private function startupFailurePath(array $app): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $app['id'])) {
            throw new RuntimeException('Invalid app ID for startup logs.');
        }

        return WRITEPATH . 'app-startup-failures/' . $app['id'] . '.log';
    }

    private function saveStartupFailure(array $app, string $message): void
    {
        $path = $this->startupFailurePath($app);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the startup logs directory.');
        }
        $temporary = tempnam($directory, 'startup-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create the startup failure log.');
        }
        $contents = 'Startup failed at ' . gmdate('Y-m-d\TH:i:s\Z') . "\n" . $message;
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not save the startup failure log.');
            }
        } finally {
            if (is_file($temporary) && ! unlink($temporary)) {
                log_message('error', 'Could not remove temporary startup failure log.');
            }
        }
    }

    private function containerLogs(array $app): string
    {
        $containers = trim($this->run([
            $this->dockerBinary, 'ps', '--all',
            '--filter', 'label=com.docker.compose.project=' . $app['project'],
            '--format', '{{.ID}}\t{{.Names}}',
        ]));
        if ($containers === '') {
            return 'No containers exist for this app yet.';
        }

        $output = [];
        foreach (explode("\n", $containers) as $container) {
            $fields = explode("\t", $container, 2);
            if (count($fields) !== 2 || $fields[0] === '' || $fields[1] === '') {
                throw new RuntimeException('Docker returned an invalid container listing.');
            }
            [$id, $name] = $fields;
            $logs = $this->run([
                $this->dockerBinary, 'logs', '--timestamps', '--tail', '100', $id,
            ], null, true);
            $output[] = $name . "\n" . ($logs === '' ? 'No logs available.' : rtrim($logs));
        }

        return implode("\n\n", $output);
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
        $values = [];
        foreach ($this->environmentFields($app) as $name => $field) {
            $values[$name] = $field['value'];
        }
        $this->run(array_merge($command, $arguments), $values);
    }

    private function environmentTemplate(array $app): ?string
    {
        $file = dirname($app['file']) . '/env';
        if (! file_exists($file) && ! is_link($file)) {
            return null;
        }
        $resolved = realpath($file);
        if ($resolved === false || ! $this->isCatalogPath($resolved)
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

    public function importCatalog(string $archive): int
    {
        return $this->withLock(function () use ($archive): int {
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
                            || ! in_array($parts[0], ['standard', 'singleton'], true)) {
                            throw new RuntimeException('ZIP paths must match the apps folder structure without unsafe paths.');
                        }
                        if (isset($entries[$path])) {
                            throw new RuntimeException('The ZIP contains a duplicate path: ' . $path);
                        }
                        $directory = str_ends_with($name, '/');
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
                    $existing = array_column($this->catalog(), null, 'path');
                    $projectNames = [];
                    foreach ($existing as $app) {
                        $projectNames[trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower($app['name'])), '-')] = true;
                    }
                    $count = 0;
                    foreach ($entries as $path => $entry) {
                        if ($entry['directory']
                            || ! in_array(basename($path), ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'], true)) {
                            continue;
                        }
                        if (count(explode('/', $path)) < 4 || isset($existing[$path])) {
                            throw new RuntimeException('Uploaded apps need a category/app folder and must not replace existing catalog items.');
                        }
                        $projectName = trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower(basename(dirname($path)))), '-');
                        if ($projectName === '' || isset($projectNames[$projectName])) {
                            throw new RuntimeException('Uploaded app names must be unique: ' . basename(dirname($path)));
                        }
                        $projectNames[$projectName] = true;
                        $this->metadata(dirname($staging . '/' . $path), $staging);
                        $env = dirname($staging . '/' . $path) . '/env';
                        if (is_file($env)) {
                            $this->readEnvironment($env);
                        }
                        $header = dirname($staging . '/' . $path) . '/header.png';
                        if (is_file($header) && (new \finfo(FILEINFO_MIME_TYPE))->file($header) !== 'image/png') {
                            throw new RuntimeException('Uploaded header.png must be a PNG image.');
                        }
                        $count++;
                    }
                    if ($count === 0) {
                        throw new RuntimeException('The ZIP has no catalog items. Include category/app/compose.yaml under standard or singleton.');
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
    private function run(array $command, ?array $environment = null, bool $mergeOutput = false): string
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
                2 => $mergeOutput ? ['redirect', 1] : $stderrFile,
            ], $pipes, ROOTPATH, $environment === null ? null : array_replace(getenv(), $environment));

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

    private function operationPath(array $app, string $status): string
    {
        return WRITEPATH . 'app-operations/' . $app['id'] . '.' . strtolower($status);
    }

    private function withAppOperation(array $app, string $status, callable $operation): mixed
    {
        $path = $this->operationPath($app, $status);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the app operation directory.');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open the app operation state.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Could not track the app operation.');
            }

            return $operation();
        } finally {
            fclose($handle);
        }
    }

    private function withLock(callable $operation): mixed
    {
        $handle = fopen($this->lockFile, 'c');

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
