<?php

$domain = require __DIR__.'/../v18/monsters-and-military.php';
$payload = $domain['payload'];

$existingNaturalPool = $payload['monster_system']['natural_spawn']['population_tiers'][2]['monster_keys'];
$payload['monster_definitions'][1]['natural_spawn_tier'] = 4;
$payload['monster_definitions'][1]['source_metadata']['traits'] = ['自爆'];
$payload['monster_definitions'][1]['source_metadata']['manual']['appearance'] =
    '怪獣派遣（9,999億円）／人口50万人以上かつランク2施設があるNationで自然発生';
$payload['monster_definitions'][5]['source_metadata']['traits'] = ['二歩移動'];
$payload['monster_definitions'][7]['source_metadata']['traits'] = ['無限移動'];
$payload['monster_definitions'][] = [
    'key' => 'nyowamiya',
    'name' => '珍獣ニョワミヤ',
    'asset_key' => 'hakoniwa_custom.monster.nyowamiya',
    'hardened_asset_key' => null,
    'base_hp' => 1,
    'hp_variation' => 0,
    'skill_key' => 'none',
    'movement_limit' => 1,
    'natural_spawn_tier' => 4,
    'wreckage_value_money' => 2000,
    'missile_base_experience' => 20,
    'experience_per_damage' => 20,
    'skill_description' => 'なんかヘンな怪獣。',
    'visibility' => 'public',
    'movement_terrain_contract' => [
        'candidate_attempts_per_action' => 3,
        'blocked_terrain_keys' => ['sea', 'shallow', 'mountain'],
        'blocked_facility_keys' => ['seabed_oil_field', 'seabed_base', 'mine', 'monument', 'capital'],
        'defense_facility_key' => 'defense',
        'destination_terrain_key' => 'plain',
        'preserve_owner' => true,
    ],
    'trample_contract' => [
        'population_after' => 0,
        'remove_facility' => true,
        'restore_previous_terrain' => false,
    ],
    'hardening_contract' => ['type' => 'none'],
    'source_metadata' => [
        'filename' => 'monsnyowa.gif',
        'reward_policy' => 'standard_split',
        'manual' => [
            'appearance' => '人口50万人以上かつランク2施設があるNationで自然発生',
            'special' => '先行移動、ニョワミヤ',
        ],
        'traits' => ['先行移動', 'ニョワミヤ'],
        'behavior' => [
            'movement' => 'legacy_land',
            'dispatchable' => false,
            'can_act_on_spawn_turn' => false,
            'special_action' => 'none',
            'island_creation_displaceable' => false,
            'movement_stage' => 'before_surface_cell_processing',
            'defeat_terrain_key' => 'plain',
        ],
    ],
    'display_order' => 750,
];

$payload['monster_system']['natural_spawn']['population_tiers'][] = [
    'minimum_population' => 500000,
    'monster_keys' => [...$existingNaturalPool, 'nyowamiya', 'mecha_inora_zero'],
];
$payload['monster_system']['natural_spawn']['rank_two_condition'] = [
    'facility_keys' => ['farm', 'factory', 'mine'],
    'conditional_monster_keys' => ['nyowamiya', 'mecha_inora_zero'],
    'fallback_monster_keys' => $existingNaturalPool,
    'fallback_selection' => 'single_uniform_draw_no_retry',
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    '/monster_system/natural_spawn/rank_two_condition/facility_keys/*',
    '/monster_system/natural_spawn/rank_two_condition/conditional_monster_keys/*',
    '/monster_system/natural_spawn/rank_two_condition/fallback_monster_keys/*',
    'fallback_selection',
    'movement_stage',
    'defeat_terrain_key',
]));
$classification['data'][] = '/monster_definitions/10/natural_spawn_tier';
$classification['flavor'][] = '/monster_definitions/*/source_metadata/traits/*';

return ['payload' => $payload, 'classification' => $classification];
