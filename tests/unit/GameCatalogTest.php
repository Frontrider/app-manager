<?php

use App\Libraries\ApplicationModel;
use CodeIgniter\Test\CIUnitTestCase;

final class GameCatalogTest extends CIUnitTestCase
{
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
