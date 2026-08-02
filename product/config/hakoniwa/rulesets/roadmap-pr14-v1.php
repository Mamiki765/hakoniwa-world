<?php

$ruleset = require __DIR__.'/roadmap-pr11-v1.php';

$ruleset['key'] = 'roadmap-pr14-v1';
$ruleset['version'] = 1;
$ruleset['facility_definitions']['seabed_oil_field'] = [
    'name' => '海底油田',
    'asset_key' => 'tile.seabed_oil_field',
    'visibility_policy' => 'public',
    'build_command_key' => 'excavate',
    'scale_unit_people' => null,
    'initial_scale' => null,
    'scale_increment' => null,
    'maximum_scale' => null,
    'workforce_per_scale_people' => null,
    'production_definition_key' => null,
    'buildable_terrain_keys' => ['sea'],
];

foreach ($ruleset['command_definitions'] as &$commandDefinition) {
    if ($commandDefinition['key'] === 'reclaim') {
        $commandDefinition['description'] = '海を中立の浅瀬へ、浅瀬を自国の荒地へ変更します。浅瀬の埋め立てでは周囲の水域も浅瀬化する場合があります。';
        unset($commandDefinition['metadata']['neighbor_validation_at_execution']);
        $commandDefinition['metadata']['adjacent_water_spread_maximum'] = 3;
    }
    if ($commandDefinition['key'] === 'excavate') {
        $commandDefinition['description'] = '陸地を浅瀬へ、浅瀬を海へ、山を荒地へ変更します。海では数量に応じて海底油田を探索します。';
        unset($commandDefinition['metadata']['oil_search_deferred']);
        $commandDefinition['metadata']['oil_search_effect_key'] = 'seabed_oil_search';
    }
}
unset($commandDefinition);

$ruleset['turn_processing']['command_random_effects']['seabed_oil_search'] = [
    'facility_key' => 'seabed_oil_field',
    'draw_denominator' => 100,
    'success_threshold_per_cost_unit' => 1,
];

return $ruleset;
