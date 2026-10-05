<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v31.php';
$world = (require __DIR__.'/natural-fire-fix/world-and-map.php')['payload'];
$terrain = (require __DIR__.'/natural-fire-fix/terrain-and-disasters.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['turn_processing']['disasters']['fire'] = $terrain['turn_processing']['disasters']['fire'];

return $rules;
