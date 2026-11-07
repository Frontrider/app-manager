<?php

use App\Libraries\ApplicationModel;
use CodeIgniter\Test\CIUnitTestCase;

final class GameCatalogTest extends CIUnitTestCase
{
    public function testMediaMtxIsAnIndependentVideoBridge(): void
    {
        $store = new ApplicationModel();
        $apps = array_column($store->catalog(), null, 'path');
        $app = $apps['standard/video/obs-bridge/docker-compose.yaml'];
        $this->assertSame('video', $store->category($app));
        $this->assertSame('/obs-bridge/bridge/', $app['caddyPath']);
        $this->assertNotNull($app['website']);
        $running = array_fill_keys(array_column($apps, 'id'), true);
        $this->assertArrayNotHasKey($app['id'], $store->installationBlocks(array_values($apps), $running));
        $settings = json_decode(
            file_get_contents(dirname($app['file']) . '/mediamtx.json'), true, 512, JSON_THROW_ON_ERROR,
        );
        $this->assertSame(
            [['action' => 'publish', 'path' => 'bridge']],
            $settings['authInternalUsers'][0]['permissions'],
        );
        $this->assertSame(
            [['action' => 'read', 'path' => 'bridge']],
            $settings['authInternalUsers'][1]['permissions'],
        );
        $this->assertFalse($settings['paths']['bridge']['overridePublisher']);
        foreach (['rtsp', 'webrtc', 'srt', 'api', 'metrics', 'pprof', 'playback'] as $protocol) {
            $this->assertFalse($settings[$protocol]);
        }
        $this->assertTrue($settings['rtmp']);
        $this->assertTrue($settings['hls']);
        $this->assertSame(':8888', $settings['hlsAddress']);
        $compose = file_get_contents($app['file']);
        $this->assertStringContainsString(
            'caddy.10_handle_path.reverse_proxy.header_down=Location ^(/.*) /obs-bridge$${1}',
            $compose,
        );
        $this->assertStringContainsString('source: "${MEDIAMTX_CONFIG_FILE:-/dev/null}"', $compose);
        $this->assertStringContainsString('target: /custom-mediamtx.yml', $compose);
        $this->assertStringContainsString('create_host_path: false', $compose);
        $this->assertStringContainsString('read_only: true', $compose);
        $this->assertStringContainsString('user: "1000:1000"', $compose);
        $this->assertStringContainsString(
            "      - type: volume\n        source: data\n        target: /Data\n        read_only: true",
            $compose,
        );
        $this->assertStringContainsString(
            "  data:\n    external: true\n" . '    name: "${DATA_VOLUME:-localhost_data}"',
            $compose,
        );
        $this->assertSame(['SERVER_PORT', 'PUBLISH_PASSWORD', 'READ_PASSWORD', 'MEDIAMTX_CONFIG_FILE'],
            array_keys($store->environmentFields($app)));
    }

    public function testCatalogCaddyMatchersAndForwardAuthLabelsAreComplete(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOTPATH . 'apps', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getFilename() !== 'docker-compose.yaml') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $this->assertNotFalse($contents);
            preg_match_all('/^\s+(caddy(?:_\d+)?\.[^:\s]+):\s*(.+)$/m', $contents, $matches, PREG_SET_ORDER);
            preg_match_all('/^\s+-\s+["\']?(caddy(?:_\d+)?\.[^=\s]+)=(.+)$/m', $contents, $listMatches, PREG_SET_ORDER);
            $matches = array_merge($matches, $listMatches);
            $labels = [];
            $matchers = [];
            foreach ($matches as $match) {
                $labels[$match[1]] = $match[2];
                if (preg_match('/^(caddy(?:_\d+)?)\.(@[\w-]+)\./', $match[1], $definition)) {
                    $matchers[$definition[1]][$definition[2]] = true;
                }
            }
            foreach ($labels as $key => $value) {
                $root = explode('.', $key)[0];
                preg_match_all('/@[\w-]+/', $value, $references);
                foreach ($references[0] as $matcher) {
                    $this->assertTrue(
                        isset($matchers[$root][$matcher]),
                        $file->getPathname() . ': undefined Caddy matcher ' . $matcher,
                    );
                }
                if (str_ends_with($key, '_forward_auth')) {
                    $this->assertArrayHasKey($key . '.uri', $labels, $file->getPathname());
                    $this->assertArrayHasKey($key . '.copy_headers', $labels, $file->getPathname());
                    $this->assertStringContainsString('/auth/api/authz/forward-auth', $labels[$key . '.uri']);
                }
            }
        }
    }

    public function testRqbitListenerPortsUseInterpolatableLabelValues(): void
    {
        $contents = file_get_contents(ROOTPATH . 'apps/standard/sharing/rqbit/docker-compose.yaml');
        $this->assertStringContainsString(
            '- "caddy_1.layer4.:${SERVER_PORT}.route.proxy={{upstreams ${SERVER_PORT}}}"',
            $contents,
        );
        $this->assertStringContainsString(
            '- "caddy_1.layer4.udp/:${SERVER_PORT}.route.proxy=udp/{{upstreams ${SERVER_PORT}}}"',
            $contents,
        );
        $this->assertStringNotContainsString('"caddy_1.layer4.:${SERVER_PORT}.route.proxy":', $contents);
    }

    public function testSyncthingLayer4RootAndKomgaClaimHandler(): void
    {
        $syncthing = file_get_contents(ROOTPATH . 'apps/standard/sharing/syncthing/docker-compose.yaml');
        $this->assertStringContainsString("caddy_1: ''", $syncthing);
        $this->assertStringContainsString("'caddy_1.layer4.:22000.route.proxy': '{{upstreams 22000}}'", $syncthing);
        $this->assertStringContainsString("'caddy_1.layer4.udp/:22000.route.proxy': 'udp/{{upstreams 22000}}'", $syncthing);
        $komga = file_get_contents(ROOTPATH . 'apps/singleton/books/komga/docker-compose.yaml');
        $this->assertStringNotContainsString('caddy.5_handle', $komga);
        $this->assertStringContainsString("caddy.6_handle.0_respond: '@komga_claim Forbidden 403'", $komga);
        $this->assertStringNotContainsString('forward_auth', $komga);
    }

    public function testAllRequestedGamesAreIndependentCatalogEntries(): void
    {
        $store = new ApplicationModel();
        $games = array_values(array_filter(
            $store->catalog(),
            static fn (array $app): bool => str_starts_with($app['path'], 'standard/games/'),
        ));
        $folders = array_map(static fn (array $app): string => basename(dirname($app['file'])), $games);
        sort($folders);
        $this->assertSame([
            '0-ad', 'bedrockconnect', 'freeorion', 'geysermc', 'hytale', 'ioquake3',
            'mindustry', 'minecraft', 'openarena', 'openttd', 'shapez-io', 'valheim',
        ], $folders);

        $running = array_fill_keys(array_column($games, 'id'), true);
        $this->assertSame([], $store->installationBlocks($games, $running));
        foreach ($games as $game) {
            $this->assertSame('games', $store->category($game));
            $this->assertNotNull($game['website']);
            $this->assertSame(
                basename(dirname($game['file'])) === 'shapez-io' ? '/shapez/' : null,
                $game['caddyPath'],
            );
        }
    }
}
