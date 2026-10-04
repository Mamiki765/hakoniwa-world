<?php

// One release draft, composed from the exact immutable v27 predecessor.
$rules = require __DIR__.'/hakoniwa-2s-plus-v27.php';
$world = (require __DIR__.'/release-4.11/world-and-map.php')['payload'];
$economy = (require __DIR__.'/release-4.11/economy-and-resources.php')['payload'];
$facilities = (require __DIR__.'/release-4.11/facilities.php')['payload'];
$commands = (require __DIR__.'/release-4.11/commands-and-production.php')['payload'];
$disasters = (require __DIR__.'/release-4.11/terrain-and-disasters.php')['payload'];
$power = (require __DIR__.'/release-4.11/power-and-pizza.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['resource_definitions'] = $economy['resource_definitions'];
$rules['resource_sale_prices'] = $economy['resource_sale_prices'];
$rules['initial_resources'] = $economy['initial_resources'];
$rules['resource_capacities'] = $economy['resource_capacities'];
$rules['facility_definitions'] = $facilities['facility_definitions'];
$rules['command_definitions'] = $commands['command_definitions'];
$rules['turn_processing']['disasters'] = $disasters['turn_processing']['disasters'];
$rules['power_economy'] = $power['power_economy'];
$rules['secretary'] = (require __DIR__.'/release-4.11/secretary.php')['payload']['secretary'];

return $rules;
