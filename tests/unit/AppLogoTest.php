<?php

use App\Libraries\ApplicationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class AppLogoTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $directory;
    private array $catalogApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ROOTPATH . 'apps/.logo-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/compose.yaml', "services: {}\n");
        $id = hash('sha256', basename($this->directory) . '/compose.yaml');
        $this->catalogApp = (new ApplicationModel())->find($id);
        $this->withSession(['ldap_user' => [
            'username' => 'test-user', 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->directory . '/metadata.json')) {
            unlink($this->directory . '/metadata.json');
        }
        if (file_exists($this->directory . '/header.png')) {
            unlink($this->directory . '/header.png');
        }
        unlink($this->directory . '/compose.yaml');
        rmdir($this->directory);

        parent::tearDown();
    }

    public function testMissingHeaderUsesPlaceholder(): void
    {
        $result = $this->get('apps/' . $this->catalogApp['id'] . '/logo');

        $result->assertStatus(200);
        $this->assertStringContainsString('image/svg+xml', $result->response()->getHeaderLine('Content-Type'));
        $this->assertSame(
            file_get_contents(FCPATH . 'images/app-placeholder.svg'),
            $result->response()->getBody(),
        );
    }

    public function testAdjacentPngIsServed(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jL1kAAAAASUVORK5CYII=',
            true,
        );
        file_put_contents($this->directory . '/header.png', $png);

        $result = $this->get('apps/' . $this->catalogApp['id'] . '/logo');

        $result->assertStatus(200);
        $this->assertStringContainsString('image/png', $result->response()->getHeaderLine('Content-Type'));
        $this->assertSame($png, $result->response()->getBody());
        $this->assertSame('nosniff', $result->response()->getHeaderLine('X-Content-Type-Options'));
    }

    public function testUnknownAppReturnsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('apps/not-an-app/logo');
    }

    public function testCatalogUsesConsistentLightThemeAndLogo(): void
    {
        session()->set('ldap_user', [
            'username' => 'theme-' . bin2hex(random_bytes(6)), 'isAdmin' => false, 'expiresAt' => time() + 3600,
        ]);
        $html = view('browser', [
            'apps'    => [$this->catalogApp],
            'running' => [$this->catalogApp['id'] => false],
            'categories' => ['General' => [$this->catalogApp]],
            'selectedCategory' => 'General',
            'installationBlocks' => [],
        ]);
        session()->remove('ldap_user');

        $this->assertStringContainsString('data-theme="light"', $html);
        $this->assertStringContainsString(
            esc(site_url('apps/' . $this->catalogApp['id'] . '/logo'), 'attr'),
            $html,
        );
        $this->assertStringContainsString('class="app-logo"', $html);
        $this->assertStringContainsString('id="theme-toggle"', $html);
        $this->assertStringContainsString('type="checkbox" role="switch"', $html);
        $this->assertStringContainsString('class="theme-control"', $html);
        $this->assertStringContainsString('class="theme-track"', $html);
        $this->assertStringContainsString('class="theme-thumb"', $html);
        $this->assertStringContainsString('hx-post="' . esc(site_url('settings/dark_mode'), 'attr') . '"', $html);
        $this->assertStringContainsString(
            'hx-on::after-request="setTimeout(() => window.location.reload(), 500)"',
            $html,
        );
        $this->assertStringContainsString('transition: transform 0.5s ease', $html);
        $this->assertMatchesRegularExpression('#/styles/style\.css\?v=\d+#', $html);
        $css = file_get_contents(FCPATH . 'styles/style.css');
        $this->assertStringContainsString('color-scheme: light', $css);
        $this->assertStringContainsString(':root[data-theme="dark"]', $css);
    }

    public function testCardLinksToOfficialWebsiteWhenStoppedAndCaddyPathWhenRunning(): void
    {
        file_put_contents(
            $this->directory . '/metadata.json',
            "{\"website\":\"https://example.org/\",\"caddy_path\":\"/books/\"}\n",
        );
        $app = (new ApplicationModel())->find($this->catalogApp['id']);
        $this->assertSame('https://example.org/', $app['website']);
        $this->assertSame('/books/', $app['caddyPath']);

        $stopped = view('partials/app_card', ['app' => $app, 'isRunning' => false]);
        $this->assertStringContainsString('href="' . esc($app['website'], 'attr') . '"', $stopped);
        $this->assertStringContainsString('Official website', $stopped);
        $this->assertStringNotContainsString('Open app', $stopped);

        $running = view('partials/app_card', ['app' => $app, 'isRunning' => true]);
        $this->assertStringContainsString('href="' . esc('/books/', 'attr') . '"', $running);
        $this->assertStringContainsString('Open app', $running);
        $this->assertStringContainsString('Official website', $running);
    }

    public function testMetadataIsOptional(): void
    {
        $this->assertNull($this->catalogApp['website']);
        $this->assertNull($this->catalogApp['caddyPath']);
        $html = view('partials/app_card', ['app' => $this->catalogApp, 'isRunning' => false]);
        $this->assertStringNotContainsString('Official website', $html);
    }

    public function testMetadataCannotLinkToExecutableUrl(): void
    {
        file_put_contents($this->directory . '/metadata.json', '{"website":"javascript:alert(1)"}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('website must be an HTTP or HTTPS URL');
        (new ApplicationModel())->find($this->catalogApp['id']);
    }

    public function testInvalidMetadataJsonIsRejected(): void
    {
        file_put_contents($this->directory . '/metadata.json', '{"website":');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not parse app metadata');
        (new ApplicationModel())->find($this->catalogApp['id']);
    }

    public function testDependenciesAreLoadedFromMetadata(): void
    {
        file_put_contents(
            $this->directory . '/metadata.json',
            '{"dependencies":[{"name":"first","category":"files","required":false}]}',
        );
        $app = (new ApplicationModel())->find($this->catalogApp['id']);
        $this->assertSame([
            ['name' => 'first', 'category' => 'files', 'required' => false],
        ], $app['dependencies']);
    }

    public function testInvalidDependencyMetadataIsRejected(): void
    {
        file_put_contents(
            $this->directory . '/metadata.json',
            '{"dependencies":[{"name":"first","category":"files","required":"yes"}]}',
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dependency required must be true or false');
        (new ApplicationModel())->find($this->catalogApp['id']);
    }

    public function testCaddyPathCannotLinkToAnotherDomain(): void
    {
        file_put_contents($this->directory . '/metadata.json', '{"caddy_path":"//example.org/"}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caddy_path must be a same-domain subpath');
        (new ApplicationModel())->find($this->catalogApp['id']);
    }

    public function testMetadataErrorsDoNotExposeInternalPaths(): void
    {
        file_put_contents($this->directory . '/metadata.json', '{"caddy_path":"//example.org/"}');

        try {
            (new ApplicationModel())->find($this->catalogApp['id']);
            $this->fail('Invalid metadata should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString($this->directory, $exception->getMessage());
            $this->assertStringNotContainsString('metadata.json', $exception->getMessage());
        }
    }
}
