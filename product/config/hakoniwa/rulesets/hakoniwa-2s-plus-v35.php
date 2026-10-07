<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v34.php';
$world = (require __DIR__.'/release-4.18/world-and-map.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['facility_definitions'] = (require __DIR__.'/release-4.18/facilities.php')['payload']['facility_definitions'];
$rules['command_definitions'] = (require __DIR__.'/release-4.18/commands-and-production.php')['payload']['command_definitions'];
$rules['turn_processing']['disasters'] = (require __DIR__.'/release-4.18/terrain-and-disasters.php')['payload']['turn_processing']['disasters'];
$turn = (require __DIR__.'/release-4.18/turn-pipeline.php')['payload']['turn_processing'];
$rules['turn_processing']['undersea_fire_station_maintenance'] = $turn['undersea_fire_station_maintenance'];
$rules['turn_processing']['settlement'] = $turn['settlement'];
$rules['capital_growth_maximum_population'] = (require __DIR__.'/release-4.18/terrain-and-disasters.php')['payload']['capital_growth_maximum_population'];
$rules['military'] = (require __DIR__.'/release-4.18/monsters-and-military.php')['payload']['military'];
$rules['monster_system'] = (require __DIR__.'/release-4.18/monsters-and-military.php')['payload']['monster_system'];
$rules['karma'] = (require __DIR__.'/release-4.18/lifecycle-and-karma.php')['payload']['karma'];
$rules['ocean_loop'] = (require __DIR__.'/release-4.18/ocean-loop.php')['payload']['ocean_loop'];
$rules['facility_rank_system'] = (require __DIR__.'/release-4.18/facility-ranks.php')['payload']['facility_rank_system'];
$rules['secretary'] = (require __DIR__.'/release-4.18/secretary.php')['payload']['secretary'];

return $rules;
