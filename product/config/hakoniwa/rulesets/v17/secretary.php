<?php

$domain = require __DIR__.'/../current/secretary.php';
$secretary = $domain['payload']['secretary'];

$secretary['skills']['declining_birthrate_policy'] = [
    'key' => 'declining_birthrate_policy',
    'name' => '少子化対策',
    'initial_level' => 0,
    'level_requirement' => [
        'basis' => 'triangular_growth',
        'multiplier' => 10000,
        'accounting' => 'cumulative_non_consuming',
    ],
    'effect' => [
        'type' => 'settlement_population_limits',
        'natural_maximum_per_level' => 50,
        'attraction_maximum_per_level' => 100,
        'capital_maximum_modifier' => 0,
    ],
    'experience_source' => [
        'type' => 'nation_population_high_water_increase',
        'checkpoint' => 'nations.population_high_water',
        'timing' => 'final_population_before_secretary_experience_flush',
        'historical_backfill' => 'authoritative_turn_summary_and_current_population_only',
    ],
];
$secretary['skills']['indomitable'] = [
    'key' => 'indomitable',
    'name' => '不屈',
    'initial_level' => 0,
    'level_requirement' => [
        'basis' => 'triangular_growth',
        'multiplier' => 10000,
        'accounting' => 'consume_required_carry_remainder',
    ],
    'effect' => [
        'type' => 'natural_population_growth_percent',
        'basis_points_per_level' => 25,
        'rounding' => 'floor',
        'maximum' => 'effective_natural_maximum',
        'extra_random_draw' => false,
    ],
    'experience_source' => [
        'type' => 'turn_net_population_loss',
        'calculation' => 'max_zero_start_population_minus_end_population',
        'timing' => 'final_population_before_secretary_experience_flush',
        'historical_backfill' => 'authoritative_turn_summary_only',
    ],
];

$secretary['items']['secretary_suit']['effects'][0]['excluded_skill_keys'] = [
    'declining_birthrate_policy',
];

$secretary['item_rarities'] = [
    'novice' => ['key' => 'novice', 'name' => 'ノービス', 'fixed_sale_price_money' => 100],
    'regular' => ['key' => 'regular', 'name' => 'レギュラー', 'fixed_sale_price_money' => 500],
    'cursed' => ['key' => 'cursed', 'name' => 'カースド', 'fixed_sale_price_money' => 1],
];
$secretary['items']['old_bow']['tradable'] = false;
$secretary['items']['old_bow']['npc_tradable'] = false;
$secretary['items']['elf_bow'] = [
    'key' => 'elf_bow',
    'category' => 'bow',
    'rarity' => 'regular',
    'tradable' => true,
    'npc_tradable' => false,
    'max_level' => 10,
    'effects' => [[
        'type' => 'pre_normal_monster_attack',
        'timing' => 'after_missile_finalization_before_normal_monsters',
        'chance_base_basis_points' => 1100,
        'chance_basis_points_per_level' => 100,
        'damage' => 1,
        'damage_type' => 'secretary_elf_bow',
        'target_scope' => 'owned_territory',
        'target_map_space_keys' => ['surface'],
        'target_safety_policy' => 'avoid_ineffective_or_immediate_hazard',
        'random_stream_version' => 1,
    ]],
];
$secretary['items']['longshot_bow'] = [
    'key' => 'longshot_bow',
    'category' => 'bow',
    'rarity' => 'regular',
    'tradable' => true,
    'npc_tradable' => false,
    'max_level' => 10,
    'effects' => [[
        'type' => 'pre_normal_monster_attack',
        'timing' => 'after_missile_finalization_before_normal_monsters',
        'chance_base_basis_points' => 1100,
        'chance_basis_points_per_level' => 100,
        'damage' => 1,
        'damage_type' => 'secretary_longshot_bow',
        'target_scope' => 'owned_territory_or_surface_aoi_inora',
        'target_map_space_keys' => ['surface'],
        'target_safety_policy' => 'avoid_ineffective_or_immediate_hazard',
        'random_stream_version' => 1,
    ]],
];
$secretary['items']['mechanical_bow'] = [
    'key' => 'mechanical_bow',
    'category' => 'bow',
    'rarity' => 'regular',
    'tradable' => true,
    'npc_tradable' => false,
    'max_level' => 10,
    'effects' => [[
        'type' => 'pre_normal_monster_attack',
        'timing' => 'after_missile_finalization_before_normal_monsters',
        'chance_base_basis_points' => 900,
        'chance_basis_points_per_level' => 100,
        'damage' => 1,
        'damage_type' => 'secretary_mechanical_bow',
        'target_scope' => 'owned_territory',
        'target_map_space_keys' => ['surface'],
        'target_safety_policy' => 'avoid_ineffective_or_immediate_hazard',
        'finisher' => [
            'current_hp' => 2,
            'damage' => 2,
            'chance_multiplier_numerator' => 2,
            'chance_multiplier_denominator' => 5,
            'requires_damage_one_safety_rejection' => true,
            'requires_damage_two_kill' => true,
        ],
        'random_stream_version' => 1,
    ]],
];
$secretary['items']['collar'] = [
    'key' => 'collar',
    'category' => 'accessory',
    'rarity' => 'cursed',
    'tradable' => true,
    'npc_tradable' => false,
    'max_level' => 11,
    'effects' => [
        [
            'type' => 'refugee_generation_percent',
            'minimum_start_karma' => 1,
            'base_percent' => 4,
            'percent_per_level' => 1,
            'rounding' => 'floor',
            'apply_after' => 'karma_refugee_generation',
        ],
        [
            'type' => 'karma_crime_double_chance',
            'minimum_start_karma' => 1,
            'base_percent' => 4,
            'percent_per_level' => 1,
            'multiplier' => 2,
            'facility_keys' => ['village', 'town', 'city', 'capital'],
            'draw_unit' => 'qualifying_impact',
            'snapshot_timing' => 'turn_start',
            'random_stream_version' => 1,
        ],
    ],
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    'apply_after', 'facility_keys', 'requires_damage_one_safety_rejection',
    'requires_damage_two_kill', 'minimum_start_karma', 'accounting', 'checkpoint',
    'calculation', 'maximum', 'extra_random_draw',
    '/secretary/items/secretary_suit/effects/*/excluded_skill_keys/*',
]));
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'fixed_sale_price_money', 'chance_base_basis_points', 'chance_basis_points_per_level',
    'base_percent', 'chance_multiplier_numerator', 'chance_multiplier_denominator', 'current_hp',
    'natural_maximum_per_level', 'attraction_maximum_per_level', 'capital_maximum_modifier',
    'basis_points_per_level',
]));

return [
    'payload' => ['secretary' => $secretary],
    'classification' => $classification,
];
