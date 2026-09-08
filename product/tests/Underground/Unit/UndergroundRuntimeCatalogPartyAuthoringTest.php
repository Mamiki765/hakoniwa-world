<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundRuntimeCatalog;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundRuntimeCatalogPartyAuthoringTest extends TestCase
{
    public function test_hunting_ground_enemy_counts_are_authored_per_party_size_without_touching_rewards(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);

        $this->assertSame([1, 2, 3, 4], [
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 1),
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 2),
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 3),
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 4),
        ]);
        $this->assertSame(4, $catalog->explorationEnemyCountForPartySize('black_crystal_cave', 4));
        $this->assertSame(
            ['xp' => 36, 'shards' => 10],
            array_intersect_key(
                $catalog->explorationEncounter('subterranean_rat', 'shallow_caves'),
                ['xp' => true, 'shards' => true],
            ),
        );
    }

    public function test_party_boss_scaling_supports_none_and_content_authored_tables(): void
    {
        $catalog = app(UndergroundRuntimeCatalog::class);

        $this->assertSame(
            ['mode' => 'none', 'hp_bps' => 10_000, 'attack_bps' => 10_000],
            $catalog->resolvePartyBossScaling(['mode' => 'none'], 4),
        );
        $this->assertSame(
            ['mode' => 'table', 'hp_bps' => 13_000, 'attack_bps' => 11_000],
            $catalog->resolvePartyBossScaling([
                'mode' => 'table',
                'hp_bps' => [1 => 10_000, 2 => 11_000, 3 => 12_000, 4 => 13_000],
                'attack_bps' => [1 => 10_000, 2 => 10_000, 3 => 10_500, 4 => 11_000],
            ], 4),
        );
    }

    public function test_party_boss_scaling_rejects_missing_or_malformed_table(): void
    {
        $catalog = app(UndergroundRuntimeCatalog::class);

        $this->expectException(InvalidArgumentException::class);
        $catalog->resolvePartyBossScaling([
            'mode' => 'table',
            'hp_bps' => [1 => 10_000],
            'attack_bps' => [1 => 10_000, 2 => 10_000, 3 => 10_000, 4 => 10_000],
        ], 1);
    }
}
