<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v10.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v11';
$ruleset['version'] = 11;

$displayOrders = [
    'mecha_inora' => 0,
    'inora' => 100,
    'sanjira' => 200,
    'red_inora' => 300,
    'dark_inora' => 400,
    'inora_ghost' => 500,
    'whale' => 600,
    'king_inora' => 700,
];
$manual = [
    'mecha_inora' => ['appearance' => '怪獣派遣', 'special' => '特殊能力なし'],
    'inora' => ['appearance' => '人口10万人以上で自然発生', 'special' => '特殊能力なし'],
    'sanjira' => ['appearance' => '人口10万人以上で自然発生', 'special' => '奇数ターンは硬化'],
    'red_inora' => ['appearance' => '人口25万人以上で自然発生', 'special' => '特殊能力なし'],
    'dark_inora' => ['appearance' => '人口25万人以上で自然発生', 'special' => '最大2歩移動'],
    'inora_ghost' => ['appearance' => '人口25万人以上で自然発生', 'special' => '高速移動'],
    'whale' => ['appearance' => '人口40万人以上で自然発生', 'special' => '偶数ターンは硬化'],
    'king_inora' => ['appearance' => '人口40万人以上で自然発生', 'special' => '特殊能力なし'],
];
foreach ($ruleset['monster_definitions'] as &$definition) {
    $definition['display_order'] = $displayOrders[$definition['key']];
    $definition['source_metadata']['reward_policy'] = 'standard_split';
    $definition['source_metadata']['manual'] = $manual[$definition['key']];
    $definition['source_metadata']['behavior'] = [
        'movement' => 'legacy_land',
        'dispatchable' => $definition['key'] === 'mecha_inora',
        'can_act_on_spawn_turn' => false,
        'special_action' => 'none',
        'island_creation_displaceable' => false,
    ];
}
unset($definition);

$template = $ruleset['monster_definitions'][0];
$zero = $template;
$zero['key'] = 'mecha_inora_zero';
$zero['name'] = 'メカいのら零式';
$zero['asset_key'] = 'hakoniwa_custom.monster.mecha_inora_zero';
$zero['base_hp'] = 4;
$zero['missile_base_experience'] = 35;
$zero['display_order'] = 50;
$zero['skill_description'] = 'HP1で核爆発';
$zero['source_metadata'] = [
    'reward_policy' => 'standard_split',
    'secretary_item_target_safety' => [
        'policy' => 'certain_self_action_at_remaining_hp',
        'remaining_hp' => 1,
    ],
    'manual' => ['appearance' => '怪獣派遣（9,999億円）', 'special' => 'HP1で核爆発'],
    'behavior' => [
        'movement' => 'legacy_land',
        'dispatchable' => true,
        'can_act_on_spawn_turn' => false,
        'special_action' => 'nuclear_self_destruct_at_hp_one',
        'island_creation_displaceable' => false,
    ],
];

$aoi = $template;
$aoi['key'] = 'aoi_inora';
$aoi['name'] = 'あおいのら';
$aoi['asset_key'] = 'hakoniwa_custom.monster.aoi_inora';
$aoi['base_hp'] = 2;
$aoi['hp_variation'] = 1;
$aoi['wreckage_value_money'] = 1_200;
$aoi['missile_base_experience'] = 18;
$aoi['display_order'] = 450;
$aoi['skill_description'] = '海から陸地へ侵攻し、踏み荒らした場所を中立の海へ変える';
$aoi['movement_terrain_contract'] = [
    'candidate_attempts_per_action' => 3,
    'blocked_terrain_keys' => ['mountain'],
    'blocked_facility_keys' => ['mine', 'monument', 'capital'],
    'defense_facility_key' => 'defense',
    'destination_terrain_key' => 'sea',
    'clear_owner' => true,
];
$aoi['source_metadata'] = [
    'reward_policy' => 'hostless_full_killer_money',
    'manual' => [
        'appearance' => 'Worldの海上災害',
        'special' => '海から陸地へ侵攻し中立の海へ変化。中立海上の通常撃破は撃破者へ残骸資金100%',
    ],
    'behavior' => [
        'movement' => 'water_neutralizing',
        'dispatchable' => false,
        'can_act_on_spawn_turn' => false,
        'special_action' => 'none',
        'island_creation_displaceable' => true,
        'world_spawn' => [
            'type' => 'world_aoi_disaster',
            'probability_per_active_owned_land_cell' => ['numerator' => 1, 'denominator' => 10_000],
            'maximum_probability_numerator' => 10_000,
            'terrain_keys' => ['sea', 'shallow'],
            'minimum_land_distance' => 4,
            'stream_version' => 1,
        ],
    ],
];

$ruleset['monster_definitions'][] = $zero;
$ruleset['monster_definitions'][] = $aoi;
usort(
    $ruleset['monster_definitions'],
    static fn (array $left, array $right): int => $left['display_order'] <=> $right['display_order'],
);

foreach ($ruleset['command_definitions'] as &$command) {
    if ($command['key'] !== 'monster_dispatch') {
        continue;
    }
    $command['cost_money'] = 3_000;
    $command['metadata'] = [
        'parameters' => $command['metadata']['parameters'],
        'private_command' => true,
        'quantity_selects_catalog' => 'monster_dispatch_options',
        'default_selector_value' => 1,
        'monster_dispatch_options' => [
            ['value' => 1, 'monster_key' => 'mecha_inora', 'label' => 'メカいのら', 'cost_money' => 3_000, 'enabled' => true],
            ['value' => 2, 'monster_key' => 'mecha_inora_zero', 'label' => 'メカいのら零式', 'cost_money' => 9_999, 'enabled' => true],
        ],
    ];
}
unset($command);

unset($ruleset['monster_system']['kill_stats']['maximum_species_rows_per_nation']);
$ruleset['secretary']['item_categories'] = [
    'bow' => ['key' => 'bow', 'max_equipped' => 1],
    'ring' => ['key' => 'ring', 'max_equipped' => 5],
];
$ruleset['secretary']['items'] = [
    'old_bow' => [
        'key' => 'old_bow',
        'category' => 'bow',
        'max_level' => 1,
        'same_item_max_equipped' => 1,
        'effects' => [[
            'type' => 'pre_normal_monster_attack',
            'timing' => 'after_missile_finalization_before_normal_monsters',
            'chance_basis_points' => 1_000,
            'damage' => 1,
            'damage_type' => 'secretary_old_bow',
            'target_scope' => 'owned_territory',
            'target_map_space_keys' => ['surface'],
            'target_safety_policy' => 'avoid_ineffective_or_immediate_hazard',
            'random_stream_version' => 1,
        ]],
    ],
    'ring' => [
        'key' => 'ring',
        'category' => 'ring',
        'max_level' => 10,
        'same_item_max_equipped' => 5,
        'effects' => [[
            'type' => 'finance_income_bonus',
            'bonus_money_per_level' => 1,
            'stacking' => 'sum_equipped_levels',
        ]],
    ],
];

return $ruleset;
