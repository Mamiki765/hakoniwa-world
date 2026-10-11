<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\UndergroundEquipmentScaling;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundRuntimeEquipmentGeneratorTest extends TestCase
{
    public function test_quality_uses_final_roll_bounds_rarity_caps_and_original_empty_slots(): void
    {
        $generator = app(UndergroundRuntimeEquipmentGenerator::class);
        $item = $this->generate(30, 'shallow_caves', 'rare', 'accessory', null, 'might', 7);
        // IL30 Artifact HP range is 45..56; damage range is 149..433.
        $item['affixes'] = [
            ['key' => 'max_hp', 'value' => 56],
            ['key' => 'physical_damage_bps', 'value' => 149],
        ];
        $this->assertSame(40, $generator->qualityPercent($item));
        $item['affixes'][1]['value'] = 433;
        $this->assertSame(80, $generator->qualityPercent($item));
        array_pop($item['affixes']);
        $this->assertSame(40, $generator->qualityPercent($item));
        $item['affixes'] = [];
        $this->assertSame(0, $generator->qualityPercent($item));
        $synced = $generator->generate(10, 'shallow_caves', 'rare', 'accessory', null, 'might', 7,
            'legacy-empty-accessory', savedAffixes: []);
        $this->assertSame([], $synced['affixes']);

        $item = $this->generate(1, 'shallow_caves', 'common', 'accessory', null, 'might', 7);
        $item['affixes'] = [['key' => 'vitality', 'value' => 1]];
        $this->assertSame(50, $generator->qualityPercent($item));
    }

    public function test_resonance_keeps_fixed_effect_and_roll_quality_while_210_affixes_gain_rating(): void
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
        $this->assertSame($atCap['affixes'][0]['value'], $afterCap['affixes'][0]['value']);
        $this->assertSame($atCap['affixes'][0]['quality_bps'], $afterCap['affixes'][0]['quality_bps']);
        $this->assertSame(990, $afterCap['affixes'][0]['rating']);
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

    public function test_210_weapon_and_armor_resolve_220_accessory_rating_and_hp_affix_once(): void
    {
        $physical = config('underground-equipment.generator.affixes.physical_damage_bps');
        $maxHp = config('underground-equipment.generator.affixes.max_hp');
        config([
            'underground-equipment.generator.rarities.common.accessory_presence_bps' => 10_000,
            'underground-equipment.generator.affixes' => ['physical_damage_bps' => $physical],
        ]);
        $weapon = $this->generate(210, 'hero', 'common', 'weapon', 'longsword', null, 23);
        $accessory = $this->generate(220, 'hero', 'common', 'accessory', null, 'might', 24);
        config(['underground-equipment.generator.affixes' => ['max_hp' => $maxHp]]);
        $armor = $this->generate(210, 'hero', 'common', 'armor', null, null, 25);
        $lowArmor = $this->generate(200, 'hero', 'common', 'armor', null, null, 25);
        $hpAccessory = $this->generate(220, 'hero', 'common', 'accessory', null, 'might', 26);
        $catalog = app(UndergroundEquipmentCatalog::class);
        $entry = static fn (string $slot, array $definition): array => [
            'slot' => $slot, 'definition' => $definition,
            'catalog_identity' => $catalog->identity(),
            'instance_identity' => $definition['instance_identity'],
        ];
        $full = $catalog->combatLoadout([
            $entry('weapon', $weapon), $entry('armor', $armor),
            $entry('accessory_1', $accessory), $entry('accessory_2', $hpAccessory),
        ]);
        $low = $catalog->combatLoadout([
            $entry('weapon', $weapon), $entry('armor', $lowArmor),
            $entry('accessory_1', $accessory), $entry('accessory_2', $hpAccessory),
        ]);

        $this->assertSame([11_000, 11_000], [$full['weapon_scale_bps'], $full['armor_scale_bps']]);
        $this->assertGreaterThan($accessory['affixes'][0]['value'], $accessory['affixes'][0]['rating']);
        $this->assertSame(11_000, $full['rating_requirement_bps']);
        $this->assertSame(10_000, $low['rating_requirement_bps']);
        $this->assertGreaterThan($full['modifiers']['physical_damage_bps'], $low['modifiers']['physical_damage_bps']);
        $this->assertGreaterThan($full['max_hp_affix_base'], $full['max_hp_affix_scaled']);
        $this->assertSame($hpAccessory['affixes'][0]['value'], $hpAccessory['max_hp'] - $hpAccessory['base']['max_hp']);
        $this->assertSame(671, UndergroundEquipmentScaling::maxHp(
            new AlphaV1CombatRules,
            array_fill_keys(AlphaV1CombatRules::STATS, 20),
            10_000,
            ['max_hp' => 100, 'max_hp_affix_base' => 100, 'max_hp_affix_scaled' => 121, 'armor_scale_bps' => 11_000],
        ));
    }

    public function test_representative_body_anchors_are_exposed_before_affix_application(): void
    {
        // The body comes from the item level anchors alone: rarity and affix
        // rolls must not leak into it.
        $cases = [
            ['weapon', 'dagger', null, 1],
            ['weapon', 'longsword', null, 90],
            ['armor', null, null, 50],
            ['accessory', null, 'spirit', 70],
        ];

        foreach ($cases as [$category, $style, $stat, $level]) {
            $plain = $this->generate($level, 'shallow_caves', 'common', $category, $style, $stat, 0);
            $rolled = $this->generate($level, 'shallow_caves', 'epic', $category, $style, $stat, 3);
            $this->assertNotEmpty($rolled['affixes']);
            $this->assertSame($plain['base'], $rolled['base']);
            $this->assertNotEmpty(array_filter($plain['base']['stats']));
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
        $this->assertSame(91, $kingdomFirst['item_level']);
        $this->assertSame(120, $last['item_level']);

        $bahamul = $this->generate(210, 'bahamul', 'unique', 'weapon', 'longsword', null, 0);
        $this->assertGreaterThan($last['weapon_power'], $bahamul['weapon_power']);

        $maximumLevel = app(UndergroundEquipmentCatalog::class)->generatorItemLevelMax();
        $this->assertSame($maximumLevel, $this->generate($maximumLevel, 'hero', 'common', 'weapon', 'dagger', null, 0)['item_level']);

        foreach ([0, $maximumLevel + 1] as $itemLevel) {
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
        $names = [];
        foreach (AlphaV1CombatRules::STATS as $mainStat) {
            $item = $this->generate(70, 'obsidian_cavern', 'common', 'accessory', null, $mainStat, 0);

            $names[$mainStat] = $item['name'];
            $this->assertGreaterThan(0, $item['base']['stats'][$mainStat]);
        }
        $this->assertCount(count($names), array_unique($names));
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
        $rarities = ['common', 'uncommon', 'rare', 'epic'];
        $weaponSlots = [];
        foreach ($rarities as $rarity) {
            $item = $this->generate(40, 'black_crystal_cave', $rarity, 'weapon', 'dagger', null, 3);
            $affixes = $item['affixes'];
            $keys = array_column($affixes, 'key');

            $weaponSlots[$rarity] = count($affixes);
            if ($rarity !== 'common') {
                $this->assertGreaterThan($weaponSlots[$rarities[array_search($rarity, $rarities, true) - 1]], count($affixes));
            }
            $this->assertCount(count($keys), array_unique($keys));
            foreach ($affixes as $affix) {
                $this->assertGreaterThanOrEqual(8_000, $affix['quality_bps']);
                $this->assertLessThanOrEqual(10_000, $affix['quality_bps']);
            }
        }

        foreach ($rarities as $rarity) {
            $item = $this->generate(40, 'black_crystal_cave', $rarity, 'accessory', null, 'spirit', 3);
            $keys = array_column($item['affixes'], 'key');

            $this->assertNotEmpty($item['affixes']);
            $this->assertLessThanOrEqual($weaponSlots[$rarity], count($item['affixes']));
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
        $this->assertNotEmpty($common['rarity_label']);
        $this->assertIsArray($miracle);
        $this->assertNotEmpty($miracle['label']);
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

    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
