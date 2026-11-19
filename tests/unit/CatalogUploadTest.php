<?php

use App\Libraries\ApplicationModel;
use CodeIgniter\Test\CIUnitTestCase;

final class CatalogUploadTest extends CIUnitTestCase
{
    private string $archive;
    private ?string $importedDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is required.');
        }
        $this->archive = tempnam(sys_get_temp_dir(), 'catalog-upload-test-');
    }

    protected function tearDown(): void
    {
        if (isset($this->archive) && is_file($this->archive)) {
            unlink($this->archive);
        }
        if ($this->importedDirectory !== null) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->importedDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->importedDirectory);
        }
        parent::tearDown();
    }

    public function testZipStructurePersistsAndDoesNotReadComposeForConfiguration(): void
    {
        $name = 'uploaded-' . bin2hex(random_bytes(8));
        $relative = 'sharing/' . $name;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->archive, ZipArchive::OVERWRITE));
        $zip->addFromString('apps/' . $relative . '/compose.yaml', 'Compose contents are not parsed during upload.');
        $zip->addFromString('apps/' . $relative . '/env', "# Listener\nSERVER_PORT=12345\n");
        $zip->addFromString('apps/' . $relative . '/metadata.json', "{\"website\":\"https://example.org/\"}\n");
        $zip->addFromString('apps/' . $relative . '/settings.yml', "not a catalog item\n");
        $zip->close();

        $store = new ApplicationModel();
        $this->assertSame(1, $store->importCatalog($this->archive));
        $app = $store->find(hash('sha256', $relative . '/compose.yaml'));
        $this->importedDirectory = dirname($app['file'], 3);
        $this->assertStringStartsWith(WRITEPATH . 'catalog/', $this->importedDirectory);
        $this->assertSame('sharing', $store->category($app));
        $this->assertSame('12345', $store->environmentFields($app)['SERVER_PORT']['value']);
        $this->assertCount(1, array_filter($store->catalog(), static fn (array $item): bool =>
            str_starts_with($item['path'], $relative . '/')));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not replace existing catalog items');
        $store->importCatalog($this->archive);
    }

    public function testComposePasteCreatesOtherAppWithMinimalMetadata(): void
    {
        $name = 'Pasted App ' . bin2hex(random_bytes(8));
        $compose = "services:\n  web:\n    image: nginx:alpine\n";
        $store = new ApplicationModel();

        $relative = $store->importCompose($name, 'pasted-app', $compose);
        $app = $store->find(hash('sha256', $relative));
        $this->importedDirectory = dirname($app['file'], 3);

        $this->assertSame($name, $app['name']);
        $this->assertSame('/pasted-app/', $app['caddyPath']);
        $this->assertSame('other', $store->category($app));
        $this->assertSame($compose, file_get_contents($app['file']));
        $this->assertSame(
            ['name' => $name, 'caddy_path' => '/pasted-app/'],
            json_decode(file_get_contents(dirname($app['file']) . '/metadata.json'), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testDuplicatePastedComposeNamesAreRejected(): void
    {
        $name = 'Duplicate App ' . bin2hex(random_bytes(8));
        $store = new ApplicationModel();
        $relative = $store->importCompose($name, '/duplicate-app/', "services: {}\n");
        $app = $store->find(hash('sha256', $relative));
        $this->importedDirectory = dirname($app['file'], 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $store->importCompose($name, '/another-path/', "services: {}\n");
    }

    public function testTraversalIsRejected(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->archive, ZipArchive::OVERWRITE);
        $zip->addFromString('sharing/app/../../../../escape', 'unsafe');
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe paths');
        (new ApplicationModel())->importCatalog($this->archive);
    }

    public function testSymlinksAreRejected(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->archive, ZipArchive::OVERWRITE);
        $zip->addFromString('sharing/app/link', '/etc/passwd');
        $zip->setExternalAttributesName('sharing/app/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not links');
        (new ApplicationModel())->importCatalog($this->archive);
    }
}
