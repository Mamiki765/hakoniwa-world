<?php

$domain = require __DIR__.'/../v26/secretary.php';
$secretary = $domain['payload']['secretary'];

$secretary['item_rarities']['artifact'] = [
    'key' => 'artifact', 'name' => 'アーティファクト', 'fixed_sale_price_money' => 3000,
];
$secretary['item_rarities']['relic'] = [
    'key' => 'relic', 'name' => 'レリック', 'fixed_sale_price_money' => 6000,
];

foreach ($secretary['items'] as $key => &$item) {
    $item['gacha_exception'] = in_array($key, ['old_bow', 'wakuwaku_ticket', 'dokidoki_ticket'], true);
}
unset($item);

$secretary['ticket_gacha'] = [
    'wakuwaku_ticket' => ['rarity_weights_basis_points' => [
        'regular' => 6895, 'high_quality' => 2955, 'artifact' => 150,
    ]],
    'dokidoki_ticket' => ['rarity_weights_basis_points' => [
        'high_quality' => 7000, 'artifact' => 3000,
    ]],
];

$charm = static fn (string $disaster): array => [[
    'type' => 'disaster_guard', 'disaster_key' => $disaster, 'cells_per_charge' => 1,
]];
$bow = static function (int $base, string $scope, string $damageType, bool $finisher): array {
    $effect = [
        'type' => 'pre_normal_monster_attack',
        'timing' => 'after_missile_finalization_before_normal_monsters',
        'chance_base_basis_points' => $base,
        'chance_basis_points_per_level' => 100,
        'damage' => 1,
        'damage_type' => $damageType,
        'target_scope' => $scope,
        'target_map_space_keys' => ['surface'],
        'target_safety_policy' => 'avoid_ineffective_or_immediate_hazard',
        'random_stream_version' => 1,
    ];
    if ($finisher) {
        $effect['finisher'] = [
            'current_hp' => 2, 'damage' => 2,
            'chance_multiplier_numerator' => 2, 'chance_multiplier_denominator' => 5,
            'requires_damage_one_safety_rejection' => true,
            'requires_damage_two_kill' => true,
        ];
    }

    return [$effect];
};
$suit = static function (int $base, string $group): array {
    $eligibleSkills = match ($group) {
        'peace' => ['agricultural_policy', 'specialty_development', 'gold_vein_survey', 'forest_management', 'indomitable', 'ship_operations'],
        'combat' => ['final_defense_line', 'navy'],
        default => [],
    };

    return [[
        'type' => 'secretary_experience_double_chance',
        'chance_base_percent' => $base,
        'chance_percent_per_level' => 1,
        'chance_multiplier_numerator' => $group === 'all' ? 1 : 3,
        'chance_multiplier_denominator' => $group === 'all' ? 1 : 2,
        'multiplier' => 2,
        'sources' => $group === 'peace' ? ['passive_skill_experience'] : ['passive_skill_experience', 'monster_experience'],
        'eligible_skill_keys' => $eligibleSkills,
        'excluded_skill_keys' => ['declining_birthrate_policy'],
        'draw_unit' => 'canonical_award_event',
        'random_stream_version' => 1,
    ]];
};

