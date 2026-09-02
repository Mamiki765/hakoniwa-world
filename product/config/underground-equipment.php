<?php

use App\Domain\Underground\Combat\AlphaV1CombatRules;

$stats = static fn (
    int $vitality = 0,
    int $might = 0,
    int $finesse = 0,
    int $spirit = 0,
    int $agility = 0,
): array => compact('vitality', 'might', 'finesse', 'spirit', 'agility');

$definition = static fn (
    string $key,
    string $name,
    string $category,
    ?string $weaponStyle,
    int $rank,
    int $itemLevel,
    ?int $buyPrice,
    int $weaponPower,
    int $physicalDefense,
    int $magicalDefense,
    int $maxHp,
    array $baseStats,
    bool $shopSold = true,
    bool $sellable = true,
    ?string $requiredTrialKey = null,
): array => [
    'key' => $key,
    'name' => $name,
    'category' => $category,
    'weapon_style' => $weaponStyle,
    'rank' => $rank,
    'item_level' => $itemLevel,
    'rarity' => 'common',
    'rarity_label' => 'ノービス',
    'buy_price' => $buyPrice,
    'shop_sold' => $shopSold,
    'sellable' => $sellable,
    'required_trial_key' => $requiredTrialKey,
    'weapon_power' => $weaponPower,
    'physical_defense' => $physicalDefense,
    'magical_defense' => $magicalDefense,
    'max_hp' => $maxHp,
    'stats' => $baseStats,
    'modifiers' => [],
    'affixes' => [],
    'unique_effect' => null,
];

$definitions = [
    'starter_knife' => $definition(
        'starter_knife', '護身用ナイフ', 'weapon', 'dagger', 0, 1, null,
        24, 0, 0, 0, $stats(1, 1, 1, 1, 1), false, false,
    ),
    'iron_dagger' => $definition('iron_dagger', '鉄の短剣', 'weapon', 'dagger', 1, 1, 120, 30, 0, 0, 0, $stats(finesse: 3, agility: 2)),
    'steel_dagger' => $definition('steel_dagger', '鋼の短剣', 'weapon', 'dagger', 2, 10, 360, 44, 0, 0, 0, $stats(finesse: 6, agility: 4)),
    'polished_steel_dagger' => $definition('polished_steel_dagger', '研磨鋼の短剣', 'weapon', 'dagger', 3, 20, 1_000, 60, 0, 0, 0, $stats(finesse: 10, agility: 7)),
    'bronze_rapier' => $definition('bronze_rapier', '青銅の細身剣', 'weapon', 'rapier', 1, 1, 120, 34, 0, 0, 0, $stats(might: 2, finesse: 2)),
    'iron_rapier' => $definition('iron_rapier', '鉄の細身剣', 'weapon', 'rapier', 2, 10, 360, 50, 0, 0, 0, $stats(might: 5, finesse: 4)),
    'steel_rapier' => $definition('steel_rapier', '鋼の細身剣', 'weapon', 'rapier', 3, 20, 1_000, 68, 0, 0, 0, $stats(might: 8, finesse: 6)),
    'iron_longsword' => $definition('iron_longsword', '鉄の長剣', 'weapon', 'longsword', 1, 1, 120, 31, 4, 0, 0, $stats(vitality: 3, might: 2)),
    'steel_longsword' => $definition('steel_longsword', '鋼の長剣', 'weapon', 'longsword', 2, 10, 360, 46, 8, 0, 0, $stats(vitality: 6, might: 4)),
    'reinforced_longsword' => $definition('reinforced_longsword', '補強鋼の長剣', 'weapon', 'longsword', 3, 20, 1_000, 62, 14, 0, 0, $stats(vitality: 10, might: 6)),
    'wood_crystal_staff' => $definition('wood_crystal_staff', '木の輝石杖', 'weapon', 'crystal_staff', 1, 1, 120, 26, 0, 0, 0, $stats(finesse: 1, spirit: 4)),
    'oak_crystal_staff' => $definition('oak_crystal_staff', '樫の輝石杖', 'weapon', 'crystal_staff', 2, 10, 360, 38, 0, 0, 0, $stats(finesse: 2, spirit: 8)),
    'iron_core_crystal_staff' => $definition('iron_core_crystal_staff', '鉄芯の輝石杖', 'weapon', 'crystal_staff', 3, 20, 1_000, 52, 0, 0, 0, $stats(finesse: 3, spirit: 13)),
    'leather_armor' => $definition('leather_armor', '革の鎧', 'armor', null, 1, 1, 100, 0, 12, 9, 20, $stats(vitality: 1)),
    'reinforced_leather_armor' => $definition('reinforced_leather_armor', '補強革の鎧', 'armor', null, 2, 10, 300, 0, 28, 22, 60, $stats(vitality: 2)),
    'iron_breastplate' => $definition('iron_breastplate', '鉄の胸当て', 'armor', null, 3, 20, 900, 0, 52, 42, 120, $stats(vitality: 3)),
];

