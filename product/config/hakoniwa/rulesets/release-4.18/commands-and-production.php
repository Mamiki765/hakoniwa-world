<?php

$domain = require __DIR__.'/../release-4.11/commands-and-production.php';
$domain['payload']['command_definitions'][] = [
    'key' => 'build_undersea_fire_station', 'name' => '海底消防署建設',
    'description' => '自国領から3hex以内の海に建設します。維持費は毎ターン6億円。周囲2hexの海底施設の火災を1件100億円で防ぎます。',
    'target_type' => 'cell', 'target_terrain_keys' => ['sea'], 'target_facility_keys' => [],
    'requires_empty_facility' => true, 'cost_money' => 1000, 'required_resources' => [],
    'execution_phase' => 'facility', 'result_terrain_key' => 'sea',
    'result_facility_key' => 'undersea_fire_station', 'sort_order' => 126,
    'metadata' => ['consumes_turn' => true, 'execution_deferred' => false],
];

return $domain;
