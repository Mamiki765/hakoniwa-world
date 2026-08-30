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
): array => [
    'key' => $key,
    'name' => $name,
    'category' => $category,
    'weapon_style' => $weaponStyle,
    'rank' => $rank,
    'item_level' => $itemLevel,
    'rarity' => 'common',
    'buy_price' => $buyPrice,
    'shop_sold' => $shopSold,
    'sellable' => $sellable,
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
            $key,
            $series['names'][$rank - 1],
            'accessory',
            null,
            $rank,
            $itemLevels[$rank],
            $prices[$rank],
            0,
            0,
            0,
            0,
            $bonus,
        );
    }
}

return [
    'schema_version' => 1,
    'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
    'vault_capacity' => 500,
    'page_size' => 50,
    'definitions' => $definitions,
];
