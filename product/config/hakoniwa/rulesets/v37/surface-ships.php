<?php

$domain = require __DIR__.'/../current/surface-ships.php';
$domain['payload']['surface_ships']['definitions']['undersea_construction'] = [
    'name' => '海底工作船',
    'asset_key' => 'ship.undersea_construction',
    'player_buildable' => true,
    'build_selector' => 5,
    'sort_order' => 70,
    'build_cost_money' => 10000,
    'maximum_hp' => 1,
    'movement_oil_units' => 10,
    'movement_reward_resource_key' => null,
    'movement_reward_resource_units' => 0,
    'movement_reward_money' => 0,
    'visibility_radius' => 1,
    'movement_mode' => 'heading_only',
    'combat_role' => 'none',
    'capacity_per_nation' => 1,
    'visible_only_in_sight' => true,
    'undersea_construction' => true,
    'construction_cost_multiplier' => 3,
    'foreign_sink_karma' => 0,
];
$domain['classification']['behavior'][] = 'visible_only_in_sight';
$domain['classification']['behavior'][] = 'undersea_construction';
$domain['classification']['data'][] = 'capacity_per_nation';
$domain['classification']['data'][] = 'construction_cost_multiplier';
$domain['classification']['data'][] = '/surface_ships/definitions/undersea_construction/foreign_sink_karma';

return $domain;
