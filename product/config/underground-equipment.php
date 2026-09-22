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
$definitions['demon_sword_gram'] = [
    ...$definition('demon_sword_gram', '魔剣グラム', 'weapon', 'longsword', 0, 1254, null,
        0, 0, 0, 0, $stats(), false, false),
    'rarity' => 'unique', 'rarity_label' => 'ユニーク', 'equippable' => false,
    'commemorative_effects' => ['生命アップ', '武力アップ', '光輝（被回復アップ）', '毎ターンHP・MP回復', '被クリティカル率低下'],
    'description' => '夢の女王との決闘の記念品。装備・売却不可。所持による能力効果はありません。',
];
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

$versionTwoDefinitions = $definitions;
// IL61 uses the existing generator's base anchors, without randomized affixes.
$definitions['kingdom_dagger'] = $definition(
    'kingdom_dagger', '王都の短剣', 'weapon', 'dagger', 5, 61, 6_200,
    142, 0, 0, 0, $stats(finesse: 20, agility: 13), requiredTrialKey: 'trial_02',
);
$definitions['kingdom_rapier'] = $definition(
    'kingdom_rapier', '近衛の細剣', 'weapon', 'rapier', 5, 61, 6_200,
    158, 0, 0, 0, $stats(might: 16, finesse: 12), requiredTrialKey: 'trial_02',
);
$definitions['kingdom_longsword'] = $definition(
    'kingdom_longsword', '王国の長剣', 'weapon', 'longsword', 5, 61, 6_200,
    144, 41, 0, 0, $stats(vitality: 20, might: 12), requiredTrialKey: 'trial_02',
);
$definitions['kingdom_staff'] = $definition(
    'kingdom_staff', '宮廷の輝石杖', 'weapon', 'crystal_staff', 5, 61, 6_200,
    126, 0, 0, 0, $stats(finesse: 7, spirit: 27), requiredTrialKey: 'trial_02',
);
$definitions['kingdom_breastplate'] = $definition(
    'kingdom_breastplate', '王都の胸当て', 'armor', null, 5, 61, 5_580,
    0, 225, 184, 563, $stats(vitality: 7), requiredTrialKey: 'trial_02',
);
foreach (['vitality' => '王都の生命護符', 'might' => '王都の武力護符', 'finesse' => '王都の技巧護符', 'spirit' => '宮廷の精神護符', 'agility' => '王都の敏捷護符'] as $stat => $name) {
    $bonus = array_fill_keys(AlphaV1CombatRules::STATS, 0);
    $bonus[$stat] = 12;
    $definitions["kingdom_{$stat}_accessory"] = $definition(
        "kingdom_{$stat}_accessory", $name, 'accessory', null, 5, 61, 3_720,
        0, 0, 0, 0, $bonus, requiredTrialKey: 'trial_02',
    );
}

