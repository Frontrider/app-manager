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
            'CATEGORY_TEST_SESSION_LOCK', 'CATEGORY_TEST_VERBOSE'] as $name) {
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

    public function testProjectNamesAreHumanReadable(): void
    {
        $this->assertSame('localhost-first', $this->apps['First']['project']);
        $this->assertSame('localhost-player', $this->apps['Player']['project']);
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
