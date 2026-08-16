<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v5.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v6';
$ruleset['version'] = 6;

foreach ($ruleset['command_definitions'] as &$command) {
    if ($command['key'] === 'logging') {
        $command['result_terrain_key'] = 'plain';
    }

    if ($command['key'] === 'build_defense_facility') {
        $command['metadata']['owner_overbuild_effect'] = 'defense_self_destruct';
    }

    if ($command['key'] === 'build_monument') {
        $command['metadata']['owner_overbuild_effect'] = 'monument_flight';
        $command['metadata']['parameters']['target_nation_id'] = [
            'label' => '対象Nation ID',
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 2_147_483_647,
            'required' => false,
            'nullable' => true,
        ];
    }
}
unset($command);

$ruleset['military']['defense_spp_resistance'] = [
    'facility_key' => 'defense',
    'ineffective_missile_keys' => ['spp_missile'],
];

return $ruleset;
