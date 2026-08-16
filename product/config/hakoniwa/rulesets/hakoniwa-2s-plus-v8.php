<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v7.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v8';
$ruleset['version'] = 8;
$ruleset['military']['defense_interception'] = [
    'facility_key' => 'defense',
    'radius' => 2,
    'exclude_center' => true,
    'defense_target_cells' => 'exclude',
    'missile_keys' => ['missile', 'pp_missile', 'land_destruction_missile', 'spp_missile'],
    'facility_owner_scope' => 'any',
    'monster_occupied_cells' => 'include',
    'self_fired_missiles' => 'include',
    'overlap_resolution' => 'single_interception',
    'resolve_before' => 'secretary',
];

return $ruleset;
