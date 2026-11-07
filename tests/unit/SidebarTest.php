<?php

use App\Libraries\AppStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class SidebarTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string|false $previousDocker;
    private string|false $previousProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDocker = getenv('DOCKER_BIN');
        $this->previousProject = getenv('SIDEBAR_TEST_PROJECT');
        putenv('DOCKER_BIN=' . ROOTPATH . 'tests/_support/Fixtures/sidebar-docker.php');
        putenv('SIDEBAR_TEST_PROJECT');
        $this->withSession([]);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDocker === false ? 'DOCKER_BIN' : 'DOCKER_BIN=' . $this->previousDocker);
        putenv($this->previousProject === false ? 'SIDEBAR_TEST_PROJECT' : 'SIDEBAR_TEST_PROJECT=' . $this->previousProject);
        parent::tearDown();
    }

    public function testSidebarIsPublicAndContainsOnlyRunningAppLinks(): void
    {
        $apps = (new AppStore())->catalog();
        $this->assertNotEmpty($apps);
        $webApps = array_values(array_filter($apps, static fn (array $app): bool => $app['caddyPath'] !== null));
        $this->assertNotEmpty($webApps);
        $app = $webApps[0];
        putenv('SIDEBAR_TEST_PROJECT=' . $app['project']);

        $result = $this->get('sidebar');
        $result->assertStatus(200);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('application/json', $result->response()->getHeaderLine('Content-Type'));
        $body = $result->response()->getBody();
        $this->assertSame(
            [['name' => $app['name'], 'href' => $app['caddyPath']]],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<html', $body);
    }

    public function testNoRunningAppsProducesEmptyLinks(): void
    {
        $result = $this->get('sidebar');
        $result->assertStatus(200);
        $this->assertSame([], json_decode($result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRunningGameServerWithoutWebInterfaceIsOmitted(): void
    {
        $apps = array_column((new AppStore())->catalog(), null, 'path');
        $app = $apps['standard/games/mindustry/docker-compose.yaml'];
        $this->assertNull($app['caddyPath']);
        putenv('SIDEBAR_TEST_PROJECT=' . $app['project']);

        $result = $this->get('sidebar');
        $result->assertStatus(200);
        $this->assertSame([], json_decode($result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
