<?php

$domain = require __DIR__.'/../current/facilities.php';
foreach ([
    'wind_power' => ['風力発電所', null],
    'condenser' => ['コンデンサ', null],
    'pizzeria' => ['ピザ屋', 3],
    'thermal_power' => ['火力発電所', 2],
] as $key => [$name, $initialScale]) {
    $domain['payload']['facility_definitions'][$key] = [
        'name' => $name, 'asset_key' => 'tile.'.$key, 'visibility_policy' => 'public',
        'build_command_key' => 'build_'.$key,
        'scale_unit_people' => $initialScale === null ? null : 0,
        'initial_scale' => $initialScale,
        'scale_increment' => $initialScale === null ? null : 1,
        'maximum_scale' => $initialScale === null ? null : ($key === 'thermal_power' ? 6 : 10),
        'workforce_per_scale_people' => $initialScale === null ? null : 0,
        'production_definition_key' => null,
        'buildable_terrain_keys' => ['plain'],
    ];
    $domain['classification']['behavior'][] = '/facility_definitions/'.$key.'/buildable_terrain_keys/*';
    foreach (['scale_unit_people', 'initial_scale', 'scale_increment', 'maximum_scale', 'workforce_per_scale_people'] as $field) {
        $domain['classification'][$initialScale === null ? 'behavior' : 'data'][] = '/facility_definitions/'.$key.'/'.$field;
    }
}

return $domain;
