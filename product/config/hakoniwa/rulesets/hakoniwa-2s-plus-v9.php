<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v8.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v9';
$ruleset['version'] = 9;
$ruleset['turn_resolution'] = [
    'normal_monster_stage' => 'after_ordinary_surface_cell_events',
];

return $ruleset;
