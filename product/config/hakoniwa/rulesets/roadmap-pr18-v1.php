<?php

$ruleset = require __DIR__.'/roadmap-pr15-v1.php';

$ruleset['key'] = 'roadmap-pr18-v1';
$ruleset['version'] = 1;
$ruleset['turn_processing']['disasters']['land_subsidence'] = [
    'enabled' => true,
    'base_safe_land_cells' => 100,
    'probability' => ['numerator' => 2, 'denominator' => 100],
    'affected_shallow_result' => 'sea',
    'affected_coastal_land_result' => 'shallow',
    'mountain_immune' => true,
    'capital_damage_percentage' => 30,
    'out_of_bounds_is_water' => true,
    'stream_version' => 1,
];

return $ruleset;
