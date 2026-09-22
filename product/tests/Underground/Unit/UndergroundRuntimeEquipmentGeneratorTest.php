<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundRuntimeEquipmentGeneratorTest extends TestCase
{
    public function test_resonance_duplicate_max_rolls_stop_percentage_growth_but_keep_equal_flat_stat_growth(): void
    {
        config([
            'underground-equipment.generator.resonance.affixes' => ['resonance_area_damage_bps' => '範囲攻撃強化'],
            'underground-equipment.generator.quality_min_bps' => 10_000,
            'underground-equipment.generator.quality_max_bps' => 10_000,
        ]);
        $atCap = $this->generate(200, 'bahamul', 'unique', 'resonance', null, null, 23);
        $afterCap = $this->generate(210, 'bahamul', 'unique', 'resonance', null, null, 23);
        app(UndergroundEquipmentCatalog::class)->assertDefinition($afterCap, true);

        $this->assertCount(2, $atCap['affixes']);
        $this->assertSame($atCap['affixes'][0]['key'], $atCap['affixes'][1]['key']);
        $this->assertSame(3_000, $afterCap['modifiers']['resonance_area_damage_bps']);
        $this->assertSame($atCap['affixes'], $afterCap['affixes']);
        $this->assertSame($atCap['unique_effect'], $afterCap['unique_effect']);
        $this->assertCount(1, array_unique($afterCap['stats']));
        $this->assertGreaterThan($atCap['stats']['vitality'], $afterCap['stats']['vitality']);

        $weapon = $this->generate(210, 'bahamul', 'unique', 'weapon', 'longsword', null, 23);
        app(UndergroundEquipmentCatalog::class)->assertDefinition($weapon, true);
        $this->assertCount(3, $weapon['affixes']);
        $this->assertSame('shockwave', $weapon['unique_effect']['type']);
    }

    public function test_same_input_and_seed_replay_the_same_generated_payload(): void
    {
        $first = $this->generate(40, 'black_crystal_cave', 'epic', 'weapon', 'dagger', null, 3);
        $retry = $this->generate(40, 'black_crystal_cave', 'epic', 'weapon', 'dagger', null, 3);

        $this->assertSame($first, $retry);
        $this->assertSame(64, strlen($first['instance_identity']));
        $otherSource = (new UndergroundRuntimeEquipmentGenerator)->generate(
            40, 'black_crystal_cave', 'epic', 'weapon', 'dagger', null, 3, 'other-source',
        );
        $this->assertNotSame($first['instance_identity'], $otherSource['instance_identity']);
        $this->assertSame($first['affixes'], $otherSource['affixes']);
    }

    public function test_body_anchors_are_exposed_before_affix_application(): void
    {
        $cases = [
            'dagger' => [
                'category' => 'weapon',
                'weapon_style' => 'dagger',
                'main_stat' => null,
                'anchors' => [
                    1 => self::base(30, 0, 0, 0, self::stats(finesse: 3, agility: 2)),
                    10 => self::base(44, 0, 0, 0, self::stats(finesse: 6, agility: 4)),
                    20 => self::base(60, 0, 0, 0, self::stats(finesse: 10, agility: 7)),
                    30 => self::base(80, 0, 0, 0, self::stats(finesse: 13, agility: 9)),
                    40 => self::base(100, 0, 0, 0, self::stats(finesse: 15, agility: 10)),
                    50 => self::base(120, 0, 0, 0, self::stats(finesse: 18, agility: 12)),
                    60 => self::base(140, 0, 0, 0, self::stats(finesse: 20, agility: 13)),
                    70 => self::base(160, 0, 0, 0, self::stats(finesse: 22, agility: 15)),
                    80 => self::base(180, 0, 0, 0, self::stats(finesse: 24, agility: 16)),
                    90 => self::base(200, 0, 0, 0, self::stats(finesse: 26, agility: 18)),
                ],
            ],
            'rapier' => [
                'category' => 'weapon',
                'weapon_style' => 'rapier',
                'main_stat' => null,
                'anchors' => [
                    1 => self::base(34, 0, 0, 0, self::stats(might: 2, finesse: 2)),
                    10 => self::base(50, 0, 0, 0, self::stats(might: 5, finesse: 4)),
                    20 => self::base(68, 0, 0, 0, self::stats(might: 8, finesse: 6)),
                    30 => self::base(90, 0, 0, 0, self::stats(might: 10, finesse: 8)),
                    40 => self::base(112, 0, 0, 0, self::stats(might: 12, finesse: 9)),
                    50 => self::base(134, 0, 0, 0, self::stats(might: 14, finesse: 11)),
                    60 => self::base(156, 0, 0, 0, self::stats(might: 16, finesse: 12)),
                    70 => self::base(178, 0, 0, 0, self::stats(might: 18, finesse: 14)),
                    80 => self::base(200, 0, 0, 0, self::stats(might: 20, finesse: 15)),
                    90 => self::base(222, 0, 0, 0, self::stats(might: 22, finesse: 17)),
                ],
            ],
            'longsword' => [
                'category' => 'weapon',
                'weapon_style' => 'longsword',
                'main_stat' => null,
                'anchors' => [
                    1 => self::base(31, 4, 0, 0, self::stats(vitality: 3, might: 2)),
                    10 => self::base(46, 8, 0, 0, self::stats(vitality: 6, might: 4)),
                    20 => self::base(62, 14, 0, 0, self::stats(vitality: 10, might: 6)),
                    30 => self::base(82, 20, 0, 0, self::stats(vitality: 13, might: 8)),
                    40 => self::base(102, 26, 0, 0, self::stats(vitality: 15, might: 9)),
                    50 => self::base(122, 33, 0, 0, self::stats(vitality: 18, might: 11)),
                    60 => self::base(142, 40, 0, 0, self::stats(vitality: 20, might: 12)),
                    70 => self::base(162, 48, 0, 0, self::stats(vitality: 22, might: 14)),
                    80 => self::base(182, 56, 0, 0, self::stats(vitality: 24, might: 15)),
                    90 => self::base(202, 64, 0, 0, self::stats(vitality: 26, might: 17)),
                ],
            ],
            'crystal_staff' => [
                'category' => 'weapon',
                'weapon_style' => 'crystal_staff',
                'main_stat' => null,
                'anchors' => [
                    1 => self::base(26, 0, 0, 0, self::stats(finesse: 1, spirit: 4)),
                    10 => self::base(38, 0, 0, 0, self::stats(finesse: 2, spirit: 8)),
                    20 => self::base(52, 0, 0, 0, self::stats(finesse: 3, spirit: 13)),
                    30 => self::base(70, 0, 0, 0, self::stats(finesse: 4, spirit: 17)),
                    40 => self::base(88, 0, 0, 0, self::stats(finesse: 5, spirit: 20)),
                    50 => self::base(106, 0, 0, 0, self::stats(finesse: 6, spirit: 24)),
                    60 => self::base(124, 0, 0, 0, self::stats(finesse: 7, spirit: 27)),
                    70 => self::base(142, 0, 0, 0, self::stats(finesse: 8, spirit: 30)),
                    80 => self::base(160, 0, 0, 0, self::stats(finesse: 9, spirit: 34)),
                    90 => self::base(178, 0, 0, 0, self::stats(finesse: 10, spirit: 38)),
                ],
            ],
            'armor' => [
                'category' => 'armor',
                'weapon_style' => null,
                'main_stat' => null,
                'anchors' => [
                    1 => self::base(0, 12, 9, 20, self::stats(vitality: 1)),
                    10 => self::base(0, 28, 22, 60, self::stats(vitality: 2)),
                    20 => self::base(0, 52, 42, 120, self::stats(vitality: 3)),
                    30 => self::base(0, 86, 71, 210, self::stats(vitality: 4)),
                    40 => self::base(0, 120, 100, 300, self::stats(vitality: 5)),
                    50 => self::base(0, 170, 140, 425, self::stats(vitality: 6)),
                    60 => self::base(0, 220, 180, 550, self::stats(vitality: 7)),
                    70 => self::base(0, 270, 220, 675, self::stats(vitality: 8)),
                    80 => self::base(0, 320, 260, 800, self::stats(vitality: 9)),
                    90 => self::base(0, 370, 300, 925, self::stats(vitality: 10)),
                ],
            ],
            'accessory' => [
                'category' => 'accessory',
                'weapon_style' => null,
                'main_stat' => 'spirit',
                'anchors' => [
                    1 => self::base(0, 0, 0, 0, self::stats(spirit: 1)),
                    10 => self::base(0, 0, 0, 0, self::stats(spirit: 2)),
                    20 => self::base(0, 0, 0, 0, self::stats(spirit: 3)),
                    30 => self::base(0, 0, 0, 0, self::stats(spirit: 5)),
                    40 => self::base(0, 0, 0, 0, self::stats(spirit: 6)),
                    50 => self::base(0, 0, 0, 0, self::stats(spirit: 9)),
                    60 => self::base(0, 0, 0, 0, self::stats(spirit: 12)),
                    70 => self::base(0, 0, 0, 0, self::stats(spirit: 15)),
                    80 => self::base(0, 0, 0, 0, self::stats(spirit: 18)),
                    90 => self::base(0, 0, 0, 0, self::stats(spirit: 21)),
                ],
            ],
        ];

        foreach ($cases as $bodyKey => $case) {
            foreach ($case['anchors'] as $itemLevel => $expectedBase) {
                $item = $this->generate(
                    $itemLevel,
                    'shallow_caves',
                    'common',
                    $case['category'],
                    $case['weapon_style'],
                    $case['main_stat'],
                    0,
                );

                $this->assertSame($expectedBase, $item['base'], "Unexpected {$bodyKey} body at Item Lv {$itemLevel}.");
            }
        }
    }

    public function test_linear_interpolation_uses_round_half_up_between_anchors(): void
    {
        $armor = $this->generate(45, 'shallow_caves', 'common', 'armor', null, null, 0);
        $dagger = $this->generate(5, 'shallow_caves', 'common', 'weapon', 'dagger', null, 0);

        // Lv45 is exactly halfway between armor max HP 300 and 425.
        $this->assertSame(363, $armor['base']['max_hp']);
        // Lv5 is 30 + (44 - 30) * 4 / 9 = 36.222... weapon power.
        $this->assertSame(36, $dagger['base']['weapon_power']);
    }

    public function test_item_level_boundaries_include_bahamul_and_reject_unsupported_levels(): void
    {
        $first = $this->generate(1, 'shallow_caves', 'common', 'weapon', 'dagger', null, 0);
        $formerLast = $this->generate(90, 'obsidian_cavern', 'common', 'weapon', 'dagger', null, 0);
        $kingdomFirst = $this->generate(91, 'shining_kingdom', 'common', 'weapon', 'dagger', null, 0);
        $last = $this->generate(120, 'shining_kingdom', 'common', 'weapon', 'dagger', null, 0);

        $this->assertSame(1, $first['item_level']);
        $this->assertSame(90, $formerLast['item_level']);
        $this->assertSame('魔窟の短剣', $formerLast['name']);
        $this->assertSame(91, $kingdomFirst['item_level']);
        $this->assertSame('王都の短剣', $kingdomFirst['name']);
        $this->assertSame(120, $last['item_level']);
        $this->assertSame('王都の短剣', $last['name']);

        $bahamul = $this->generate(210, 'bahamul', 'unique', 'weapon', 'longsword', null, 0);
        $this->assertGreaterThan($last['weapon_power'], $bahamul['weapon_power']);

        foreach ([0, 211] as $itemLevel) {
            try {
                $this->generate($itemLevel, 'shallow_caves', 'common', 'weapon', 'dagger', null, 0);
                $this->fail("Item Lv {$itemLevel} should be rejected.");
            } catch (InvalidArgumentException) {
                // Expected boundary rejection.
            }
        }
    }

    public function test_obsidian_cavern_accessory_name_identifies_its_main_stat(): void
    {
        $expectedNames = [
            'vitality' => '魔窟の生命護符',
            'might' => '魔窟の武力護符',
            'finesse' => '魔窟の技巧護符',
            'spirit' => '魔窟の精神護符',
            'agility' => '魔窟の敏捷護符',
        ];

        foreach ($expectedNames as $mainStat => $expectedName) {
            $item = $this->generate(70, 'obsidian_cavern', 'common', 'accessory', null, $mainStat, 0);

            $this->assertSame($expectedName, $item['name']);
            $this->assertSame(15, $item['base']['stats'][$mainStat]);
        }
    }

    public function test_ordinary_tiers_cannot_generate_unique_effects(): void
    {
        try {
            $this->generate(40, 'black_crystal_cave', 'unique', 'weapon', 'dagger', null, 3);
            $this->fail('An ordinary tier must not generate a boss weapon effect.');
        } catch (InvalidArgumentException) {
            // This tier has no intrinsic weapon effect.
        }

        foreach (['common', 'uncommon', 'rare', 'epic'] as $rarity) {
            $item = $this->generate(40, 'black_crystal_cave', $rarity, 'weapon', 'dagger', null, 3);

            $this->assertNull($item['unique_effect']);
        }
    }

    public function test_rarity_slots_cap_affixes_without_duplicate_keys_and_quality_stays_between_80_and_100_percent(): void
    {
        $expectedWeaponSlots = [
            'common' => 1,
            'uncommon' => 2,
            'rare' => 3,
            'epic' => 4,
        ];

        foreach ($expectedWeaponSlots as $rarity => $expectedSlots) {
            $item = $this->generate(40, 'black_crystal_cave', $rarity, 'weapon', 'dagger', null, 3);
            $affixes = $item['affixes'];
            $keys = array_column($affixes, 'key');

            $this->assertCount($expectedSlots, $affixes);
            $this->assertCount(count($keys), array_unique($keys));
            foreach ($affixes as $affix) {
                $this->assertGreaterThanOrEqual(8_000, $affix['quality_bps']);
                $this->assertLessThanOrEqual(10_000, $affix['quality_bps']);
            }
        }

        $expectedAccessorySlots = [
            'common' => 1,
            'uncommon' => 2,
            'rare' => 2,
            'epic' => 2,
        ];
        foreach ($expectedAccessorySlots as $rarity => $maximumSlots) {
            $item = $this->generate(40, 'black_crystal_cave', $rarity, 'accessory', null, 'spirit', 3);
            $keys = array_column($item['affixes'], 'key');

            $this->assertLessThanOrEqual($maximumSlots, count($item['affixes']));
            $this->assertCount(count($keys), array_unique($keys));
        }
    }

    public function test_accessory_affix_values_use_the_rarity_accessory_multiplier(): void
    {
        $common = $this->generate(30, 'shallow_caves', 'common', 'accessory', null, 'might', 7);
        $epic = $this->generate(30, 'shallow_caves', 'epic', 'accessory', null, 'might', 7);
        $commonAffix = $common['affixes'][0];
        $epicAffix = $epic['affixes'][0];

        $this->assertSame($commonAffix['key'], $epicAffix['key']);
        $this->assertSame($commonAffix['quality_bps'], $epicAffix['quality_bps']);
        $this->assertGreaterThanOrEqual(($commonAffix['value'] * 2) - 1, $epicAffix['value']);
        $this->assertLessThanOrEqual(($commonAffix['value'] * 2) + 1, $epicAffix['value']);
    }

    public function test_common_uses_regular_label_and_modifier_table_formula_keeps_miracle_label_player_facing(): void
    {
        $common = $this->generate(1, 'shallow_caves', 'common', 'weapon', 'dagger', null, 0);
        $item = $this->generate(40, 'black_crystal_cave', 'epic', 'weapon', 'dagger', null, 3);
        $miracle = null;
        foreach ($item['affixes'] as $affix) {
            if ($affix['key'] === 'miracle_damage_bps') {
                $miracle = $affix;
                break;
            }
        }

        $this->assertSame('common', $common['rarity']);
        $this->assertSame('レギュラー', $common['rarity_label']);
        $this->assertIsArray($miracle);
        $this->assertSame('魔法攻撃力アップ', $miracle['label']);
        $this->assertSame('modifier', $miracle['kind']);
        $this->assertGreaterThanOrEqual(8_000, $miracle['quality_bps']);
        $this->assertLessThanOrEqual(10_000, $miracle['quality_bps']);

        // Apply the published combined round-half-up formula to the persisted
        // raw roll audit, without duplicating the RNG implementation here.
        $raw = $miracle['raw_value'];
        $itemLevelBps = $miracle['item_level_bps'];
        $accessoryValueBps = 10_000;
        $expected = min(
            self::roundHalfUp($raw * $itemLevelBps * $miracle['quality_bps'] * $accessoryValueBps, 1_000_000_000_000),
            self::roundHalfUp(420 * $itemLevelBps * $accessoryValueBps, 100_000_000),
        );

        $this->assertSame($expected, $miracle['value']);
    }

    /** @return array<string, mixed> */
    private function generate(
        int $itemLevel,
        string $tier,
        string $rarity,
        string $category,
        ?string $weaponStyle,
        ?string $mainStat,
        int $seed,
    ): array {
        return (new UndergroundRuntimeEquipmentGenerator)->generate(
            $itemLevel,
            $tier,
            $rarity,
            $category,
            $weaponStyle,
            $mainStat,
            $seed,
            'generator-unit-test',
        );
    }

    /** @return array{weapon_power: int, physical_defense: int, magical_defense: int, max_hp: int, stats: array<string, int>} */
    private static function base(
        int $weaponPower,
        int $physicalDefense,
        int $magicalDefense,
        int $maxHp,
        array $stats,
    ): array {
        return [
            'weapon_power' => $weaponPower,
            'physical_defense' => $physicalDefense,
            'magical_defense' => $magicalDefense,
            'max_hp' => $maxHp,
            'stats' => $stats,
        ];
    }

    /** @return array{vitality: int, might: int, finesse: int, spirit: int, agility: int} */
    private static function stats(
        int $vitality = 0,
        int $might = 0,
        int $finesse = 0,
        int $spirit = 0,
        int $agility = 0,
    ): array {
        return compact('vitality', 'might', 'finesse', 'spirit', 'agility');
    }

    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
