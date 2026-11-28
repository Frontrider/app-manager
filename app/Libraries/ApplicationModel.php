<?php

namespace App\Libraries;

/**
 * Backwards-compatible facade for the former monolithic app model.
 *
 * New code should use Catalog, Deployment, and Management directly. This class
 * remains so existing callers and tests can migrate without a flag day.
 *
 * @deprecated Use Catalog, Deployment, and Management instead.
 */
class ApplicationModel
{
    private Catalog $catalog;
    private Deployment $deployment;
    private Management $management;

    public function __construct()
    {
        $docker = new DockerCli();
        $lock = new LocalhostLock();
        $this->catalog = new Catalog();
        $this->management = new Management($this->catalog, $lock);
        $this->deployment = new Deployment($this->catalog, $docker, $lock);
    }

    /**
     * @return list<array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string}>
     */
    public function catalog(): array
    {
        return $this->catalog->catalog();
    }

    /**
     * @return array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string}
     */
    public function find(string $id): array
    {
        return $this->catalog->find($id);
    }

    public function category(array $app): string
    {
        return $this->catalog->category($app);
    }

    /**
     * @param list<array> $apps
     * @param array<string, bool> $running
     * @return array<string, string>
     */
    public function installationBlocks(array $apps, array $running): array
    {
        return $this->catalog->installationBlocks($apps, $running);
    }

    /**
     * @param list<array> $apps
     * @param array<string, bool> $running
     * @return array<string, string>
     */
    public function deploymentBlocks(array $app, array $apps, array $running): array
    {
        return $this->catalog->deploymentBlocks($app, $apps, $running);
    }

    public function headerFile(array $app): ?string
    {
        return $this->catalog->headerFile($app);
    }

    /**
     * @param list<array> $apps
     * @return array<string, bool>
     */
    public function runningApps(array $apps): array
    {
        return $this->deployment->runningApps($apps);
    }

    public function applicationActivity(): array
    {
        return $this->deployment->applicationActivity();
    }

    public function install(array $app, ?array $environment = null): void
    {
        $this->deployment->install($app, $environment);
    }

    public function refresh(array $app): void
    {
        $this->deployment->refresh($app);
    }

    public function uninstall(array $app): void
    {
        $this->deployment->uninstall($app);
    }

    public function deleteData(array $app): void
    {
        $this->deployment->deleteData($app);
    }

    public function stopAll(): void
    {
        $this->deployment->stopAll();
    }

    public function logs(array $app): string
    {
        return $this->deployment->logs($app);
    }

    public function importCatalog(string $archive): int
    {
        return $this->management->importCatalog($archive);
    }

    public function importCompose(string $name, string $subpath, string $compose): string
    {
        return $this->management->importCompose($name, $subpath, $compose);
    }

    public function environmentFields(array $app): ?array
    {
        return $this->deployment->environmentFields($app);
    }
}