$newItems = [
    'fire_charm' => ['accessory', 'regular', 3, $charm('fire')],
    'wave_charm' => ['accessory', 'regular', 3, $charm('tsunami')],
    'wind_charm' => ['accessory', 'regular', 3, $charm('typhoon')],
    'quake_charm' => ['accessory', 'regular', 3, $charm('earthquake')],
    'star_charm' => ['accessory', 'high_quality', 3, $charm('meteor_shower')],
    'gem_bow' => ['bow', 'high_quality', 15, $bow(2400, 'owned_territory', 'secretary_gem_bow', false)],
    'elven_bow' => ['bow', 'artifact', 50, $bow(4900, 'owned_territory', 'secretary_elven_bow', false)],
    'aquamarine_bow' => ['bow', 'high_quality', 15, $bow(2400, 'owned_territory_or_surface_aoi_inora', 'secretary_aquamarine_bow', false)],
    'artemis_bow' => ['bow', 'artifact', 50, $bow(4900, 'owned_territory_or_surface_aoi_inora', 'secretary_artemis_bow', false)],
    'bullseye_bow' => ['bow', 'high_quality', 15, $bow(1900, 'owned_territory', 'secretary_bullseye_bow', true)],
    'shiva_bow' => ['bow', 'artifact', 50, $bow(3900, 'owned_territory', 'secretary_shiva_bow', true)],
    'experienced_suit' => ['clothing', 'regular', 10, $suit(12, 'all')],
    'eternal_suit' => ['clothing', 'high_quality', 11, $suit(24, 'all')],
    'star_reader_suit' => ['clothing', 'artifact', 12, $suit(38, 'all')],
    'military_suit' => ['clothing', 'regular', 10, $suit(12, 'combat')],
    'marshal_suit' => ['clothing', 'high_quality', 11, $suit(24, 'combat')],
    'war_suit' => ['clothing', 'artifact', 12, $suit(38, 'combat')],
    'chancellor_suit' => ['clothing', 'regular', 10, $suit(12, 'peace')],
    'grand_chancellor_suit' => ['clothing', 'high_quality', 11, $suit(24, 'peace')],
    'star_chancellor_suit' => ['clothing', 'artifact', 12, $suit(38, 'peace')],
    'magic_white_flag' => ['accessory', 'high_quality', 1, [[
        'type' => 'monster_missile_defense_bypass',
    ]]],
    'nyowamiya_ribbon' => ['accessory', 'high_quality', 1, [[
        'type' => 'nyowamiya_ribbon', 'nyowamiya_type_weight_bonus' => 1,
    ]]],
    'love_emblem' => ['accessory', 'artifact', 1, [[
        'type' => 'population_growth_percent', 'percent' => 10,
        'applies_to' => ['ordinary', 'attraction'],
    ]]],
    'twin_star_emblem' => ['accessory', 'artifact', 1, [[
        'type' => 'final_defense_preserve_chance', 'chance_percent' => 10,
        'random_stream_version' => 1,
    ]]],
    'crescent_emblem' => ['accessory', 'artifact', 1, [[
        'type' => 'launch_base_experience_double_chance',
        'chance_percent' => 10, 'foreign_settlement_chance_percent' => 100,
        'random_stream_version' => 1,
    ]]],
];
foreach ($newItems as $key => [$category, $rarity, $maximumLevel, $effects]) {
    $secretary['items'][$key] = [
        'key' => $key, 'category' => $category, 'rarity' => $rarity,
        'tradable' => true, 'npc_tradable' => false,
        'max_level' => $maximumLevel,
        'effects' => $effects,
        'gacha_exception' => in_array($key, ['love_emblem', 'twin_star_emblem', 'crescent_emblem'], true),
    ];
}

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    'gacha_exception', 'disaster_key',
    '/secretary/items/love_emblem/effects/*/applies_to/*',
]));
foreach ($newItems as $itemKey => [, , , $effects]) {
    foreach (['sources', 'excluded_skill_keys', 'eligible_skill_keys', 'target_map_space_keys'] as $field) {
        if (($effects[0][$field] ?? []) !== []) {
            $classification['behavior'][] = "/secretary/items/{$itemKey}/effects/*/{$field}/*";
        }
    }
}
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'chance_base_percent', 'chance_multiplier_numerator', 'chance_multiplier_denominator',
    'cells_per_charge', 'nyowamiya_type_weight_bonus', 'percent', 'chance_percent',
    'foreign_settlement_chance_percent',
    '/secretary/ticket_gacha/wakuwaku_ticket/rarity_weights_basis_points/regular',
    '/secretary/ticket_gacha/wakuwaku_ticket/rarity_weights_basis_points/high_quality',
    '/secretary/ticket_gacha/wakuwaku_ticket/rarity_weights_basis_points/artifact',
    '/secretary/ticket_gacha/dokidoki_ticket/rarity_weights_basis_points/high_quality',
    '/secretary/ticket_gacha/dokidoki_ticket/rarity_weights_basis_points/artifact',
]));

return ['payload' => ['secretary' => $secretary], 'classification' => $classification];
