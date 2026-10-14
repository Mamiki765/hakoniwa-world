<?php

// Compose only the changed domains over the immutable production predecessor.
$rules = require __DIR__.'/hakoniwa-2s-plus-v36.php';
$identity = (require __DIR__.'/v37/world-and-map.php')['payload'];
$commands = (require __DIR__.'/v37/commands-and-production.php')['payload'];
$ships = (require __DIR__.'/v37/surface-ships.php')['payload'];
$rules['key'] = $identity['key'];
$rules['version'] = $identity['version'];
$rules['command_definitions'] = $commands['command_definitions'];
$rules['surface_ships'] = $ships['surface_ships'];

return $rules;
