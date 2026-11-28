<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Docker/Compose lifecycle for catalog applications.
 *
 * Deployment owns running-state detection, operation locks, environment
 * persistence, dependency ordering, the actual Compose commands, and the
 * deployment/startup/container logs they produce.
 */
class Deployment
{
    private Catalog $catalog;
    private DockerCli $docker;
    private LocalhostLock $lock;

    public function __construct(
        ?Catalog $catalog = null,
        ?DockerCli $docker = null,
        ?LocalhostLock $lock = null,
    ) {
        $this->catalog = $catalog ?? new Catalog();
        $this->docker = $docker ?? new DockerCli();
        $this->lock = $lock ?? new LocalhostLock();
    }

    /**
     * @param list<array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string}> $apps
     * @return array<string, bool>
     */
    public function runningApps(array $apps): array
    {
        $running = array_fill_keys(array_column($apps, 'id'), false);
        $ids     = $this->docker->run([$this->docker->binary(), 'ps', '--quiet']);
        $ids     = array_values(array_filter(array_map('trim', explode("\n", $ids))));

        if ($ids === []) {
            return $running;
        }

        $output = $this->docker->run(array_merge(
            [$this->docker->binary(), 'inspect', '--format', '{{json .Config.Labels}}'],
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
     * @return list<array{id: string, name: string, category: string, status: string, containers: list<array{id: string, name: string, status: string}>, cpuPercent: float, memoryBytes: int}>
     */
    public function applicationActivity(): array
    {
        $apps = $this->catalog->catalog();
        $ids = preg_split('/\s+/', trim($this->docker->run([
            $this->docker->binary(), 'ps', '--all', '--quiet', '--filter', 'label=com.docker.compose.project',
        ])), -1, PREG_SPLIT_NO_EMPTY);
        $containers = [];
        $containerIds = [];
        if ($ids !== []) {
            $output = $this->docker->run(array_merge([
                $this->docker->binary(), 'inspect', '--format',
                '{"id":{{json .Id}},"name":{{json .Name}},"labels":{{json .Config.Labels}},"state":{{json .State}},"restart":{{json .HostConfig.RestartPolicy.Name}}}',
            ], $ids));
            foreach (array_filter(explode("\n", trim($output))) as $line) {
                $container = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $state = $container['state'];
                if ($state['Status'] === 'exited' && $state['ExitCode'] === 0
                    && $container['restart'] === 'no') {
                    continue;
                }
                $status = match (true) {
                    $state['Status'] === 'exited' => 'Stopped',
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
                    $containerId = (string) ($container['id'] ?? '');
                    $containers[$container['labels']['com.docker.compose.project']][] = [
                        'id' => $containerId,
                        'name' => ltrim($container['name'], '/'),
                        'status' => $status,
                    ];
                    if ($containerId !== '') {
                        $containerIds[] = $containerId;
                    }
                }
            }
        }

        $stats = [];
        try {
            $stats = $this->containerStats($containerIds);
        } catch (RuntimeException $exception) {
            log_message('error', 'Could not read container resource usage: {message}', [
                'message' => $exception->getMessage(),
            ]);
        }

        $activity = [];
        foreach ($apps as $app) {
            $services = $containers[$app['project']] ?? [];
            $status = null;
            foreach (['Deleting', 'Stopping', 'Starting'] as $operation) {
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
                foreach (['Deleting', 'Stopping', 'Failed', 'Starting', 'Paused', 'Running', 'Stopped'] as $candidate) {
                    if (in_array($candidate, array_column($services, 'status'), true)) {
                        $status = $candidate;
                        break;
                    }
                }
            }
            if ($status !== null) {
                $cpuPercent = 0.0;
                $memoryBytes = 0;
                foreach ($services as $service) {
                    $usage = $stats[$service['id']] ?? null;
                    if ($usage === null) {
                        continue;
                    }
                    $cpuPercent += $usage['cpuPercent'];
                    $memoryBytes += $usage['memoryBytes'];
                }

                $activity[] = [
                    'id' => $app['id'], 'name' => $app['name'], 'category' => $this->catalog->category($app),
                    'status' => $status, 'containers' => $services,
                    'cpuPercent' => round($cpuPercent, 1), 'memoryBytes' => $memoryBytes,
                ];
            }
        }

        return $activity;
    }

    /**
     * @param list<string> $ids
     * @return array<string, array{cpuPercent: float, memoryBytes: int}>
     */
    private function containerStats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $output = $this->docker->run(array_merge([
            $this->docker->binary(), 'stats', '--all', '--no-stream', '--no-trunc', '--format',
            '{{.ID}}\t{{.CPUPerc}}\t{{.MemUsage}}',
        ], array_values(array_unique($ids))));

        $stats = [];
        foreach (array_filter(explode("\n", trim($output))) as $line) {
            $fields = explode("\t", $line, 3);
            if (count($fields) !== 3) {
                throw new RuntimeException('Docker returned invalid container statistics.');
            }

            $cpu = rtrim(trim($fields[1]), '%');
            $stats[trim($fields[0])] = [
                'cpuPercent' => is_numeric($cpu) ? max(0, (float) $cpu) : 0.0,
                'memoryBytes' => $this->parseMemoryUsage($fields[2]),
            ];
        }

        return $stats;
    }

    private function parseMemoryUsage(string $usage): int
    {
        $used = trim(explode('/', $usage, 2)[0]);
        if (! preg_match('/^([0-9.]+)\s*([KMGTP]?i?B)$/i', $used, $match)) {
            return 0;
        }

        $unit = strtoupper($match[2]);
        $power = strpos('BKMGTP', $unit[0]);
        if ($power === false) {
            return 0;
        }

        return (int) round((float) $match[1] * ((str_contains($unit, 'I') ? 1024 : 1000) ** $power));
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string} $app
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
        $this->lock->run(function () use ($app, $environment, $refresh): void {
            $apps = $this->catalog->catalog();
            $appsById = array_column($apps, null, 'id');
            if (! isset($appsById[$app['id']])) {
                throw new RuntimeException('That app is not available in the catalog.');
            }

            $running = $this->runningApps($apps);
            $this->startApplication($appsById[$app['id']], $apps, $running, $environment, $refresh, []);
        });
    }

    /**
     * @param list<array> $apps
     * @param array<string, bool> $running
     * @param list<string> $chain
     */
    private function startApplication(
        array $app,
        array $apps,
        array &$running,
        ?array $environment,
        bool $refresh,
        array $chain,
    ): void {
        if (in_array($app['id'], $chain, true)) {
            throw new RuntimeException('Circular app dependency detected for ' . $app['name'] . '.');
        }

        if ($running[$app['id']] && ! $refresh) {
            throw new RuntimeException($app['name'] . ' is already running and cannot be deployed again.');
        }

        $chain[] = $app['id'];
        foreach ($app['dependencies'] ?? [] as $dependency) {
            $resolved = $this->resolveDependency($apps, $dependency);
            if ($resolved === null) {
                if (! $dependency['required']) {
                    continue;
                }
                throw new RuntimeException(
                    'Required dependency ' . $dependency['name'] . ' in category '
                    . $dependency['category'] . ' is not available for ' . $app['name'] . '.',
                );
            }
            if ($running[$resolved['id']]) {
                continue;
            }
            $this->startApplication($resolved, $apps, $running, null, false, $chain);
        }

        $blocks = $this->catalog->installationBlocks($apps, $running);
        if (isset($blocks[$app['id']])) {
            $tag = $this->catalog->blockingTag($app, $apps, $running);
            $reason = $tag === null
                ? 'a conflicting singleton tag'
                : 'the "' . $tag . '" singleton tag';
            throw new RuntimeException(
                $blocks[$app['id']] . ' is already running with ' . $reason
                . '. Stop it before starting ' . $app['name'] . '.',
            );
        }

        $this->withAppOperation($app, 'Starting', function () use ($app, $environment, $refresh): void {
            try {
                $this->resetDeploymentLog($app);
                if ($environment !== null) {
                    $this->saveEnvironment($app, $environment);
                }
                $this->deploy($app, $refresh);
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

        $running[$app['id']] = true;
    }

    /**
     * Run the normal start once. If it fails, retry with the full rebuild path
     * once before recording the startup failure. This recovers from stale or
     * broken images that need to be pulled/built again.
     */
    private function deploy(array $app, bool $refresh): void
    {
        if ($refresh) {
            $this->rebuild($app);
            return;
        }

        try {
            $this->compose($app, true, 'up', '-d');
        } catch (RuntimeException) {
            $this->rebuild($app);
        }
    }

    /**
     * Pull images, rebuild local images, and recreate the Compose project.
     */
    private function rebuild(array $app): void
    {
        $this->compose($app, true, 'pull', '--ignore-buildable');
        $this->compose($app, true, 'build', '--pull', '--no-cache');
        $this->compose($app, true, 'up', '-d', '--force-recreate', '--always-recreate-deps', '--remove-orphans');
    }

    /**
     * @param list<array> $apps
     * @param array{name: string, category: string, required: bool} $dependency
     */
    private function resolveDependency(array $apps, array $dependency): ?array
    {
        $name = $this->normalizeAppIdentifier($dependency['name']);
        $category = $this->normalizeAppIdentifier($dependency['category']);
        $matches = [];
        foreach ($apps as $candidate) {
            if ($category !== $this->normalizeAppIdentifier($this->catalog->category($candidate))) {
                continue;
            }
            $folder = basename(dirname(str_replace('\\', '/', $candidate['path'])));
            if ($name === $this->normalizeAppIdentifier($candidate['name'])
                || $name === $this->normalizeAppIdentifier($folder)) {
                $matches[$candidate['id']] = $candidate;
            }
        }

        if (count($matches) > 1) {
            throw new RuntimeException(
                'App dependency ' . $dependency['name'] . ' in category ' . $dependency['category']
                . ' is ambiguous.',
            );
        }

        return $matches === [] ? null : reset($matches);
    }

    private function normalizeAppIdentifier(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', ' '], '-', $value);

        return preg_replace('/-*\/-*/', '/', $value) ?? $value;
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string} $app
     */
    public function uninstall(array $app): void
    {
        $this->lock->run(fn () => $this->withAppOperation(
            $app, 'Stopping', fn () => $this->compose($app, false, 'stop'),
        ));
    }

    public function deleteData(array $app): void
    {
        $this->lock->run(function () use ($app): void {
            if ($this->runningApps([$app])[$app['id']]) {
                throw new RuntimeException('Stop the app before deleting its data.');
            }
            $this->withAppOperation(
                $app,
                'Deleting',
                fn () => $this->compose($app, false, 'down', '--volumes', '--remove-orphans'),
            );
        });
    }

    public function stopAll(): void
    {
        $this->lock->run(function (): void {
            $failures = [];
            foreach ($this->catalog->catalog() as $app) {
                try {
                    $output = $this->docker->run([
                        $this->docker->binary(), 'ps', '--all', '--quiet', '--filter',
                        'label=com.docker.compose.project=' . $app['project'],
                    ]);
                    $ids = array_values(array_filter(array_map('trim', explode("\n", $output))));
                    if ($ids !== []) {
                        $this->withAppOperation(
                            $app, 'Stopping', fn () => $this->docker->run(array_merge([$this->docker->binary(), 'stop'], $ids)),
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

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string} $app
     */
    private function compose(array $app, bool $logOutput, string ...$arguments): void
    {
        $command = [
            $this->docker->binary(),
            'compose',
            '--project-name',
            $app['project'],
            '--file',
            $app['file'],
        ];
        $environment = $this->catalog->environmentTemplate($app);
        if ($environment !== null) {
            array_push($command, '--env-file', $environment);
        }

        $logFile = $logOutput ? $this->deploymentLogPath($app) : null;
        $saved = $this->savedEnvironmentPath($app);
        if ($environment === null || ! is_file($saved)) {
            $this->docker->run(array_merge($command, $arguments), null, false, $logFile);
            return;
        }

        array_push($command, '--env-file', $saved);
        $values = [];
        foreach ($this->environmentFields($app) as $name => $field) {
            $values[$name] = $field['value'];
        }
        $this->docker->run(array_merge($command, $arguments), $values, false, $logFile);
    }

    /**
     * @return ?array<string, array{value: string, description: string}>
     */
    public function environmentFields(array $app): ?array
    {
        $template = $this->catalog->environmentTemplate($app);
        if ($template === null) {
            return null;
        }
        $fields = $this->catalog->readEnvironment($template);
        $saved = $this->savedEnvironmentPath($app);
        if (is_file($saved)) {
            foreach ($this->catalog->readEnvironment($saved) as $name => $field) {
                if (isset($fields[$name])) {
                    $fields[$name]['value'] = $field['value'];
                }
            }
        }

        return $fields;
    }

    private function savedEnvironmentPath(array $app): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $app['id'])) {
            throw new RuntimeException('Invalid app ID for environment settings.');
        }

        return WRITEPATH . 'app-environments/' . $app['id'] . '.env';
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

        $deployment = $this->deploymentLogPath($app);
        if (($output !== '' || $this->operationIsActive($app, 'Starting')) && is_file($deployment)) {
            $contents = trim($this->docker->readTail($deployment));
            if ($contents !== '') {
                $output .= "Deployment output\n" . $contents . "\n\n";
            }
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

    public function startupFailurePath(array $app): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $app['id'])) {
            throw new RuntimeException('Invalid app ID for startup logs.');
        }

        return WRITEPATH . 'app-startup-failures/' . $app['id'] . '.log';
    }

    public function deploymentLogPath(array $app): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $app['id'])) {
            throw new RuntimeException('Invalid app ID for deployment logs.');
        }

        return WRITEPATH . 'app-deployment-logs/' . $app['id'] . '.log';
    }

    public function resetDeploymentLog(array $app): void
    {
        $path = $this->deploymentLogPath($app);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the deployment logs directory.');
        }
        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Could not reset the deployment log.');
        }
        if (! chmod($path, 0600)) {
            throw new RuntimeException('Could not secure the deployment log.');
        }
    }

    public function saveStartupFailure(array $app, string $message): void
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

    private function operationPath(array $app, string $status): string
    {
        return WRITEPATH . 'app-operations/' . $app['id'] . '.' . strtolower($status);
    }

    private function operationIsActive(array $app, string $status): bool
    {
        $path = $this->operationPath($app, $status);
        if (! is_file($path)) {
            return false;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        try {
            $locked = flock($handle, LOCK_SH | LOCK_NB, $wouldBlock);

            return ! $locked && $wouldBlock;
        } finally {
            fclose($handle);
        }
    }

    private function containerLogs(array $app): string
    {
        $containers = trim($this->docker->run([
            $this->docker->binary(), 'ps', '--all',
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
            $logs = $this->docker->run([
                $this->docker->binary(), 'logs', '--timestamps', '--tail', '100', $id,
            ], null, true);
            $output[] = $name . "\n" . ($logs === '' ? 'No logs available.' : rtrim($logs));
        }

        return implode("\n\n", $output);
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
}
