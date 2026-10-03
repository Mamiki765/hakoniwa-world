<?php

$domain = require __DIR__.'/../current/secretary.php';
$domain['payload']['secretary']['skills']['energy_saving'] = [
    'key' => 'energy_saving', 'name' => '省エネ', 'initial_level' => 0,
    // The former 150*(L+1)^2 MWh requirement divided by 100, rounded up.
    'level_requirement' => ['basis' => 'next_level_squared', 'multiplier' => 3, 'divisor' => 2],
    'effect' => ['type' => 'power_consumption_ratio', 'base' => 1000, 'numerator_per_level' => 5, 'denominator_per_level' => 7],
    'experience_source' => ['type' => 'actual_power_consumption', 'points_per_mw' => 1],
];
$domain['classification']['data'][] = '/secretary/skills/energy_saving/level_requirement/divisor';
$domain['classification']['data'][] = '/secretary/skills/energy_saving/effect/base';
$domain['classification']['data'][] = '/secretary/skills/energy_saving/effect/numerator_per_level';
$domain['classification']['data'][] = '/secretary/skills/energy_saving/effect/denominator_per_level';
$domain['classification']['data'][] = '/secretary/skills/energy_saving/experience_source/points_per_mw';

return $domain;
