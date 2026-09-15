<?php

$domain = require __DIR__.'/../v20/surface-ships.php';
$payload = $domain['payload'];

foreach ($payload['surface_ships']['definitions'] as &$definition) {
    $definition = [
        'name' => $definition['name'],
        'asset_key' => $definition['asset_key'],
        'player_buildable' => true,
        'build_selector' => $definition['build_selector'],
        'sort_order' => $definition['sort_order'],
        'build_cost_money' => $definition['build_cost_money'],
        'maximum_hp' => $definition['maximum_hp'],
        'movement_oil_units' => $definition['movement_oil_units'],
        'movement_reward_resource_key' => $definition['movement_reward_resource_key'],
        'movement_reward_resource_units' => $definition['movement_reward_resource_units'],
        'movement_reward_money' => $definition['movement_reward_money'],
        'visibility_radius' => $definition['visibility_radius'],
    ];
}
unset($definition);

$payload['surface_ships']['definitions']['pirate'] = [
    'name' => '海賊船',
    'asset_key' => 'ship.pirate',
    'player_buildable' => false,
    'build_selector' => null,
    'sort_order' => 40,
    'build_cost_money' => 0,
    'maximum_hp' => 3,
    'movement_oil_units' => 0,
    'movement_reward_resource_key' => null,
    'movement_reward_resource_units' => 0,
    'movement_reward_money' => 0,
    'visibility_radius' => 1,
];
$payload['surface_ships']['definitions']['treasure'] = [
    'name' => '宝船',
    'asset_key' => 'ship.treasure',
    'player_buildable' => false,
    'build_selector' => null,
    'sort_order' => 50,
    'build_cost_money' => 0,
    'maximum_hp' => 1,
    'movement_oil_units' => 0,
    'movement_reward_resource_key' => null,
    'movement_reward_resource_units' => 0,
    'movement_reward_money' => 0,
    'visibility_radius' => 1,
];

$classification = $domain['classification'];
$classification['behavior'][] = 'player_buildable';

return [
    'payload' => $payload,
    'classification' => $classification,
];
