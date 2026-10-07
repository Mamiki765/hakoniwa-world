<?php

$domain = require __DIR__.'/../current/monsters-and-military.php';
$domain['payload']['military']['seabed_base_resistance']['facility_keys'][] = 'undersea_fire_station';
$domain['payload']['monster_system']['natural_spawn']['rescue'] = [
    'monster_keys' => ['king_inora', 'nyowamiya', 'mecha_inora_zero'],
    'empty_terrain_keys' => ['plain', 'wasteland'],
    'large_city_facility_key' => 'city',
    'eligibility' => 'existing_population_and_industrial_rank_conditions',
    'trigger' => 'no_normal_candidate',
];
foreach (['monster_keys', 'empty_terrain_keys'] as $field) {
    $domain['classification']['behavior'][] = '/monster_system/natural_spawn/rescue/'.$field.'/*';
}
foreach (['large_city_facility_key', 'eligibility', 'trigger'] as $field) {
    $domain['classification']['behavior'][] = $field;
}

return $domain;
