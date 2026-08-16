<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v6.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v7';
$ruleset['version'] = 7;
$ruleset['secretary'] = [
    'skills' => [
        'agricultural_policy' => [
            'key' => 'agricultural_policy',
            'name' => '農業政策',
            'initial_level' => 0,
            'level_requirement' => [
                'basis' => 'next_level_squared',
                'multiplier' => 1,
            ],
            'effect' => [
                'type' => 'production_multiplier',
                'resource_key' => 'wheat',
                'per_mille_per_level' => 1,
            ],
            'experience_source' => [
                'type' => 'successful_command_execution',
                'command_key' => 'build_farm',
                'points_per_execution' => 1,
                'quantity_multiplier' => false,
            ],
        ],
        'specialty_development' => [
            'key' => 'specialty_development',
            'name' => '特産品開発',
            'initial_level' => 0,
            'level_requirement' => [
                'basis' => 'next_level_squared',
                'multiplier' => 1,
            ],
            'effect' => [
                'type' => 'production_multiplier',
                'resource_key' => 'industrial_goods',
                'per_mille_per_level' => 1,
            ],
            'experience_source' => [
                'type' => 'successful_command_execution',
                'command_key' => 'build_factory',
                'points_per_execution' => 1,
                'quantity_multiplier' => false,
            ],
        ],
        'gold_vein_survey' => [
            'key' => 'gold_vein_survey',
            'name' => '金鉱脈調査',
            'initial_level' => 0,
            'level_requirement' => [
                'basis' => 'next_level_squared',
                'multiplier' => 1,
            ],
            'effect' => [
                'type' => 'production_multiplier',
                'resource_key' => 'minerals',
                'per_mille_per_level' => 1,
            ],
            'experience_source' => [
                'type' => 'successful_command_execution',
                'command_key' => 'build_mine',
                'points_per_execution' => 1,
                'quantity_multiplier' => false,
            ],
        ],
        'final_defense_line' => [
            'key' => 'final_defense_line',
            'name' => '最終防衛ライン',
            'initial_level' => 1,
            'level_requirement' => [
                'basis' => 'current_level_squared',
                'multiplier' => 100,
            ],
            'effect' => [
                'type' => 'final_defense_line',
                'interceptions_per_level_per_turn' => 1,
                'normal_defense_resolves_first' => true,
                'exclude_monster_occupied_cells' => true,
            ],
            'experience_source' => [
                'type' => 'owned_cell_missile_arrival',
                'points_per_missile' => 1,
                'include_normal_defense_interception' => true,
                'include_secretary_interception' => true,
                'include_actual_impact' => true,
                'include_self_fired_collateral' => true,
                'independent_from_interception_eligibility' => true,
            ],
        ],
    ],
];

return $ruleset;
