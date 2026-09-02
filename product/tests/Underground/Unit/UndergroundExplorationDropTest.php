<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentDropService;
use Tests\TestCase;

final class UndergroundExplorationDropTest extends TestCase
{
    public function test_hunting_grounds_preserve_shallow_balance_and_define_exact_black_crystal_rewards(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $grounds = collect($catalog->explorationHuntingGrounds())->keyBy('key');
        $this->assertSame(['shallow_caves', 'black_crystal_cave'], $grounds->keys()->all());
        $this->assertSame('secretary-underground-exploration-alpha-v1', $grounds['shallow_caves']['content_identity']);
        $this->assertSame('trial_01', $grounds['black_crystal_cave']['required_trial_key']);
        $this->assertSame([30, 60], [
            $grounds['black_crystal_cave']['item_level_min'],
            $grounds['black_crystal_cave']['item_level_max'],
        ]);

        $shallow = collect($catalog->explorationEncounters('shallow_caves'))->keyBy('key');
        $this->assertSame([2500, 2500, 2000, 1000, 1000, 900, 100], $shallow->pluck('weight')->values()->all());
        $this->assertSame(10_000, $shallow->sum('weight'));

        $black = collect($catalog->explorationEncounters('black_crystal_cave'))->keyBy('key');
        $this->assertSame([
            'black_crystal_bat',
            'black_crystal_beast',
            'crystal_shell_bug',
            'black_crystal_mage',
            'crystal_berserker',
            'black_crystal_regenerator',
            'black_crystal_warden',
            'black_crystal_bug',
        ], $black->keys()->all());
        $this->assertSame([2500, 2000, 1500, 1000, 1200, 800, 900, 100], $black->pluck('weight')->values()->all());
        $this->assertSame([145, 160, 170, 185, 280, 330, 600, 1400], $black->pluck('xp')->values()->all());
        $this->assertSame([37, 42, 47, 55, 78, 98, 157, 0], $black->pluck('shards')->values()->all());
        $this->assertSame([
            [30, 38], [30, 40], [32, 40], [34, 40],
            [35, 47], [40, 50], [45, 60], [50, 60],
        ], $black->map(static fn (array $encounter): array => [
            $encounter['item_level_min'],
            $encounter['item_level_max'],
        ])->values()->all());
        $this->assertSame(10_000, $black->sum('weight'));
        $this->assertEqualsWithDelta(240.25, $black->sum(
            static fn (array $encounter): int => $encounter['weight'] * $encounter['xp'],
        ) / 10_000, 0.0001);
        $this->assertEqualsWithDelta(61.53, $black->sum(
            static fn (array $encounter): int => $encounter['weight'] * $encounter['shards'],
        ) / 10_000, 0.0001);
        $this->assertEqualsWithDelta(160.3571, $black->where('drop_profile', 'standard')->sum(
            static fn (array $encounter): int => $encounter['weight'] * $encounter['xp'],
        ) / 7000, 0.0001);
        $this->assertSame([7000, 2900, 100], [
            $black->where('drop_profile', 'standard')->sum('weight'),
            $black->where('drop_profile', 'elite')->sum('weight'),
            $black->where('drop_profile', 'rare')->sum('weight'),
        ]);

        $blackConfig = config('underground-alpha-v1.exploration.grounds.black_crystal_cave.encounters');
        $this->assertIsArray($blackConfig);
        $this->assertSame([
            [18, 26, 22, 8, 26, 1400, 100, 90, 125],
            [24, 32, 18, 12, 14, 1650, 125, 110, 135],
            [40, 22, 8, 20, 10, 1950, 200, 125, 125],
            [18, 10, 20, 42, 10, 1550, 100, 190, 140],
            [30, 40, 10, 10, 10, 2300, 165, 140, 170],
            [42, 22, 8, 18, 10, 2800, 180, 165, 160],
            [36, 34, 10, 10, 10, 3700, 240, 215, 215],
            [20, 5, 5, 60, 10, 1, 0, 0, 1],
        ], array_values(array_map(static fn (array $entry): array => [
            $entry['enemy']['base_stats']['vitality'],
            $entry['enemy']['base_stats']['might'],
            $entry['enemy']['base_stats']['finesse'],
            $entry['enemy']['base_stats']['spirit'],
            $entry['enemy']['base_stats']['agility'],
            $entry['enemy']['max_hp'],
            $entry['enemy']['physical_defense'],
            $entry['enemy']['magical_defense'],
            $entry['enemy']['weapon_power'],
        ], $blackConfig)));
    }

    public function test_drop_profiles_have_exact_aggregate_rarity_rates_and_category_weights(): void
    {
        $drop = app(UndergroundAlphaV1PlayerCatalog::class)->explorationDropConfig();
        $this->assertSame('secretary-underground-exploration-drop-alpha-v1', $drop['identity']);
        $this->assertSame([2000, 2000, 6000], array_values($drop['category_weights']));
        $this->assertSame([
            'standard' => [
                'presence_bps' => 2006,
                'rarity_weights' => ['common' => 9118, 'uncommon' => 683, 'rare' => 194, 'epic' => 5],
            ],
            'elite' => [
                'presence_bps' => 3815,
                'rarity_weights' => ['common' => 7864, 'uncommon' => 1573, 'rare' => 524, 'epic' => 39],
            ],
            'rare' => [
                'presence_bps' => 10_000,
                'rarity_weights' => ['common' => 5000, 'uncommon' => 3000, 'rare' => 1500, 'epic' => 500],
            ],
        ], $drop['profiles']);

        $encounterMix = ['standard' => 7000, 'elite' => 2900, 'rare' => 100];
        $aggregate = array_fill_keys(['common', 'uncommon', 'rare', 'epic'], 0.0);
        foreach ($encounterMix as $profileKey => $encounterWeight) {
            $profile = $drop['profiles'][$profileKey];
            foreach ($profile['rarity_weights'] as $rarity => $rarityWeight) {
                $aggregate[$rarity] += ($encounterWeight / 10_000)
                    * ($profile['presence_bps'] / 10_000)
                    * ($rarityWeight / 10_000);
            }
        }

        $this->assertEqualsWithDelta(0.22004, $aggregate['common'], 0.00001);
        $this->assertEqualsWithDelta(0.02999, $aggregate['uncommon'], 0.00001);
        $this->assertEqualsWithDelta(0.01002, $aggregate['rare'], 0.00001);
        $this->assertEqualsWithDelta(0.00100, $aggregate['epic'], 0.00001);
        $this->assertEqualsWithDelta(0.26105, array_sum($aggregate), 0.00001);
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
