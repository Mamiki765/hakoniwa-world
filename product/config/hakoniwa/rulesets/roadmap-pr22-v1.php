<?php

$ruleset = require __DIR__.'/roadmap-pr21-v1.php';

$ruleset['key'] = 'roadmap-pr22-v1';
$ruleset['version'] = 1;
$ruleset['capital_relocation_cost_money'] = 1_000;
$ruleset['turn_processing']['resource_sale_phase'] = [
    'after' => 'nation_economy',
    'before' => 'development_commands',
];
$ruleset['turn_processing']['settlement']['post_ordinary_attraction_growth'] = [
    'minimum' => 100,
    'maximum' => 100,
    'unit_people' => 1,
];

foreach ($ruleset['command_definitions'] as &$existingCommand) {
    if (in_array($existingCommand['key'], ['land_clear', 'land_level', 'excavate'], true)
        && ! in_array('scorched', $existingCommand['target_terrain_keys'], true)) {
        $existingCommand['target_terrain_keys'][] = 'scorched';
    }
}
unset($existingCommand);

$emptyFacility = [
    'disguise_ownership_policy' => null,
    'scale_unit_people' => null,
    'initial_scale' => null,
    'scale_increment' => null,
    'maximum_scale' => null,
    'workforce_per_scale_people' => null,
    'production_definition_key' => null,
];

$ruleset['facility_definitions']['missile_base']['build_command_key'] = 'build_missile_base';
$ruleset['facility_definitions']['defense'] = [
    ...$emptyFacility,
    'name' => '防衛施設',
    'asset_key' => 'tile.defense',
    'visibility_policy' => 'public',
    'build_command_key' => 'build_defense_facility',
    'buildable_terrain_keys' => ['plain'],
];
$ruleset['facility_definitions']['seabed_base'] = [
    ...$emptyFacility,
    'name' => '海底基地',
    'asset_key' => 'tile.seabed_base',
    'visibility_policy' => 'disguised',
    'disguise_terrain_key' => 'sea',
    'disguise_asset_key' => 'tile.sea',
    'disguise_ownership_policy' => 'neutral',
    'build_command_key' => 'build_seabed_base',
    'buildable_terrain_keys' => ['sea'],
];
$ruleset['facility_definitions']['monument'] = [
    ...$emptyFacility,
    'name' => '記念碑',
    'asset_key' => 'tile.monument',
    'visibility_policy' => 'public',
    'build_command_key' => 'build_monument',
    'buildable_terrain_keys' => ['plain'],
];
$ruleset['facility_definitions']['decoy'] = [
    ...$emptyFacility,
    'name' => 'ハリボテ',
    'asset_key' => 'tile.decoy',
    'visibility_policy' => 'public',
    'build_command_key' => 'build_decoy',
    'buildable_terrain_keys' => ['plain'],
    'display_as_facility_key' => 'defense',
];

$allTerrains = ['sea', 'shallow', 'wasteland', 'scorched', 'plain', 'forest', 'mountain'];
$allLandTerrains = ['wasteland', 'scorched', 'plain', 'forest', 'mountain'];
$parameterTargetNation = [
    'target_nation_id' => [
        'label' => '対象Nation ID',
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 2_147_483_647,
        'required' => true,
    ],
];

$command = static fn (
    string $key,
    string $name,
    string $description,
    string $targetType,
    array $terrains,
    array $facilities,
    bool $requiresEmptyFacility,
    int $cost,
    string $phase,
    ?string $resultTerrain,
    ?string $resultFacility,
    int $sort,
    array $metadata = [],
): array => [
    'key' => $key,
    'name' => $name,
    'description' => $description,
    'target_type' => $targetType,
    'target_terrain_keys' => $terrains,
    'target_facility_keys' => $facilities,
    'requires_empty_facility' => $requiresEmptyFacility,
    'cost_money' => $cost,
    'required_resources' => [],
    'execution_phase' => $phase,
    'result_terrain_key' => $resultTerrain,
    'result_facility_key' => $resultFacility,
    'sort_order' => $sort,
    'metadata' => ['consumes_turn' => true, 'parameters' => [], ...$metadata],
];

$ruleset['command_definitions'][] = $command(
    'logging', '伐採', '所有する森を伐採し、木の価値を資金として受け取ります。',
    'cell', ['forest'], [], false, 0, 'terrain', 'wasteland', null, 80,
    ['legacy_command' => 'SellTree', 'money_per_legacy_tree_unit' => 5, 'private_coordinate' => true],
);
$ruleset['command_definitions'][] = $command(
    'territory_expand', '領土拡張', '自国領に隣接する中立の陸地を領有します。',
    'cell', $allLandTerrains, [], true, 100, 'territory', null, null, 90,
    ['legacy_command' => 'Widen', 'neutral_only' => true, 'adjacent_owned_required' => true],
);
$ruleset['command_definitions'][] = $command(
    'plant_forest', '植林', '所有する平地へ森を植えます。',
    'cell', ['plain'], [], true, 50, 'facility', 'forest', null, 100,
    ['legacy_command' => 'Plant', 'private_coordinate' => true],
);
$ruleset['command_definitions'][] = $command(
    'build_missile_base', 'ミサイル基地建設', '所有する平地へ秘密のミサイル基地を建設します。',
    'cell', ['plain'], [], true, 300, 'facility', 'plain', 'missile_base', 110,
    ['legacy_command' => 'Base', 'private_coordinate' => true],
);
$ruleset['command_definitions'][] = $command(
    'build_defense_facility', '防衛施設建設', '所有する平地へ防衛施設を建設します。',
    'cell', ['plain'], [], true, 800, 'facility', 'plain', 'defense', 120,
    ['legacy_command' => 'DBase'],
);
$ruleset['command_definitions'][] = $command(
    'build_seabed_base', '海底基地建設', '自国領から3hex以内の海へ秘密の海底基地を建設します。',
    'cell', ['sea'], [], true, 8_000, 'facility', 'sea', 'seabed_base', 130,
    ['legacy_command' => 'SBase', 'owned_within_radius' => 3, 'private_coordinate' => true],
);
$ruleset['command_definitions'][] = $command(
    'build_monument', '記念碑建設', '所有する平地へ選択した種類の記念碑を建設します。quantityは記念碑種類です。',
    'cell', ['plain'], [], true, 9_999, 'facility', 'plain', 'monument', 140,
    ['legacy_command' => 'Monument', 'quantity_selects_catalog' => 'monument_definitions'],
);
$ruleset['command_definitions'][] = $command(
    'build_decoy', '防衛施設建設', '所有する平地へ防衛施設に見えるハリボテを建設します。',
    'cell', ['plain'], [], true, 1, 'facility', 'plain', 'decoy', 150,
    ['legacy_command' => 'Haribote', 'private_actual_facility' => true],
);

