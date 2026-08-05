<?php

$ruleset = require __DIR__.'/roadmap-pr19-v1.php';

$ruleset['key'] = 'roadmap-pr21-v1';
$ruleset['version'] = 1;

$movementContract = [
    'candidate_attempts_per_action' => 3,
    'blocked_terrain_keys' => ['sea', 'shallow', 'mountain'],
    'blocked_facility_keys' => ['seabed_oil_field', 'seabed_base', 'mine', 'monument', 'capital'],
    'defense_facility_key' => 'defense',
    'destination_terrain_key' => 'wasteland',
    'preserve_owner' => true,
];
$trampleContract = [
    'population_after' => 0,
    'remove_facility' => true,
    'restore_previous_terrain' => false,
];

$ruleset['monster_definitions'] = [
    [
        'key' => 'mecha_inora', 'name' => 'メカいのら',
        'asset_key' => 'hakoniwa_original.monster.mecha_inora', 'hardened_asset_key' => null,
        'base_hp' => 2, 'hp_variation' => 0, 'skill_key' => 'none', 'movement_limit' => 1,
        'natural_spawn_tier' => null, 'wreckage_value_money' => 0, 'missile_base_experience' => 5,
        'skill_description' => '特殊能力なし（最大1歩移動）', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'none'],
        'source_metadata' => ['kind' => 0, 'skill_code' => 0, 'filename' => 'monster7.gif'],
    ],
    [
        'key' => 'inora', 'name' => 'いのら',
        'asset_key' => 'hakoniwa_original.monster.inora', 'hardened_asset_key' => null,
        'base_hp' => 1, 'hp_variation' => 1, 'skill_key' => 'none', 'movement_limit' => 1,
        'natural_spawn_tier' => 1, 'wreckage_value_money' => 400, 'missile_base_experience' => 5,
        'skill_description' => '特殊能力なし（最大1歩移動）', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'none'],
        'source_metadata' => ['kind' => 1, 'skill_code' => 0, 'filename' => 'monster0.gif'],
    ],
    [
        'key' => 'sanjira', 'name' => 'サンジラ',
        'asset_key' => 'hakoniwa_original.monster.sanjira',
        'hardened_asset_key' => 'hakoniwa_original.monster.hardened',
        'base_hp' => 1, 'hp_variation' => 1, 'skill_key' => 'harden_odd', 'movement_limit' => 1,
        'natural_spawn_tier' => 1, 'wreckage_value_money' => 500, 'missile_base_experience' => 7,
        'skill_description' => '奇数ターンは硬化し、移動と通常ダメージを無効化', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'target_turn_parity', 'parity' => 'odd'],
        'source_metadata' => ['kind' => 2, 'skill_code' => 3, 'filename' => 'monster5.gif'],
    ],
    [
        'key' => 'red_inora', 'name' => 'レッドいのら',
        'asset_key' => 'hakoniwa_original.monster.red_inora', 'hardened_asset_key' => null,
        'base_hp' => 3, 'hp_variation' => 1, 'skill_key' => 'none', 'movement_limit' => 1,
        'natural_spawn_tier' => 2, 'wreckage_value_money' => 1_000, 'missile_base_experience' => 12,
        'skill_description' => '特殊能力なし（最大1歩移動）', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'none'],
        'source_metadata' => ['kind' => 3, 'skill_code' => 0, 'filename' => 'monster1.gif'],
    ],
    [
        'key' => 'dark_inora', 'name' => 'ダークいのら',
        'asset_key' => 'hakoniwa_original.monster.dark_inora', 'hardened_asset_key' => null,
        'base_hp' => 2, 'hp_variation' => 1, 'skill_key' => 'move_2', 'movement_limit' => 2,
        'natural_spawn_tier' => 2, 'wreckage_value_money' => 800, 'missile_base_experience' => 15,
        'skill_description' => '1ターンに最大2歩移動', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'none'],
        'source_metadata' => ['kind' => 4, 'skill_code' => 1, 'filename' => 'monster2.gif'],
    ],
    [
        'key' => 'inora_ghost', 'name' => 'いのらゴースト',
        'asset_key' => 'hakoniwa_original.monster.inora_ghost', 'hardened_asset_key' => null,
        'base_hp' => 1, 'hp_variation' => 0, 'skill_key' => 'move_9999', 'movement_limit' => 9_999,
        'natural_spawn_tier' => 2, 'wreckage_value_money' => 300, 'missile_base_experience' => 10,
        'skill_description' => 'randomized cell順に従い最大9,999歩移動', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'none'],
        'source_metadata' => ['kind' => 5, 'skill_code' => 2, 'filename' => 'monster8.gif'],
    ],
    [
        'key' => 'whale', 'name' => 'クジラ',
        'asset_key' => 'hakoniwa_original.monster.kujira',
        'hardened_asset_key' => 'hakoniwa_original.monster.hardened',
        'base_hp' => 4, 'hp_variation' => 1, 'skill_key' => 'harden_even', 'movement_limit' => 1,
        'natural_spawn_tier' => 3, 'wreckage_value_money' => 1_500, 'missile_base_experience' => 20,
        'skill_description' => '偶数ターンは硬化し、移動と通常ダメージを無効化', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'target_turn_parity', 'parity' => 'even'],
        'source_metadata' => ['kind' => 6, 'skill_code' => 4, 'filename' => 'monster6.gif'],
    ],
    [
        'key' => 'king_inora', 'name' => 'キングいのら',
        'asset_key' => 'hakoniwa_original.monster.king_inora', 'hardened_asset_key' => null,
        'base_hp' => 5, 'hp_variation' => 1, 'skill_key' => 'none', 'movement_limit' => 1,
        'natural_spawn_tier' => 3, 'wreckage_value_money' => 2_000, 'missile_base_experience' => 30,
        'skill_description' => '特殊能力なし（最大1歩移動）', 'visibility' => 'public',
        'movement_terrain_contract' => $movementContract, 'trample_contract' => $trampleContract,
        'hardening_contract' => ['type' => 'none'],
        'source_metadata' => ['kind' => 7, 'skill_code' => 0, 'filename' => 'monster3.gif'],
    ],
];

