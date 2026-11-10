<?php

use App\Libraries\ApplicationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class AppCategoryTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $directory;
    private array $environment = [];
    private ApplicationModel $store;
    private array $apps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/app-category-' . bin2hex(random_bytes(8));
        foreach (['APPS_DIR', 'DOCKER_BIN', 'CATEGORY_TEST_PROJECT', 'CATEGORY_TEST_CALLS',
            'CATEGORY_TEST_SESSION_LOCK', 'CATEGORY_TEST_VERBOSE', 'CATEGORY_TEST_ACTIVITY',
            'CATEGORY_TEST_OPERATION'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        foreach (['singleton/files/first', 'singleton/files/second', 'singleton/video/player',
            'singleton/books/reader', 'standard/files/unlimited'] as $path) {
            mkdir($this->directory . '/' . $path, 0777, true);
            file_put_contents($this->directory . '/' . $path . '/compose.yaml', "services: {}\n");
        }
        putenv('APPS_DIR=' . $this->directory);
        putenv('DOCKER_BIN=' . ROOTPATH . 'tests/_support/Fixtures/category-docker.php');
        putenv('CATEGORY_TEST_CALLS=' . $this->directory . '/calls');
        putenv('CATEGORY_TEST_PROJECT');
        putenv('CATEGORY_TEST_SESSION_LOCK');
        putenv('CATEGORY_TEST_VERBOSE');
        putenv('CATEGORY_TEST_ACTIVITY');
        putenv('CATEGORY_TEST_OPERATION');
        $this->store = new ApplicationModel();
        $this->apps = array_column($this->store->catalog(), null, 'name');
    }

    protected function tearDown(): void
    {
        foreach ($this->apps as $app) {
            $saved = WRITEPATH . 'app-environments/' . $app['id'] . '.env';
            if (is_file($saved)) {
                unlink($saved);
            }
            $failure = WRITEPATH . 'app-startup-failures/' . $app['id'] . '.log';
            if (is_file($failure)) {
                unlink($failure);
            }
            foreach (['starting', 'stopping'] as $state) {
                $operation = WRITEPATH . 'app-operations/' . $app['id'] . '.' . $state;
                if (is_file($operation)) {
                    unlink($operation);
                }
            }
        }
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testCategoryAndSingletonBlocks(): void
    {
        $apps = array_values($this->apps);
        $running = array_fill_keys(array_column($apps, 'id'), false);
        $running[$this->apps['First']['id']] = true;
        $this->assertSame('files', $this->store->category($this->apps['First']));
        $this->assertSame('video', $this->store->category($this->apps['Player']));
        $this->assertSame('files', $this->store->category($this->apps['Unlimited']));
        $this->assertSame(
            [$this->apps['Second']['id'] => 'First'],
            $this->store->installationBlocks($apps, $running),
        );
    }

    private function activityContainer(
        string $project,
        string $state,
        ?string $health = null,
        int $exitCode = 0,
        string $restart = 'unless-stopped',
    ): array
    {
        return [
            'name' => '/test-server',
            'labels' => ['com.docker.compose.project' => $project],
            'state' => ['Status' => $state, 'ExitCode' => $exitCode, 'Health' => ['Status' => $health]],
            'restart' => $restart,
        ];
    }

    public function testActivityAggregatesDockerStatesAndIgnoresCompletedSetupAndUnknownProjects(): void
    {
        $containers = [
            $this->activityContainer($this->apps['First']['project'], 'running'),
            $this->activityContainer($this->apps['First']['project'], 'exited'),
            $this->activityContainer($this->apps['Player']['project'], 'running', 'starting'),
            $this->activityContainer($this->apps['Reader']['project'], 'exited'),
            $this->activityContainer($this->apps['Reader']['project'], 'exited', null, 143),
            $this->activityContainer($this->apps['Reader']['project'], 'exited', 'starting'),
            $this->activityContainer($this->apps['Player']['project'], 'exited', null, 0, 'no'),
            $this->activityContainer('unrelated-project', 'running'),
        ];
        putenv('CATEGORY_TEST_ACTIVITY=' . json_encode($containers));
        $activity = array_column($this->store->applicationActivity(), null, 'name');
        $this->assertSame(['First', 'Player', 'Reader'], array_keys($activity));
        $this->assertSame('Running', $activity['First']['status']);
        $this->assertCount(2, $activity['First']['containers']);
        $this->assertSame('Stopped', $activity['First']['containers'][1]['status']);
        $this->assertSame('Starting', $activity['Player']['status']);
        $this->assertCount(1, $activity['Player']['containers']);
        $this->assertSame('video', $activity['Player']['category']);
        $this->assertSame('Stopped', $activity['Reader']['status']);
        $this->assertCount(3, $activity['Reader']['containers']);
        foreach ([
            ['created', null, 0, 'Starting'], ['restarting', null, 0, 'Starting'],
            ['running', 'unhealthy', 0, 'Failed'], ['removing', null, 0, 'Stopping'],
            ['paused', null, 0, 'Paused'], ['dead', null, 0, 'Failed'],
        ] as [$state, $health, $exitCode, $expected]) {
            putenv('CATEGORY_TEST_ACTIVITY=' . json_encode([
                $this->activityContainer($this->apps['First']['project'], $state, $health, $exitCode),
            ]));
            $this->assertSame($expected, $this->store->applicationActivity()[0]['status']);
        }
    }

    public function testActivityShowsLiveOperationsButNeverStaleMarkers(): void
    {
        putenv('CATEGORY_TEST_ACTIVITY=[]');
        $directory = WRITEPATH . 'app-operations';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach (['Starting', 'Stopping'] as $state) {
            $handle = fopen($directory . '/' . $this->apps['First']['id'] . '.' . strtolower($state), 'c');
            flock($handle, LOCK_EX);
            try {
                $activity = $this->store->applicationActivity();
                $this->assertSame('First', $activity[0]['name']);
                $this->assertSame($state, $activity[0]['status']);
                $this->assertSame([], $activity[0]['containers']);
            } finally {
                fclose($handle);
            }
            $this->assertSame([], $this->store->applicationActivity());
        }
    }

    public function testStartRefreshStopAndStopAllPublishOperationStateAndReleaseIt(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_OPERATION=' . $app['id']);
        $this->store->install($app);
        $this->store->refresh($app);
        $this->store->uninstall($app);
        putenv('CATEGORY_TEST_PROJECT=' . $app['project']);
        $this->store->stopAll();
        $states = array_map(static fn (string $line): array => json_decode($line, true),
            file($this->directory . '/calls.operations'));
        $this->assertSame([
            ['Starting'], ['Starting'], ['Starting'], ['Starting'], ['Stopping'], ['Stopping'],
        ], $states);
        putenv('CATEGORY_TEST_PROJECT');
        $this->assertSame([], $this->store->applicationActivity());
        putenv('CATEGORY_TEST_VERBOSE=1');
        try {
            $this->store->install($app);
            $this->fail('A failed deployment must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('simulated failure', $exception->getMessage());
        }
        $this->assertSame([], $this->store->applicationActivity());
    }

    public function testDeleteDataRemovesOnlyComposeProjectVolumesAndPublishesOperation(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_OPERATION=' . $app['id']);
        $this->store->deleteData($app);
        $calls = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->directory . '/calls'));
        $this->assertSame(
            ['down', '--volumes', '--remove-orphans'],
            array_slice($calls[0], -3),
        );
        $states = array_map(static fn (string $line): array => json_decode($line, true),
            file($this->directory . '/calls.operations'));
        $this->assertSame([['Deleting']], $states);
        putenv('CATEGORY_TEST_ACTIVITY=[]');
        $this->assertSame([], $this->store->applicationActivity());
    }

    public function testDeleteDataRefusesAppsWithRunningContainers(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_PROJECT=' . $app['project']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stop the app before deleting its data.');
        $this->store->deleteData($app);
    }

    public function testActivityScreenAndFeedRequireLoginAndAreAvailableToReaders(): void
    {
        $this->get('activity')->assertRedirectTo(site_url('login'));
        $this->get('activity/output')->assertRedirectTo(site_url('login'));
        putenv('CATEGORY_TEST_ACTIVITY=[]');
        $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]]);
        $page = $this->get('activity');
        $page->assertStatus(200);
        $html = $page->response()->getBody();
        $this->assertStringContainsString('Application activity</h1>', $html);
        $this->assertStringContainsString('data-application-activity=', $html);
        $this->assertStringNotContainsString('Delete data</button>', $html);
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $this->assertSame(0, (new DOMXPath($document))->query('//*[@data-activity-logs]')->length);
        $feed = $this->get('activity/output');
        $feed->assertStatus(200);
        $this->assertSame(['applications' => []], json_decode($feed->response()->getBody(), true));
        $this->assertStringContainsString('no-store', $feed->response()->getHeaderLine('Cache-Control'));
        $admin = $this->withSession(['ldap_user' => [
            'username' => 'admin', 'isAdmin' => true, 'expiresAt' => time() + 3600,
        ]])->get('activity');
        $admin->assertStatus(200);
        $document = new DOMDocument();
        @$document->loadHTML($admin->response()->getBody());
        $this->assertSame(1, (new DOMXPath($document))->query('//*[@data-activity-logs]')->length);
        $this->assertStringContainsString('data-delete-data-form', $admin->response()->getBody());
        $this->assertStringContainsString('Delete volumes permanently', $admin->response()->getBody());
        $this->assertStringContainsString('This action is destructive and cannot be undone.', $admin->response()->getBody());
    }

    public function testActivityFeedReportsDockerErrorsInsteadOfAnEmptyList(): void
    {
        putenv('CATEGORY_TEST_ACTIVITY=error');
        $result = $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->get('activity/output');
        $result->assertStatus(500);
        $this->assertArrayHasKey('error', json_decode($result->response()->getBody(), true));
    }

    public function testProjectNamesAreHumanReadable(): void
    {
        $this->assertSame('localhost-first', $this->apps['First']['project']);
        $this->assertSame('localhost-player', $this->apps['Player']['project']);
    }

    public function testMetadataProtectionIsDisplayedBesideRuntimeStatus(): void
    {
        $app = $this->apps['First'];
        $metadata = dirname($app['file']) . '/metadata.properties';
        file_put_contents($metadata, "protected=true\n");
        $protected = array_column($this->store->catalog(), null, 'id')[$app['id']];
        $this->assertTrue($protected['protected']);
        $html = view('partials/app_card', [
            'app' => $protected, 'isRunning' => true, 'canManage' => false,
        ]);
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $this->assertSame(
            1,
            $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " tags ")]'
                . '/span[normalize-space(.)="Running"]/following-sibling::span'
                . '[@class="tag is-info is-light" and normalize-space(.)="Protected"]')->length,
        );

        file_put_contents($metadata, "protected=false\n");
        $unprotected = array_column($this->store->catalog(), null, 'id')[$app['id']];
        $this->assertFalse($unprotected['protected']);
        $html = view('partials/app_card', [
            'app' => $unprotected, 'isRunning' => false, 'canManage' => false,
        ]);
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " tags ")]'
            . '/span[normalize-space(.)="Stopped"]')->length);
        $this->assertSame(0, $xpath->query('//span[normalize-space(.)="Unprotected"]')->length);

        unlink($metadata);
        $withoutMetadata = array_column($this->store->catalog(), null, 'id')[$app['id']];
        $this->assertFalse($withoutMetadata['protected']);
    }

    public function testInvalidProtectionMetadataIsRejected(): void
    {
        file_put_contents(dirname($this->apps['First']['file']) . '/metadata.properties', "protected=yes\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protected must be true or false');
        $this->store->catalog();
    }

    public function testAppReadmeIsUsedAsAnEscapedDescription(): void
    {
        $app = $this->apps['First'];
        file_put_contents(dirname($app['file']) . '/readme.md', "# First\n<script>alert(1)</script>\n");
        $catalog = array_column($this->store->catalog(), null, 'id');
        $this->assertSame("# First\n<script>alert(1)</script>", $catalog[$app['id']]['description']);
        $this->assertNull($catalog[$this->apps['Second']['id']]['description']);
        $html = view('partials/app_card', [
            'app' => $catalog[$app['id']], 'isRunning' => false, 'canManage' => false,
        ]);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testAppReadmeCannotEscapeCatalog(): void
    {
        symlink(__FILE__, dirname($this->apps['First']['file']) . '/readme.md');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readable file within the catalog');
        $this->store->catalog();
    }

    public function testAppDescriptionRendersMarkdownWithoutUnsafeHtmlOrLinks(): void
    {
        $app = $this->apps['First'];
        $app['description'] = "# Heading\n\n**Bold** and [website](https://example.com)\n\n"
            . "- First item\n- Second item\n\n<script>alert(1)</script>\n\n[unsafe](javascript:alert(1))";
        $html = view('partials/app_card', [
            'app' => $app, 'isRunning' => false, 'canManage' => false,
        ]);
        $this->assertStringContainsString('<h1>Heading</h1>', $html);
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com">website</a>', $html);
        $this->assertStringContainsString('<li>First item</li>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function testStopAllTargetsOnlyCatalogProjectContainers(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        $this->store->stopAll();
        $calls = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->directory . '/calls'));
        $stops = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'stop'));
        $this->assertSame([['stop', 'category-container']], $stops);
        foreach (array_filter($calls, static fn (array $call): bool => $call[0] === 'ps') as $call) {
            $this->assertContains('--all', $call);
            $this->assertContains('--filter', $call);
            $this->assertContains(substr($call[array_key_last($call)], strlen('label=com.docker.compose.project=')),
                array_column($this->apps, 'project'));
        }
    }

    public function testStopAllButtonAndEndpointAreAdminOnly(): void
    {
        $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->post('catalog/stop-all')->assertStatus(403);
        $this->assertFileDoesNotExist($this->directory . '/calls');
        $page = $this->get('/');
        $this->assertStringNotContainsString('Stop all apps', $page->response()->getBody());
        $page = $this->withSession(['ldap_user' => [
            'username' => 'admin', 'isAdmin' => true, 'expiresAt' => time() + 3600,
        ]])->get('/');
        $this->assertStringContainsString('Stop all apps', $page->response()->getBody());
    }

    public function testRefreshRebuildsAndRecreatesRunningAppWithoutRemovingVolumes(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_PROJECT=' . $app['project']);
        $this->store->refresh($app);
        $calls = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->directory . '/calls'));
        $this->assertCount(3, $calls);
        $this->assertSame(['pull', '--ignore-buildable'], array_slice($calls[0], -2));
        $this->assertSame(['build', '--pull', '--no-cache'], array_slice($calls[1], -3));
        $this->assertSame(
            ['up', '-d', '--force-recreate', '--always-recreate-deps', '--remove-orphans'],
            array_slice($calls[2], -5),
        );
        foreach ($calls as $call) {
            $this->assertNotContains('down', $call);
            $this->assertNotContains('--volumes', $call);
        }
    }

    public function testRefreshCannotBypassSingletonExclusivity(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stop it before starting');
        $this->store->refresh($this->apps['Second']);
    }

    public function testRefreshReusesSavedEnvironment(): void
    {
        $app = $this->apps['Unlimited'];
        file_put_contents(dirname($app['file']) . '/env', "SERVER_PORT=12345\n");
        $this->store->install($app, ['SERVER_PORT' => '23456']);
        $this->store->refresh($app);
        $calls = file($this->directory . '/calls');
        foreach (array_slice($calls, 1) as $call) {
            $arguments = json_decode($call, true, 512, JSON_THROW_ON_ERROR);
            $this->assertContains(WRITEPATH . 'app-environments/' . $app['id'] . '.env', $arguments);
        }
        $this->assertSame('23456', $this->store->environmentFields($app)['SERVER_PORT']['value']);
    }

    public function testRefreshFailureIsSavedForLogs(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_VERBOSE=1');
        try {
            $this->store->refresh($app);
            $this->fail('Refresh should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('simulated failure', $exception->getMessage());
        }
        $this->assertStringContainsString('simulated failure',
            file_get_contents(WRITEPATH . 'app-startup-failures/' . $app['id'] . '.log'));
    }

    public function testRefreshIsAdminOnly(): void
    {
        $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->post('apps/' . $this->apps['First']['id'] . '/refresh')->assertStatus(403);
        $this->assertFileDoesNotExist($this->directory . '/calls');
    }

    public function testLogsIncludeAllProjectContainersAndBothOutputStreams(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_PROJECT=' . $app['project']);
        $logs = $this->store->logs($app);
        $this->assertStringContainsString('localhost-first-server-1', $logs);
        $this->assertStringContainsString('localhost-first-init-1', $logs);
        $this->assertStringContainsString('server ready', $logs);
        $this->assertStringContainsString('server warning', $logs);
        $calls = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->directory . '/calls'));
        $this->assertSame([
            ['ps', '--all', '--filter', 'label=com.docker.compose.project=' . $app['project'],
                '--format', '{{.ID}}\t{{.Names}}'],
            ['logs', '--timestamps', '--tail', '100', 'category-container'],
            ['logs', '--timestamps', '--tail', '100', 'category-init'],
        ], $calls);
    }

    public function testLogsExplainWhenAppHasNoContainers(): void
    {
        $this->assertSame('No containers exist for this app yet.', $this->store->logs($this->apps['First']));
    }

    public function testStartupFailurePersistsWithoutContainersAndClearsAfterSuccess(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_VERBOSE=1');
        try {
            $this->store->install($app);
            $this->fail('Startup should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('simulated failure', $exception->getMessage());
        }
        $logs = (new ApplicationModel())->logs($app);
        $this->assertStringContainsString('Startup failed at ', $logs);
        $this->assertStringContainsString('simulated failure', $logs);
        $this->assertStringContainsString('No containers exist for this app yet.', $logs);
        $this->assertSame(0600, fileperms(WRITEPATH . 'app-startup-failures/' . $app['id'] . '.log') & 0777);
        putenv('CATEGORY_TEST_VERBOSE');
        $this->store->install($app);
        $this->assertStringNotContainsString('Startup failed', $this->store->logs($app));
    }

    public function testStartupFailureRemainsVisibleWhenContainerLogsFail(): void
    {
        $app = $this->apps['First'];
        putenv('CATEGORY_TEST_VERBOSE=1');
        try {
            $this->store->install($app);
            $this->fail('Startup should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('simulated failure', $exception->getMessage());
        }
        putenv('CATEGORY_TEST_PROJECT=' . $app['project']);
        $logs = $this->store->logs($app);
        $this->assertStringContainsString('Startup failed at ', $logs);
        $this->assertStringContainsString('simulated failure', $logs);
        $this->assertStringContainsString('Could not read container logs: simulated log failure', $logs);
    }

    public function testLogFailuresAreReported(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        putenv('CATEGORY_TEST_VERBOSE=1');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('simulated log failure');
        $this->store->logs($this->apps['First']);
    }

    public function testAdminsCanReadLogsAsUncachedPlainText(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        $result = $this->withSession(['ldap_user' => [
            'username' => 'admin', 'isAdmin' => true, 'expiresAt' => time() + 3600,
        ]])->get('apps/' . $this->apps['First']['id'] . '/logs/output');
        $result->assertStatus(200);
        $this->assertStringStartsWith('text/plain', $result->response()->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('server warning', $result->response()->getBody());
    }

    public function testLogDisplayRemovesTerminalCommandsAndPreservesUnicode(): void
    {
        $method = new ReflectionMethod(\App\Controllers\Home::class, 'plainTextLogs');
        $input = "timestamp \x1b[?2027\$p\x1b[?1h\x1b=\x1b[?2004h"
            . "\x1b[K\x1b[1m\x1b[94m[I]\x1b[0m café \u{F7EF} <script>\r\n"
            . "\x1b]0;window title\x07next\x1b]8;;https://example.com\x1b\\"
            . "link\x1b]8;;\x1b\\\rfinished";
        $this->assertSame(
            "timestamp [I] café \u{F7EF} <script>\nnextlink\nfinished",
            $method->invoke(new \App\Controllers\Home(), $input),
        );
    }

    public function testNonAdminsCannotReadLogsOrSeeLogLinks(): void
    {
        $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->get('apps/' . $this->apps['First']['id'] . '/logs')->assertStatus(403);
        $this->get('apps/' . $this->apps['First']['id'] . '/logs/output')->assertStatus(403);
        $html = view('partials/app_card', [
            'app' => $this->apps['First'], 'isRunning' => true, 'canManage' => false,
        ]);
        $this->assertStringNotContainsString('View logs', $html);
        $html = view('partials/app_card', [
            'app' => $this->apps['First'], 'isRunning' => false, 'canManage' => true,
        ]);
        $this->assertStringContainsString('View logs', $html);
        $this->assertStringContainsString(site_url('apps/' . $this->apps['First']['id'] . '/logs'), $html);
        $this->assertStringNotContainsString('data-app-logs', $html);
    }

    public function testAdminsSeeLogsOnSeparatePage(): void
    {
        $result = $this->withSession(['ldap_user' => [
            'username' => 'admin', 'isAdmin' => true, 'expiresAt' => time() + 3600,
        ]])->get('apps/' . $this->apps['First']['id'] . '/logs');
        $result->assertStatus(200);
        $this->assertSame('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('First logs', $result->response()->getBody());
        $this->assertStringContainsString('data-app-logs', $result->response()->getBody());
        $this->assertStringContainsString('Back to applications', $result->response()->getBody());
    }

    public function testLogEndpointReportsDockerErrors(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        putenv('CATEGORY_TEST_VERBOSE=1');
        $result = $this->withSession(['ldap_user' => [
            'username' => 'admin', 'isAdmin' => true, 'expiresAt' => time() + 3600,
        ]])->get('apps/' . $this->apps['First']['id'] . '/logs/output');
        $result->assertStatus(500);
        $this->assertStringContainsString('simulated log failure', $result->response()->getBody());
    }

    public function testBundledFileBrowsersAreAlternativesToCopyparty(): void
    {
        putenv('APPS_DIR=' . ROOTPATH . 'apps');
        $store = new ApplicationModel();
        $apps = array_column($store->catalog(), null, 'path');
        $paths = [
            'singleton/files/copyparty/docker-compose.yaml',
            'singleton/files/sftpgo/docker-compose.yaml',
            'singleton/files/tiny-file-manager/docker-compose.yaml',
        ];
        $running = array_fill_keys(array_column($apps, 'id'), false);
        $running[$apps[$paths[0]]['id']] = true;
        $blocks = $store->installationBlocks(array_values($apps), $running);
        foreach (array_slice($paths, 1) as $path) {
            $this->assertSame('files', $store->category($apps[$path]));
            $this->assertSame('Copyparty', $blocks[$apps[$path]['id']]);
            $this->assertNotNull($apps[$path]['website']);
            $this->assertStringStartsWith('/files/', $apps[$path]['caddyPath']);
        }
    }

    public function testDirectInstallCannotBypassOccupiedCategory(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        try {
            $this->store->install($this->apps['Second']);
            $this->fail('Installation should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('First is already running in the files category', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->directory . '/calls');
    }

    public function testOtherCategoriesAndStandardAppsRemainInstallable(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        foreach (['Player', 'Reader', 'Unlimited'] as $name) {
            $this->store->install($this->apps[$name]);
        }
        $this->assertCount(3, file($this->directory . '/calls'));
    }

    public function testLifecycleLoadsAdjacentEnvironmentForComposeInterpolation(): void
    {
        $app = $this->apps['Unlimited'];
        $environment = dirname($app['file']) . '/env';
        file_put_contents($environment, "SERVER_PORT=12345\n");
        $this->store->install($app);
        $this->store->uninstall($app);
        foreach (file($this->directory . '/calls') as $call) {
            $arguments = json_decode($call, true, 512, JSON_THROW_ON_ERROR);
            $position = array_search('--env-file', $arguments, true);
            $this->assertNotFalse($position);
            $this->assertSame($environment, $arguments[$position + 1]);
        }
    }

    public function testAppWithoutEnvironmentKeepsExistingComposeArguments(): void
    {
        $this->store->install($this->apps['Unlimited']);
        $call = json_decode(file($this->directory . '/calls')[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotContains('--env-file', $call);
    }

    public function testEnvironmentCannotEscapeAppsDirectory(): void
    {
        symlink(__FILE__, dirname($this->apps['Unlimited']['file']) . '/env');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The app env must be a readable file within the apps directory.');
        $this->store->install($this->apps['Unlimited']);
    }

    public function testEnvironmentDialogUsesTemplateCommentsAndSavedValues(): void
    {
        $app = $this->apps['Unlimited'];
        file_put_contents(dirname($app['file']) . '/env', "# Server listener\nSERVER_PORT=12345\nPASSWORD=\n");
        $fields = $this->store->environmentFields($app);
        $this->assertSame('Server listener', $fields['SERVER_PORT']['description']);
        $this->assertSame('12345', $fields['SERVER_PORT']['value']);
        $this->store->install($app, ['SERVER_PORT' => '23456', 'PASSWORD' => 'a"$secret\'\\']);
        $savedFields = (new ApplicationModel())->environmentFields($app);
        $this->assertSame('23456', $savedFields['SERVER_PORT']['value']);
        $this->assertSame('a"$secret\'\\', $savedFields['PASSWORD']['value']);
        $saved = WRITEPATH . 'app-environments/' . $app['id'] . '.env';
        $this->assertSame(0600, fileperms($saved) & 0777);
        $call = json_decode(file($this->directory . '/calls')[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains($saved, $call);
        $this->assertSame(1, count(array_filter($call, static fn (string $arg): bool => $arg === '--file')));
        $this->assertStringContainsString('SERVER_PORT=12345', file_get_contents(dirname($app['file']) . '/env'));

        $html = view('partials/app_card', [
            'app' => $app, 'isRunning' => false, 'canManage' => true, 'environmentFields' => $savedFields,
        ]);
        $this->assertStringContainsString('data-configure-app', $html);
        $this->assertStringContainsString('class="modal"', $html);
        $this->assertStringContainsString('class="modal-background"', $html);
        $this->assertStringContainsString('class="modal-card-body"', $html);
        $this->assertStringContainsString('name="environment[SERVER_PORT]"', $html);
        $this->assertStringContainsString('value="23456"', $html);
        $this->assertStringContainsString('Save and start', $html);
    }

    public function testUnknownEnvironmentVariablesCannotBeSaved(): void
    {
        $app = $this->apps['Unlimited'];
        file_put_contents(dirname($app['file']) . '/env', "SERVER_PORT=12345\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Submit exactly the variables');
        $this->store->install($app, ['SERVER_PORT' => '23456', 'OTHER' => 'unexpected']);
    }

    public function testMultilineEnvironmentValuesCannotBeSaved(): void
    {
        $app = $this->apps['Unlimited'];
        file_put_contents(dirname($app['file']) . '/env', "SERVER_PORT=12345\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('single-line text');
        $this->store->install($app, ['SERVER_PORT' => "23456\nOTHER=value"]);
    }

    public function testStoppingSingletonAllowsReplacement(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        $this->store->uninstall($this->apps['First']);
        putenv('CATEGORY_TEST_PROJECT');
        $this->store->install($this->apps['Second']);
        $this->assertCount(2, file($this->directory . '/calls'));
        $this->assertStringContainsString($this->apps['Second']['project'], file_get_contents($this->directory . '/calls'));
    }

    public function testRunningAppCannotBeRedeployed(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already running and cannot be deployed again');
        $this->store->install($this->apps['First']);
    }

    public function testConcurrentLifecycleActionFailsWithoutWaiting(): void
    {
        $handle = fopen(ROOTPATH . 'writable/cache/localhost.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $start = microtime(true);
        try {
            $this->store->install($this->apps['First']);
            $this->fail('Concurrent deployment should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Another app is being started or stopped', $exception->getMessage());
            $this->assertLessThan(1, microtime(true) - $start);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testVerboseFailureDoesNotDeadlockAndIsReported(): void
    {
        putenv('CATEGORY_TEST_VERBOSE=1');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('simulated failure');
        $this->store->install($this->apps['First']);
    }

    public function testControllerReleasesSessionDuringDockerAndPersistsResult(): void
    {
        $marker = $this->directory . '/session-lock';
        file_put_contents($marker, 'locked');
        putenv('CATEGORY_TEST_SESSION_LOCK=' . $marker);
        $session = $this->createMock(\CodeIgniter\Session\Session::class);
        $session->expects($this->exactly(2))->method('close')->willReturnCallback(
            static function () use ($marker): void {
                if (file_exists($marker)) {
                    unlink($marker);
                }
            },
        );
        $session->expects($this->once())->method('start')->willReturnCallback(
            static function () use ($marker, $session) {
                file_put_contents($marker, 'locked');
                return $session;
            },
        );
        $session->expects($this->once())->method('setFlashdata')->with('success', 'First started.');
        \CodeIgniter\Config\Services::injectMock('session', $session);
        $controller = new \App\Controllers\Home();
        $controller->initController(service('request'), service('response'), service('logger'));
        $controller->install($this->apps['First']['id']);
        $this->assertFileExists($this->directory . '/calls');
    }

    public function testCatalogGroupsAppsAndDisablesBlockedStartButton(): void
    {
        putenv('CATEGORY_TEST_PROJECT=' . $this->apps['First']['project']);
        $result = $this->withSession(['ldap_user' => [
            'username' => 'admin', 'isAdmin' => true, 'expiresAt' => time() + 3600,
        ]])->get('/category/files');
        $result->assertStatus(200);
        $html = $result->response()->getBody();
        $this->assertStringContainsString('<h2 class="title is-4">Files</h2>', $html);
        $this->assertStringContainsString('Stop First to use another app in this category.', $html);
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $second = site_url('apps/' . $this->apps['Second']['id'] . '/install');
        $unlimited = site_url('apps/' . $this->apps['Unlimited']['id'] . '/install');
        $this->assertSame(1, $xpath->query('//form[@action="' . $second . '"]/button[@disabled]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="' . $unlimited . '"]/button[not(@disabled)]')->length);
        $this->assertStringNotContainsString('Player logo', $html);
        $this->assertStringNotContainsString('Reader logo', $html);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="App categories"]//a[@aria-current="page"]')->length);
    }

    public function testCategorySelectionShowsOnlySelectedApps(): void
    {
        $result = $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->get('/category/video');
        $result->assertStatus(200);
        $html = $result->response()->getBody();
        $this->assertStringContainsString('Player logo', $html);
        $this->assertStringNotContainsString('First logo', $html);
        $this->assertStringNotContainsString('Reader logo', $html);
    }

    public function testFilesIsDefaultAndFirstSidebarCategory(): void
    {
        $result = $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->get('/');
        $result->assertStatus(200);
        $html = $result->response()->getBody();
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//nav[@aria-label="App categories"]//a');
        $this->assertSame('Files', trim($links->item(0)->textContent));
        $this->assertSame('page', $links->item(0)->getAttribute('aria-current'));
        $this->assertStringContainsString('First logo', $html);
        $this->assertStringNotContainsString('Reader logo', $html);
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]]);
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('/category/missing');
    }

    public function testSearchFindsAppsAcrossCategories(): void
    {
        $result = $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->post('catalog/search', ['search' => 'player']);
        $result->assertStatus(200);
        $html = $result->response()->getBody();
        $this->assertStringContainsString('Search results', $html);
        $this->assertStringContainsString('Player logo', $html);
        $this->assertStringNotContainsString('First logo', $html);
        $this->assertStringNotContainsString('Upload catalog ZIP', $html);
    }

    public function testSearchWithoutMatchesShowsMessage(): void
    {
        $result = $this->withSession(['ldap_user' => [
            'username' => 'reader', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]])->post('catalog/search', ['search' => 'not-in-catalog']);
        $result->assertStatus(200);
        $this->assertStringContainsString('No applications match your search.', $result->response()->getBody());
    }
}
