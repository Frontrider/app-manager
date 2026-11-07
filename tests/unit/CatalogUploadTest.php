<?php

use App\Libraries\AppStore;
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
        $relative = 'standard/sharing/' . $name;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->archive, ZipArchive::OVERWRITE));
        $zip->addFromString('apps/' . $relative . '/compose.yaml', 'Compose contents are not parsed during upload.');
        $zip->addFromString('apps/' . $relative . '/env', "# Listener\nSERVER_PORT=12345\n");
        $zip->addFromString('apps/' . $relative . '/metadata.properties', "website=https://example.org/\n");
        $zip->addFromString('apps/' . $relative . '/settings.yml', "not a catalog item\n");
        $zip->close();

        $store = new AppStore();
        $this->assertSame(1, $store->importCatalog($this->archive));
        $app = $store->find(hash('sha256', $relative . '/compose.yaml'));
        $this->importedDirectory = dirname($app['file'], 4);
        $this->assertStringStartsWith(WRITEPATH . 'catalog/', $this->importedDirectory);
        $this->assertSame('sharing', $store->category($app));
        $this->assertSame('12345', $store->environmentFields($app)['SERVER_PORT']['value']);
        $this->assertCount(1, array_filter($store->catalog(), static fn (array $item): bool =>
            str_starts_with($item['path'], $relative . '/')));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not replace existing catalog items');
        $store->importCatalog($this->archive);
    }

    public function testTraversalIsRejected(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->archive, ZipArchive::OVERWRITE);
        $zip->addFromString('standard/sharing/app/../../../../escape', 'unsafe');
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe paths');
        (new AppStore())->importCatalog($this->archive);
    }

    public function testSymlinksAreRejected(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->archive, ZipArchive::OVERWRITE);
        $zip->addFromString('standard/sharing/app/link', '/etc/passwd');
        $zip->setExternalAttributesName('standard/sharing/app/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not links');
        (new AppStore())->importCatalog($this->archive);
    }
}
