<?php

$ruleset = require __DIR__.'/roadmap-pr14-v1.php';

$ruleset['key'] = 'roadmap-pr15-v1';
$ruleset['version'] = 1;
$ruleset['capital_minimum_population'] = 100;
$ruleset['capital_growth_maximum_population'] = 25_000;
$ruleset['capital_damage_percentages'] = [
    'facility_or_wasteland' => 10,
    'excavation_or_shallow' => 30,
    'deep_sea' => 90,
    'eruption_center' => 30,
];

$ruleset['turn_processing']['disasters'] = [
    'earthquake' => [
        'probability' => ['numerator' => 80, 'denominator' => 2_000],
        'center_padding' => 5, 'radius' => 10, 'minimum_city_population' => 10_000,
        'facility_keys' => ['factory', 'decoy'],
        'damage_probability' => ['numerator' => 1, 'denominator' => 4],
    ],
    'tsunami' => [
        'probability' => ['numerator' => 300, 'denominator' => 2_000],
        'center_padding' => 5, 'radius' => 10,
        'settlement_facility_keys' => ['village', 'town', 'city', 'capital'],
        'facility_keys' => ['farm', 'factory', 'missile_base', 'defense', 'decoy'],
        'excluded_facility_keys' => ['seabed_base', 'monument'],
        'water_facility_keys' => ['seabed_base'],
        'internal_denominator' => 12, 'adjacent_water_offset' => 1,
    ],
    'typhoon' => [
        'probability' => ['numerator' => 400, 'denominator' => 2_000],
        'center_padding' => 5, 'radius' => 10,
        'facility_keys' => ['farm', 'decoy'],
        'internal_denominator' => 12, 'base_damage_threshold' => 6,
        'protection_facility_keys' => ['monument'],
    ],
    'meteor_shower' => [
        'probability' => ['numerator' => 200, 'denominator' => 2_000],
        'center_padding' => 5, 'radius' => 10,
        'continuation_probability' => ['numerator' => 1, 'denominator' => 2],
        'seabed_facility_keys' => ['seabed_oil_field', 'seabed_base'],
    ],
    'huge_meteor' => [
        'probability' => ['numerator' => 100, 'denominator' => 2_000],
        'center_padding' => 2, 'radius' => 2,
        'seabed_facility_keys' => ['seabed_oil_field', 'seabed_base'],
    ],
    'eruption' => [
        'probability' => ['numerator' => 200, 'denominator' => 2_000],
        'center_padding' => 0, 'radius' => 1,
        'seabed_facility_keys' => ['seabed_oil_field', 'seabed_base'],
    ],
    'fire' => [
        'probability' => ['numerator' => 10, 'denominator' => 2_000],
        'minimum_city_population' => 10_000,
        'facility_keys' => ['factory', 'decoy'],
        'protection_facility_keys' => ['monument'],
    ],
];

$ruleset['turn_processing']['command_random_effects']['land_level_earthquake'] = [
    'probability' => ['numerator' => 5, 'denominator' => 2_000],
    'radius' => 10, 'minimum_city_population' => 10_000,
    'facility_keys' => ['factory', 'decoy'],
    'damage_probability' => ['numerator' => 1, 'denominator' => 4],
];

$ruleset['turn_processing']['oil_field'] = [
    'facility_key' => 'seabed_oil_field',
    'income_money' => 1_000,
    'depletion_probability' => ['numerator' => 40, 'denominator' => 1_000],
    'depleted_terrain_key' => 'sea',
];

$ruleset['turn_processing']['riot']['facility_keys'] = [
    'farm', 'factory', 'missile_base', 'seabed_base', 'defense', 'decoy',
];

return $ruleset;