return [
    'schema_version' => 2,
    'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v3',
    'weapon_style_labels' => [
        'dagger' => '短剣',
        'rapier' => '細身剣',
        'longsword' => '長剣',
        'crystal_staff' => '輝石杖',
    ],
    'legacy_catalogs' => [
        'secretary-underground-shop-equipment-alpha-v2' => $versionTwoDefinitions,
        'secretary-underground-shop-equipment-alpha-v1' => $legacyDefinitions,
    ],
    'vault_capacity' => 500,
    'resonance_capacity' => 50,
    'page_size' => 50,
    'definitions' => $definitions,
    'generator' => [
        'identity' => 'secretary-underground-drop-equipment-alpha-v2',
        'legacy_identities' => ['secretary-underground-drop-equipment-alpha-v1'],
        'item_level_min' => 1,
        'item_level_max' => 210,
        'tiers' => [
            'bahamul' => [
                'weapon_names' => ['dagger' => '黒竜の短剣', 'rapier' => '黒竜の細剣', 'longsword' => '黒竜の長剣', 'crystal_staff' => '黒竜の輝石杖'],
                'armor_name' => '黒竜の胸当て',
                'accessory_name' => '黒竜の護符',
                'resonance_name' => '黒竜の共鳴結晶',
                'weapon_effect' => [
                    'key' => 'bahamul_shockwave',
                    'label' => '黒竜の衝撃波',
                    'chance_bps' => 2_000,
                    'potency_bps' => 2_800,
                    'stat_coefficients' => [
                        'dagger' => ['might' => 7_000, 'finesse' => 3_000],
                        'rapier' => ['might' => 7_000, 'finesse' => 3_000],
                        'longsword' => ['vitality' => 4_000, 'might' => 6_000],
                        'crystal_staff' => ['spirit' => 7_000, 'finesse' => 3_000],
                    ],
                ],
                'resonance_effect' => [
                    'key' => 'bahamul_resonance',
                    'label' => '黒竜の共鳴',
                    'target' => 'resonance_area_damage_bps',
                ],
            ],
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
            'obsidian_cavern' => [
                'weapon_names' => ['dagger' => '魔窟の短剣', 'rapier' => '魔窟の細剣', 'longsword' => '魔窟の長剣', 'crystal_staff' => '魔窟の杖'],
                'armor_name' => '魔窟の胸当て',
                'accessory_names' => [
                    'vitality' => '魔窟の生命護符',
                    'might' => '魔窟の武力護符',
                    'finesse' => '魔窟の技巧護符',
                    'spirit' => '魔窟の精神護符',
                    'agility' => '魔窟の敏捷護符',
                ],
            ],
            'shining_kingdom' => [
                'weapon_names' => ['dagger' => '王都の短剣', 'rapier' => '近衛の細剣', 'longsword' => '王国の長剣', 'crystal_staff' => '宮廷の輝石杖'],
                'armor_name' => '王都の胸当て',
                'accessory_names' => [
                    'vitality' => '王都の生命護符',
                    'might' => '王都の武力護符',
                    'finesse' => '王都の技巧護符',
                    'spirit' => '宮廷の精神護符',
                    'agility' => '王都の敏捷護符',
                ],
            ],
        ],
        'rarities' => [
            'unique' => ['label' => 'ユニーク', 'weapon_armor_slots' => 3],
            'common' => ['label' => 'レギュラー', 'weapon_armor_slots' => 1, 'accessory_slots' => 1, 'accessory_presence_bps' => 5_000, 'accessory_value_bps' => 5_000],
            'uncommon' => ['label' => 'ハイクオリティ', 'weapon_armor_slots' => 2, 'accessory_slots' => 2, 'accessory_presence_bps' => 5_000, 'accessory_value_bps' => 5_000],
            'rare' => ['label' => 'アーティファクト', 'weapon_armor_slots' => 3, 'accessory_slots' => 2, 'accessory_presence_bps' => 8_000, 'accessory_value_bps' => 8_000],
            'epic' => ['label' => 'レリック', 'weapon_armor_slots' => 4, 'accessory_slots' => 2, 'accessory_presence_bps' => 10_000, 'accessory_value_bps' => 10_000],
        ],
        'body_anchors' => [
            'dagger' => [
                'category' => 'weapon', 'weapon_style' => 'dagger',
                'weapon_power' => [1 => 30, 10 => 44, 20 => 60, 30 => 80, 40 => 100, 50 => 120, 60 => 140, 70 => 160, 80 => 180, 90 => 200, 100 => 220, 110 => 240, 120 => 260],
                'stats' => [
                    'finesse' => [1 => 3, 10 => 6, 20 => 10, 30 => 13, 40 => 15, 50 => 18, 60 => 20, 70 => 22, 80 => 24, 90 => 26, 100 => 28, 110 => 30, 120 => 32],
                    'agility' => [1 => 2, 10 => 4, 20 => 7, 30 => 9, 40 => 10, 50 => 12, 60 => 13, 70 => 15, 80 => 16, 90 => 18, 100 => 19, 110 => 21, 120 => 22],
                ],
            ],
            'rapier' => [
                'category' => 'weapon', 'weapon_style' => 'rapier',
                'weapon_power' => [1 => 34, 10 => 50, 20 => 68, 30 => 90, 40 => 112, 50 => 134, 60 => 156, 70 => 178, 80 => 200, 90 => 222, 100 => 244, 110 => 266, 120 => 288],
                'stats' => [
                    'might' => [1 => 2, 10 => 5, 20 => 8, 30 => 10, 40 => 12, 50 => 14, 60 => 16, 70 => 18, 80 => 20, 90 => 22, 100 => 24, 110 => 26, 120 => 28],
                    'finesse' => [1 => 2, 10 => 4, 20 => 6, 30 => 8, 40 => 9, 50 => 11, 60 => 12, 70 => 14, 80 => 15, 90 => 17, 100 => 18, 110 => 20, 120 => 21],
                ],
            ],
            'longsword' => [
                'category' => 'weapon', 'weapon_style' => 'longsword',
                'weapon_power' => [1 => 31, 10 => 46, 20 => 62, 30 => 82, 40 => 102, 50 => 122, 60 => 142, 70 => 162, 80 => 182, 90 => 202, 100 => 222, 110 => 242, 120 => 262],
                'physical_defense' => [1 => 4, 10 => 8, 20 => 14, 30 => 20, 40 => 26, 50 => 33, 60 => 40, 70 => 48, 80 => 56, 90 => 64, 100 => 72, 110 => 80, 120 => 88],
                'stats' => [
                    'vitality' => [1 => 3, 10 => 6, 20 => 10, 30 => 13, 40 => 15, 50 => 18, 60 => 20, 70 => 22, 80 => 24, 90 => 26, 100 => 28, 110 => 30, 120 => 32],
                    'might' => [1 => 2, 10 => 4, 20 => 6, 30 => 8, 40 => 9, 50 => 11, 60 => 12, 70 => 14, 80 => 15, 90 => 17, 100 => 18, 110 => 20, 120 => 21],
                ],
            ],
            'crystal_staff' => [
                'category' => 'weapon', 'weapon_style' => 'crystal_staff',
                'weapon_power' => [1 => 26, 10 => 38, 20 => 52, 30 => 70, 40 => 88, 50 => 106, 60 => 124, 70 => 142, 80 => 160, 90 => 178, 100 => 196, 110 => 214, 120 => 232],
                'stats' => [
                    'finesse' => [1 => 1, 10 => 2, 20 => 3, 30 => 4, 40 => 5, 50 => 6, 60 => 7, 70 => 8, 80 => 9, 90 => 10, 100 => 11, 110 => 12, 120 => 13],
                    'spirit' => [1 => 4, 10 => 8, 20 => 13, 30 => 17, 40 => 20, 50 => 24, 60 => 27, 70 => 30, 80 => 34, 90 => 38, 100 => 42, 110 => 46, 120 => 50],
                ],
            ],
            'armor' => [
                'category' => 'armor', 'weapon_style' => null,
                'physical_defense' => [1 => 12, 10 => 28, 20 => 52, 30 => 86, 40 => 120, 50 => 170, 60 => 220, 70 => 270, 80 => 320, 90 => 370, 100 => 420, 110 => 470, 120 => 520],
                'magical_defense' => [1 => 9, 10 => 22, 20 => 42, 30 => 71, 40 => 100, 50 => 140, 60 => 180, 70 => 220, 80 => 260, 90 => 300, 100 => 340, 110 => 380, 120 => 420],
                'max_hp' => [1 => 20, 10 => 60, 20 => 120, 30 => 210, 40 => 300, 50 => 425, 60 => 550, 70 => 675, 80 => 800, 90 => 925, 100 => 1050, 110 => 1175, 120 => 1300],
                'stats' => ['vitality' => [1 => 1, 10 => 2, 20 => 3, 30 => 4, 40 => 5, 50 => 6, 60 => 7, 70 => 8, 80 => 9, 90 => 10, 100 => 11, 110 => 12, 120 => 13]],
            ],
            'accessory' => [
                'category' => 'accessory', 'weapon_style' => null,
                'main_stat' => [1 => 1, 10 => 2, 20 => 3, 30 => 5, 40 => 6, 50 => 9, 60 => 12, 70 => 15, 80 => 18, 90 => 21, 100 => 24, 110 => 27, 120 => 30],
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
        'resonance' => [
            'slots' => 2,
            'percentage_item_level_cap' => 200,
            'stats' => [1 => 1, 120 => 5, 130 => 10, 150 => 20, 180 => 40, 210 => 60],
            'intrinsic_bps' => [1 => 100, 130 => 600, 150 => 800, 180 => 1_000, 200 => 1_200],
            'affix_min_bps' => [1 => 100, 130 => 300, 150 => 400, 180 => 500, 200 => 600],
            'affix_max_bps' => [1 => 100, 130 => 400, 150 => 600, 180 => 800, 200 => 900],
            'affixes' => [
                'resonance_single_damage_bps' => '単体攻撃強化',
                'resonance_area_damage_bps' => '範囲攻撃強化',
                'resonance_normal_damage_bps' => '通常攻撃強化',
                'resonance_skill_damage_bps' => '攻撃技強化',
                'resonance_single_healing_bps' => '単体回復強化',
                'resonance_area_healing_bps' => '範囲回復強化',
                'resonance_guard_reduction_bps' => '防御行動強化',
            ],
        ],
    ],
];
