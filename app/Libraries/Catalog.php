<?php

namespace App\Libraries;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Reads the application catalog and the metadata that describes each app.
 *
 * This class is deliberately read-only. Writing to the catalog is handled by
 * Management, and Docker lifecycle work is handled by Deployment.
 */
class Catalog
{
    private string $appsDirectory;

    public function __construct()
    {
        $directory = realpath(getenv('APPS_DIR') ?: ROOTPATH . 'apps');

        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('The apps directory is unavailable.');
        }

        $this->appsDirectory = $directory;
    }

    /**
     * @return list<array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string}>
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
                    'name'    => $metadata['name'] ?? ucwords(str_replace(['-', '_'], ' ', $name)),
                    'path'    => $relativePath,
                    'file'    => $path,
                    'project' => 'localhost-' . $this->projectSlug($name),
                    'website' => $metadata['website'],
                    'caddyPath' => $metadata['caddyPath'],
                    'protected' => $metadata['protected'],
                    'recommended' => $metadata['recommended'],
                    'singletonTags' => $metadata['singletonTags'],
                    'dependencies' => $metadata['dependencies'],
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

    /**
     * @return array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string}
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
        $parts = explode('/', str_replace('\\', '/', $app['path']));

        $folders = array_slice($parts, 0, -2);

        return $folders === [] ? 'General' : implode(' / ', $folders);
    }

    /**
     * Running apps that share at least one singleton tag with another app.
     *
     * @return array<string, string> App IDs mapped to the running app blocking installation.
     */
    public function installationBlocks(array $apps, array $running): array
    {
        $occupied = [];
        foreach ($apps as $app) {
            if (! ($running[$app['id']] ?? false)) {
                continue;
            }
            foreach ($app['singletonTags'] ?? [] as $tag) {
                $occupied[$tag] ??= $app['name'];
            }
        }

        $blocked = [];
        foreach ($apps as $app) {
            if ($running[$app['id']] ?? false) {
                continue;
            }
            foreach ($app['singletonTags'] ?? [] as $tag) {
                if (isset($occupied[$tag])) {
                    $blocked[$app['id']] = $occupied[$tag];
                    break;
                }
            }
        }

        return $blocked;
    }

    /**
     * Apps that would become undeployable if the given app were started.
     *
     * @param list<array> $apps
     * @param array<string, bool> $running
     * @return array<string, string> App IDs mapped to the names that would be blocked.
     */
    public function deploymentBlocks(array $app, array $apps, array $running): array
    {
        $tags = $app['singletonTags'] ?? [];
        if ($tags === []) {
            return [];
        }

        $blocked = [];
        foreach ($apps as $candidate) {
            if ($candidate['id'] === $app['id'] || ($running[$candidate['id']] ?? false)) {
                continue;
            }
            if (array_intersect($tags, $candidate['singletonTags'] ?? []) !== []) {
                $blocked[$candidate['id']] = $candidate['name'];
            }
        }

        return $blocked;
    }

    /**
     * The first singleton tag that would prevent the app from starting.
     *
     * @param list<array> $apps
     * @param array<string, bool> $running
     */
    public function blockingTag(array $app, array $apps, array $running): ?string
    {
        $occupied = [];
        foreach ($apps as $candidate) {
            if (! ($running[$candidate['id']] ?? false)) {
                continue;
            }
            foreach ($candidate['singletonTags'] ?? [] as $tag) {
                $occupied[$tag] = true;
            }
        }

        foreach ($app['singletonTags'] ?? [] as $tag) {
            if (isset($occupied[$tag])) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string} $app
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
     * @param array{id: string, name: string, path: string, file: string, project: string, website: ?string, caddyPath: ?string, protected: bool, recommended: bool, singletonTags: list<string>, dependencies: list<array{name: string, category: string, required: bool}>, description: ?string} $app
     */
    public function environmentTemplate(array $app): ?string
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

    /**
     * @return array<string, array{value: string, description: string}>
     */
    public function readEnvironment(string $file): array
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

    /**
     * @return array{
     *     name: ?string,
     *     website: ?string,
     *     caddyPath: ?string,
     *     protected: bool,
     *     recommended: bool,
     *     singletonTags: list<string>,
     *     dependencies: list<array{name: string, category: string, required: bool}>
     * }
     */
    public function metadata(string $directory, ?string $stagingRoot = null): array
    {
        $file = $directory . '/metadata.json';
        if (! file_exists($file)) {
            return [
                'name' => null,
                'website' => null,
                'caddyPath' => null,
                'protected' => false,
                'recommended' => false,
                'singletonTags' => [],
                'dependencies' => [],
            ];
        }

        $path = realpath($file);
        if ($path === false || ! ($stagingRoot === null ? $this->isCatalogPath($path)
            : str_starts_with($path, $stagingRoot . DIRECTORY_SEPARATOR))
            || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('App metadata must be a readable file within the apps directory.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read app metadata.');
        }

        try {
            $metadata = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Could not parse app metadata.', 0, $exception);
        }
        if (! $metadata instanceof \stdClass) {
            throw new RuntimeException('App metadata must be a JSON object.');
        }
        $metadata = (array) $metadata;

        $name = $metadata['name'] ?? null;
        if ($name !== null && (! is_string($name) || trim($name) === ''
            || strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name))) {
            throw new RuntimeException('App metadata name must be a single-line string of at most 120 characters.');
        }
        $name = $name === null ? null : trim($name);

        $website = $metadata['website'] ?? null;
        $caddyPath = $this->normalizeCaddyPath($metadata['caddy_path'] ?? null);
        $protected = $metadata['protected'] ?? false;
        if (! is_bool($protected)) {
            throw new RuntimeException('App metadata protected must be true or false.');
        }
        $recommended = $metadata['recommended'] ?? false;
        if (! is_bool($recommended)) {
            throw new RuntimeException('App metadata recommended must be true or false.');
        }
        if ($website !== null && (! is_string($website)
            || filter_var($website, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($website, PHP_URL_SCHEME), ['https', 'http'], true))) {
            throw new RuntimeException('App metadata website must be an HTTP or HTTPS URL.');
        }

        return [
            'name' => $name,
            'website' => $website,
            'caddyPath' => $caddyPath,
            'protected' => $protected,
            'recommended' => $recommended,
            'singletonTags' => $this->metadataSingletonTags($metadata['singleton-tags'] ?? []),
            'dependencies' => $this->metadataDependencies($metadata['dependencies'] ?? []),
        ];
    }

    public function normalizeCaddyPath(mixed $caddyPath): ?string
    {
        if ($caddyPath === null) {
            return null;
        }
        if (! is_string($caddyPath)) {
            throw new RuntimeException('App metadata caddy_path must be a same-domain subpath.');
        }

        $caddyPath = trim($caddyPath);
        if ($caddyPath === '' || str_starts_with($caddyPath, '//')
            || str_contains($caddyPath, '\\') || preg_match('/[\x00-\x20\x7f]/', $caddyPath)
            || strpbrk($caddyPath, '?#') !== false
            || parse_url($caddyPath, PHP_URL_SCHEME) !== null
            || parse_url($caddyPath, PHP_URL_HOST) !== null) {
            throw new RuntimeException('App metadata caddy_path must be a same-domain subpath.');
        }

        $segments = array_values(array_filter(
            explode('/', $caddyPath),
            static fn (string $segment): bool => $segment !== '',
        ));

        return $segments === [] ? '/' : '/' . implode('/', $segments) . '/';
    }

    public function projectSlug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name)), '-');
    }

    /**
     * @return list<string>
     */
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
     * @return list<string>
     */
    private function metadataSingletonTags(mixed $tags): array
    {
        if (! is_array($tags) || ! array_is_list($tags)) {
            throw new RuntimeException('App metadata singleton-tags must be a list of strings.');
        }

        $parsed = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                throw new RuntimeException('App metadata singleton-tags must contain strings.');
            }
            $tag = trim($tag);
            if ($tag === '') {
                throw new RuntimeException('App metadata singleton-tags must not be empty.');
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $tag)) {
                throw new RuntimeException('App metadata singleton-tags must not contain control characters.');
            }
            if (in_array($tag, $parsed, true)) {
                throw new RuntimeException('App metadata singleton-tags must not contain duplicates.');
            }
            $parsed[] = $tag;
        }

        return $parsed;
    }

    /**
     * @return list<array{name: string, category: string, required: bool}>
     */
    private function metadataDependencies(mixed $dependencies): array
    {
        if (! is_array($dependencies) || ! array_is_list($dependencies)) {
            throw new RuntimeException('App metadata dependencies must be a list.');
        }

        $parsed = [];
        foreach ($dependencies as $dependency) {
            if (! $dependency instanceof \stdClass) {
                throw new RuntimeException('App metadata dependencies must contain JSON objects.');
            }
            $dependency = (array) $dependency;

            $name = $dependency['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                throw new RuntimeException('App metadata dependency name must be a non-empty string.');
            }

            $category = $dependency['category'] ?? null;
            if (! is_string($category) || trim($category) === '') {
                throw new RuntimeException('App metadata dependency category must be a non-empty string.');
            }

            $required = $dependency['required'] ?? true;
            if (! is_bool($required)) {
                throw new RuntimeException('App metadata dependency required must be true or false.');
            }

            $parsed[] = [
                'name' => trim($name),
                'category' => trim($category),
                'required' => $required,
            ];
        }

        return $parsed;
    }
}
