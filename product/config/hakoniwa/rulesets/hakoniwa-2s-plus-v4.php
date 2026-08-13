<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v3.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v4';
$ruleset['version'] = 4;
$ruleset['facility_definitions']['seabed_base'] = [
    ...$ruleset['facility_definitions']['seabed_base'],
    'initial_experience' => 0,
    'maximum_experience' => 200,
    'level_thresholds' => [50, 200],
    'launch_capacity_by_level' => [1 => 1, 2 => 2, 3 => 3],
];
$ruleset['military']['launch_base_experience'] = [
    'facility_keys' => ['missile_base', 'seabed_base'],
    'settlement_hit' => [
        'missile_keys' => ['missile', 'pp_missile', 'spp_missile'],
        'population_divisor' => 2_000,
        'capital_population_loss_multiplier' => 2,
    ],
    'monster_damage_experience' => 0,
    'monster_final_blow_experience' => 'monster_definition.missile_base_experience',
];
$ruleset['military']['seabed_base_resistance'] = [
    'facility_key' => 'seabed_base',
    'ineffective_missile_keys' => ['missile', 'pp_missile', 'spp_missile'],
    'destructive_missile_keys' => ['land_destruction_missile'],
];

return $ruleset;
