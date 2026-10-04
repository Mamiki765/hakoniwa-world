<?php

// 4.11.1 advances the immutable v28 snapshot without changing its source.
$rules = require __DIR__.'/hakoniwa-2s-plus-v28.php';
$world = (require __DIR__.'/release-4.11.1/world-and-map.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['power_economy'] = (require __DIR__.'/release-4.11.1/power-and-pizza.php')['payload']['power_economy'];
$rules['secretary'] = (require __DIR__.'/release-4.11.1/secretary.php')['payload']['secretary'];

return $rules;
