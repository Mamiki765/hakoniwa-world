<?php

$domain = require __DIR__.'/../release-4.11.1/secretary.php';
$skills = &$domain['payload']['secretary']['skills'];
$skills['oil_development'] = [
    'key' => 'oil_development',
    'name' => '油田開発',
    'initial_level' => 0,
    'level_requirement' => ['basis' => 'next_level_squared', 'multiplier' => 1],
    'effect' => ['type' => 'oil_field_production_addition', 'units_per_level_per_field' => 1],
    'experience_source' => ['type' => 'successful_oil_discovery', 'points_per_discovery' => 1],
];
$skills['ship_operations']['effect'] = [
    'type' => 'ship_reward_multiplier',
    'per_mille_per_level' => 10,
    'rounding' => 'floor_after_multiplier',
];
$skills['navy']['effect'] = [
    'type' => 'warship_damage_evasion',
    'maximum_percent' => 15,
    'level_offset' => 20,
    'random_stream_version' => 1,
];
$domain['classification']['data'][] = 'units_per_level_per_field';
$domain['classification']['data'][] = 'points_per_discovery';
$domain['classification']['data'][] = 'maximum_percent';
$domain['classification']['data'][] = 'level_offset';
// All placeholders have been replaced; this selector would otherwise be unused.
$domain['classification']['flavor'] = array_values(array_diff($domain['classification']['flavor'], ['display']));

return $domain;