$accessories = [
    'vitality' => ['names' => ['革紐のお守り', '補強紐のお守り', '鉄飾りのお守り']],
    'might' => ['names' => ['力の銅指輪', '力の鉄指輪', '力の鋼指輪']],
    'finesse' => ['names' => ['細工の銅指輪', '細工の銀指輪', '精密細工の指輪']],
    'spirit' => ['names' => ['木彫りの首飾り', '輝石片の首飾り', '磨き輝石の首飾り']],
    'agility' => ['names' => ['軽革のお守り', '編み革のお守り', '薄鉄のお守り']],
];
$itemLevels = [1 => 1, 2 => 10, 3 => 20];
$prices = [1 => 60, 2 => 180, 3 => 600];
foreach ($accessories as $stat => $series) {
    foreach ([1, 2, 3] as $rank) {
        $key = "{$stat}_accessory_rank_{$rank}";
        $bonus = array_fill_keys(AlphaV1CombatRules::STATS, 0);
        $bonus[$stat] = $rank;
        $definitions[$key] = $definition(
            $key, $series['names'][$rank - 1], 'accessory', null, $rank,
            $itemLevels[$rank], $prices[$rank], 0, 0, 0, 0, $bonus,
        );
    }
}

$legacyDefinitions = $definitions;
$trial1Key = 'trial_01';
$definitions['black_crystal_dagger'] = $definition(
    'black_crystal_dagger', '黒晶の短剣', 'weapon', 'dagger', 4, 40, 3_000,
    100, 0, 0, 0, $stats(finesse: 15, agility: 10), requiredTrialKey: $trial1Key,
);
$definitions['black_crystal_rapier'] = $definition(
    'black_crystal_rapier', '黒晶の細剣', 'weapon', 'rapier', 4, 40, 3_000,
    112, 0, 0, 0, $stats(might: 12, finesse: 9), requiredTrialKey: $trial1Key,
);
$definitions['black_crystal_longsword'] = $definition(
    'black_crystal_longsword', '黒晶の長剣', 'weapon', 'longsword', 4, 40, 3_000,
    102, 26, 0, 0, $stats(vitality: 15, might: 9), requiredTrialKey: $trial1Key,
);
$definitions['black_crystal_staff'] = $definition(
    'black_crystal_staff', '黒晶の杖', 'weapon', 'crystal_staff', 4, 40, 3_000,
    88, 0, 0, 0, $stats(finesse: 5, spirit: 20), requiredTrialKey: $trial1Key,
);
$definitions['black_crystal_breastplate'] = $definition(
    'black_crystal_breastplate', '黒晶の胸当て', 'armor', null, 4, 40, 2_700,
    0, 120, 100, 300, $stats(vitality: 5), requiredTrialKey: $trial1Key,
);
foreach (AlphaV1CombatRules::STATS as $stat) {
    $bonus = array_fill_keys(AlphaV1CombatRules::STATS, 0);
    $bonus[$stat] = 6;
    $definitions["black_crystal_{$stat}_accessory"] = $definition(
        "black_crystal_{$stat}_accessory", '黒晶の護符', 'accessory', null, 4, 40, 1_800,
        0, 0, 0, 0, $bonus, requiredTrialKey: $trial1Key,
    );
}

