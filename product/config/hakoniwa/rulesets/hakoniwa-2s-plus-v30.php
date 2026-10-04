<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v29.php';
$world = (require __DIR__.'/release-4.12/world-and-map.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['secretary'] = (require __DIR__.'/release-4.12/secretary.php')['payload']['secretary'];

return $rules;