$ruleset['monster_system'] = [
    'footprint_cells' => 1,
    'natural_spawn' => [
        'probability_per_land_cell' => ['numerator' => 2, 'denominator' => 10_000],
        'maximum_probability_numerator' => 10_000,
        'one_draw_per_active_nation' => true,
        'eligible_nation_state' => 'active',
        'minimum_population' => 100_000,
        'population_tiers' => [
            ['minimum_population' => 100_000, 'monster_keys' => ['inora', 'sanjira']],
            ['minimum_population' => 250_000, 'monster_keys' => ['inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost']],
            ['minimum_population' => 400_000, 'monster_keys' => ['inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost', 'whale', 'king_inora']],
        ],
        'settlement_facility_keys' => ['village', 'town', 'city'],
        'exclude_capital' => true,
        'maximum_per_nation_per_turn' => 1,
        'selection' => 'uniform_source_pool',
        'stream_version' => 1,
    ],
    'movement' => $movementContract,
    'reward' => [
        'killer_money_share' => 'floor_half',
        'host_remainder_share' => true,
        'host_food_resource_key' => 'monster_meat',
        'food_tons_per_money_unit' => 500,
        'missile_base_experience_maximum' => 200,
    ],
    'terrain_events' => [
        'preserve_occupancy' => ['earthquake', 'tsunami', 'typhoon'],
        'remove_without_rewards' => ['meteor_shower', 'huge_meteor', 'eruption', 'land_subsidence', 'defense_self_destruct', 'terrain_destruction_missile'],
    ],
    'kill_stats' => [
        'scope' => 'nation_monster_definition',
        'increment_on_attributed_final_blow' => true,
        'authoritative_for_final_blow_count' => true,
        'authoritative_for_kill_marks' => true,
        'maximum_species_rows_per_nation' => 8,
    ],
];

return $ruleset;