foreach ([
    ['missile', 'ミサイル発射', 20, 2, false, false, 160],
    ['pp_missile', 'PPミサイル発射', 50, 1, false, false, 170],
    ['land_destruction_missile', '陸地破壊弾発射', 100, 2, true, true, 180],
    ['spp_missile', 'SPPミサイル発射', 500, 0, false, true, 190],
] as [$key, $name, $cost, $deviation, $terrainDestruction, $singleCommand, $sort]) {
    $ruleset['command_definitions'][] = $command(
        $key,
        $name,
        '指定座標へミサイルを発射します。quantityは発射予定数です。',
        'cell',
        $allTerrains,
        [],
        false,
        $cost,
        'military',
        null,
        null,
        $sort,
        [
            'per_shot_cost' => true,
            'deviation_radius' => $deviation,
            'terrain_destruction' => $terrainDestruction,
            'single_command_per_turn' => $singleCommand,
            'requires_owned_launch_base' => true,
        ],
    );
}

$ruleset['command_definitions'][] = $command(
    'monster_dispatch', '怪獣派遣', '対象active Nationへメカいのらを派遣します。',
    'nation', $allTerrains, [], false, 3_000, 'military', null, null, 200,
    ['parameters' => $parameterTargetNation, 'monster_key' => 'mecha_inora', 'private_command' => true],
);
$ruleset['command_definitions'][] = $command(
    'finance', '資金繰り', '資金繰りを行い、ruleset所定の資金を受け取ります。',
    'nation', $allTerrains, [], false, 0, 'operations', null, null, 210,
);
$ruleset['command_definitions'][] = $command(
    'money_aid', '資金援助', '対象active Nationへquantity×100億円を援助します。',
    'nation', $allTerrains, [], false, 0, 'operations', null, null, 220,
    ['parameters' => $parameterTargetNation, 'transfer_money_per_quantity' => 100, 'consumes_turn' => false],
);
$ruleset['command_definitions'][] = $command(
    'food_aid', '食料援助', '対象active Nationへquantity×1,000トンの食料を援助します。',
    'nation', $allTerrains, [], false, 0, 'operations', null, null, 230,
    ['parameters' => $parameterTargetNation, 'transfer_food_tons_per_quantity' => 1_000, 'consumes_turn' => false],
);
$ruleset['command_definitions'][] = $command(
    'attraction', '誘致活動', 'このtarget turnの人口増加を誘致活動の範囲へ拡張します。',
    'nation', $allTerrains, [], false, 1_000, 'operations', null, null, 240,
    ['legacy_command' => 'Propaganda'],
);
$ruleset['command_definitions'][] = $command(
    'relocate_capital', '首都遷都', '指定した自国の都市を新しい首都にします。',
    'cell', ['plain'], ['city'], false, 1_000, 'operations', 'plain', 'capital', 250,
    ['fixed_cost_setting' => 'capital_relocation_cost_money'],
);

$ruleset['military'] = [
    'launch_base_facility_keys' => ['missile_base', 'seabed_base'],
    'missiles' => [
        'missile' => ['cost_money_per_shot' => 20, 'deviation_radius' => 2, 'creates_terrain' => 'scorched', 'refugees' => true],
        'pp_missile' => ['cost_money_per_shot' => 50, 'deviation_radius' => 1, 'creates_terrain' => 'scorched', 'refugees' => true],
        'land_destruction_missile' => ['cost_money_per_shot' => 100, 'deviation_radius' => 2, 'creates_terrain' => null, 'refugees' => false],
        'spp_missile' => ['cost_money_per_shot' => 500, 'deviation_radius' => 0, 'creates_terrain' => 'scorched', 'refugees' => true],
    ],
    'visibility' => [
        'launch_summary' => 'public',
        'meaningful_impacts' => 'public',
        'ineffective_impacts' => 'aggregate_per_launch',
        'firing_nation_details' => 'private',
        'anonymous_missile_keys' => [],
    ],
    'dormant_impact' => [
        'explicit_target_state' => 'active',
        'no_effect_owner_states' => ['dormant_frozen', 'dormant_contestable', 'sunken_archived'],
        'preserve' => ['cell', 'facility', 'population', 'monster_occupancy'],
        'monster_exception' => false,
    ],
    'refugees' => [
        'settlement_facility_keys' => ['village', 'town', 'city', 'capital'],
        'recipient' => 'firing_nation',
        'generated_fraction' => ['numerator' => 1, 'denominator' => 2],
        'event_types' => ['refugee_generated', 'refugee_received'],
    ],
];

return $ruleset;