return [
    'schema_version' => 2,
    'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
    'legacy_catalogs' => [
        'secretary-underground-shop-equipment-alpha-v1' => $legacyDefinitions,
    ],
    'vault_capacity' => 500,
    'page_size' => 50,
    'definitions' => $definitions,
    'generator' => [
        'identity' => 'secretary-underground-drop-equipment-alpha-v1',
        'item_level_min' => 1,
        'item_level_max' => 60,
        'tiers' => [
            'shallow_caves' => [
                'weapon_names' => ['dagger' => '浅層の短剣', 'rapier' => '浅層の細剣', 'longsword' => '浅層の長剣', 'crystal_staff' => '浅層の杖'],
                'armor_name' => '浅層の胸当て',
                'accessory_name' => '浅層の護符',
            ],
            'black_crystal_cave' => [
                'weapon_names' => ['dagger' => '黒晶の短剣', 'rapier' => '黒晶の細剣', 'longsword' => '黒晶の長剣', 'crystal_staff' => '黒晶の杖'],
                'armor_name' => '黒晶の胸当て',
                'accessory_name' => '黒晶の護符',
            ],
        ],
        'rarities' => [
            'common' => ['label' => 'レギュラー', 'weapon_armor_slots' => 1, 'accessory_slots' => 1, 'accessory_presence_bps' => 5_000, 'accessory_value_bps' => 5_000],
            'uncommon' => ['label' => 'ハイクオリティ', 'weapon_armor_slots' => 2, 'accessory_slots' => 2, 'accessory_presence_bps' => 5_000, 'accessory_value_bps' => 5_000],
            'rare' => ['label' => 'アーティファクト', 'weapon_armor_slots' => 3, 'accessory_slots' => 2, 'accessory_presence_bps' => 8_000, 'accessory_value_bps' => 8_000],
            'epic' => ['label' => 'レリック', 'weapon_armor_slots' => 4, 'accessory_slots' => 2, 'accessory_presence_bps' => 10_000, 'accessory_value_bps' => 10_000],
        ],
        'body_anchors' => [
            'dagger' => [
                'category' => 'weapon', 'weapon_style' => 'dagger',
                'weapon_power' => [1 => 30, 10 => 44, 20 => 60, 30 => 80, 40 => 100, 50 => 120, 60 => 140],
                'stats' => [
                    'finesse' => [1 => 3, 10 => 6, 20 => 10, 30 => 13, 40 => 15, 50 => 18, 60 => 20],
                    'agility' => [1 => 2, 10 => 4, 20 => 7, 30 => 9, 40 => 10, 50 => 12, 60 => 13],
                ],
            ],
            'rapier' => [
                'category' => 'weapon', 'weapon_style' => 'rapier',
                'weapon_power' => [1 => 34, 10 => 50, 20 => 68, 30 => 90, 40 => 112, 50 => 134, 60 => 156],
                'stats' => [
                    'might' => [1 => 2, 10 => 5, 20 => 8, 30 => 10, 40 => 12, 50 => 14, 60 => 16],
                    'finesse' => [1 => 2, 10 => 4, 20 => 6, 30 => 8, 40 => 9, 50 => 11, 60 => 12],
                ],
            ],
            'longsword' => [
                'category' => 'weapon', 'weapon_style' => 'longsword',
                'weapon_power' => [1 => 31, 10 => 46, 20 => 62, 30 => 82, 40 => 102, 50 => 122, 60 => 142],
                'physical_defense' => [1 => 4, 10 => 8, 20 => 14, 30 => 20, 40 => 26, 50 => 33, 60 => 40],
                'stats' => [
                    'vitality' => [1 => 3, 10 => 6, 20 => 10, 30 => 13, 40 => 15, 50 => 18, 60 => 20],
                    'might' => [1 => 2, 10 => 4, 20 => 6, 30 => 8, 40 => 9, 50 => 11, 60 => 12],
                ],
            ],
            'crystal_staff' => [
                'category' => 'weapon', 'weapon_style' => 'crystal_staff',
                'weapon_power' => [1 => 26, 10 => 38, 20 => 52, 30 => 70, 40 => 88, 50 => 106, 60 => 124],
                'stats' => [
                    'finesse' => [1 => 1, 10 => 2, 20 => 3, 30 => 4, 40 => 5, 50 => 6, 60 => 7],
                    'spirit' => [1 => 4, 10 => 8, 20 => 13, 30 => 17, 40 => 20, 50 => 24, 60 => 27],
                ],
            ],
            'armor' => [
                'category' => 'armor', 'weapon_style' => null,
                'physical_defense' => [1 => 12, 10 => 28, 20 => 52, 30 => 86, 40 => 120, 50 => 170, 60 => 220],
                'magical_defense' => [1 => 9, 10 => 22, 20 => 42, 30 => 71, 40 => 100, 50 => 140, 60 => 180],
                'max_hp' => [1 => 20, 10 => 60, 20 => 120, 30 => 210, 40 => 300, 50 => 425, 60 => 550],
                'stats' => ['vitality' => [1 => 1, 10 => 2, 20 => 3, 30 => 4, 40 => 5, 50 => 6, 60 => 7]],
            ],
            'accessory' => [
                'category' => 'accessory', 'weapon_style' => null,
                'main_stat' => [1 => 1, 10 => 2, 20 => 3, 30 => 5, 40 => 6, 50 => 9, 60 => 12],
            ],
        ],
        'affixes' => [
            'vitality' => ['label' => '生命力アップ', 'kind' => 'stat', 'target' => 'vitality'],
            'might' => ['label' => '筋力アップ', 'kind' => 'stat', 'target' => 'might'],
            'finesse' => ['label' => '技巧アップ', 'kind' => 'stat', 'target' => 'finesse'],
            'spirit' => ['label' => '精神力アップ', 'kind' => 'stat', 'target' => 'spirit'],
            'agility' => ['label' => '敏捷アップ', 'kind' => 'stat', 'target' => 'agility'],
            'physical_damage_bps' => ['label' => '物理攻撃力アップ', 'kind' => 'modifier', 'target' => 'physical_damage_bps', 'minimum' => 180, 'maximum' => 420],
            'miracle_damage_bps' => ['label' => '魔法攻撃力アップ', 'kind' => 'modifier', 'target' => 'miracle_damage_bps', 'minimum' => 180, 'maximum' => 420],
            'healing_bps' => ['label' => '治癒力アップ', 'kind' => 'modifier', 'target' => 'healing_bps', 'minimum' => 180, 'maximum' => 420],
            'barrier_bps' => ['label' => '護壁力アップ', 'kind' => 'modifier', 'target' => 'barrier_bps', 'minimum' => 180, 'maximum' => 420],
            'critical_chance_bps' => ['label' => 'critical率アップ', 'kind' => 'modifier', 'target' => 'critical_chance_bps', 'minimum' => 120, 'maximum' => 300],
            'critical_damage_bps' => ['label' => 'critical damageアップ', 'kind' => 'modifier', 'target' => 'critical_damage_bps', 'minimum' => 180, 'maximum' => 420],
            'mp_cost_reduction_bps' => ['label' => 'MP効率アップ', 'kind' => 'modifier', 'target' => 'mp_cost_reduction_bps', 'minimum' => 120, 'maximum' => 280],
            'max_hp' => ['label' => '最大HPアップ', 'kind' => 'base', 'target' => 'max_hp'],
            'physical_defense' => ['label' => '物理防御アップ', 'kind' => 'base', 'target' => 'physical_defense'],
            'magical_defense' => ['label' => '魔法防御アップ', 'kind' => 'base', 'target' => 'magical_defense'],
        ],
        'quality_min_bps' => 8_000,
        'quality_max_bps' => 10_000,
        'sell_price_bps' => 1_000,
    ],
];
