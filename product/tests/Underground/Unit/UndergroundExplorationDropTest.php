<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentDropService;
use Tests\TestCase;

final class UndergroundExplorationDropTest extends TestCase
{
    public function test_hunting_ground_item_levels_follow_the_generator_range_instead_of_a_stale_sixty_cap(): void
    {
        config([
            'underground-alpha-v1.exploration.grounds.black_crystal_cave.item_level_max' => 90,
            'underground-alpha-v1.exploration.grounds.black_crystal_cave.encounters.black_crystal_bug.item_level_max' => 90,
        ]);

        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);

        $this->assertSame(90, $catalog->explorationHuntingGround('black_crystal_cave')['item_level_max']);
        $this->assertSame(90, collect($catalog->explorationEncounters('black_crystal_cave'))
            ->firstWhere('key', 'black_crystal_bug')['item_level_max']);
    }

    public function test_drop_roll_is_deterministic_domain_separated_and_uses_runtime_generator_payload(): void
    {
        config([
            'underground-alpha-v1.exploration.drop.profiles.standard.presence_bps' => 10_000,
            'underground-alpha-v1.exploration.drop.profiles.standard.rarity_weights' => [
                'common' => 10_000,
                'uncommon' => 0,
                'rare' => 0,
                'epic' => 0,
            ],
        ]);
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $encounter = $catalog->explorationEncounter('subterranean_rat', 'shallow_caves');
        $service = app(UndergroundEquipmentDropService::class);

        $first = $service->roll('shallow_caves', $encounter, 12345, 'drop-unit-source');
        $replay = $service->roll('shallow_caves', $encounter, 12345, 'drop-unit-source');
        $otherSource = $service->roll('shallow_caves', $encounter, 12345, 'drop-unit-source-2');

        $this->assertSame($first, $replay);
        $this->assertSame('generated', $first['status']);
        $this->assertSame('common', $first['payload']['rarity']);
        $this->assertGreaterThanOrEqual(5, $first['payload']['item_level']);
        $this->assertLessThanOrEqual(15, $first['payload']['item_level']);
        $this->assertContains($first['payload']['category'], ['weapon', 'armor', 'accessory']);
        $this->assertNull($first['payload']['unique_effect']);
        $this->assertNotSame($first['payload']['instance_identity'], $otherSource['payload']['instance_identity']);
        $this->assertSame(
            collect($first['payload'])->except(['instance_identity', 'source'])->all(),
            collect($otherSource['payload'])->except(['instance_identity', 'source'])->all(),
        );
    }
}
