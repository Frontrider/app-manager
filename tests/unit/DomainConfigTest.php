<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

final class DomainConfigTest extends CIUnitTestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = WRITEPATH . 'cache/domain-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->environment = [];
        foreach (['DOMAIN_DIRECTORY', 'APP_SCHEME', 'app_baseURL', 'app.baseURL', 'CADDY_PROXY_HOST'] as $key) {
            $this->environment[$key] = getenv($key);
            putenv($key);
        }
        putenv('DOMAIN_DIRECTORY=' . $this->directory);
    }

    protected function tearDown(): void
    {
        foreach (['domain', 'DOMAIN'] as $name) {
            if (file_exists($this->directory . '/' . $name)) {
                unlink($this->directory . '/' . $name);
            }
        }
        rmdir($this->directory);
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        parent::tearDown();
    }

    public function testDomainIsLoadedAndUpdatesAreRead(): void
    {
        file_put_contents($this->directory . '/domain', "example.org\n");
        $this->assertSame('https://example.org/', (new App())->baseURL);
        file_put_contents($this->directory . '/domain', 'changed.example.org');
        $this->assertSame('https://changed.example.org/', (new App())->baseURL);
    }

    public function testUppercaseDomainFileIsSupported(): void
    {
        file_put_contents($this->directory . '/DOMAIN', 'example.org');
        $this->assertSame('https://example.org/', (new App())->baseURL);
    }

    public function testMissingDomainFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or unreadable');
        new App();
    }

    public function testInvalidDomainFailsExplicitly(): void
    {
        file_put_contents($this->directory . '/domain', 'https://example.org/path');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid');
        new App();
    }

    public function testExplicitUrlOverridesDomainFile(): void
    {
        putenv('app_baseURL=https://override.example.org/');
        $this->assertSame('https://override.example.org/', (new App())->baseURL);
    }

    public function testCaddyAddressIsTrustedWithoutTrustingEntireNetwork(): void
    {
        file_put_contents($this->directory . '/domain', 'example.org');
        putenv('CADDY_PROXY_HOST=127.0.0.1');
        $this->assertSame(['127.0.0.1' => 'X-Forwarded-For'], (new App())->proxyIPs);
    }

    public function testCaddyHostnameIsResolved(): void
    {
        file_put_contents($this->directory . '/domain', 'example.org');
        putenv('CADDY_PROXY_HOST=localhost');
        $this->assertArrayHasKey('127.0.0.1', (new App())->proxyIPs);
    }
}
