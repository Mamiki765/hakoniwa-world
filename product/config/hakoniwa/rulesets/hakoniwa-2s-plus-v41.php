<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v40.php';
$identity = (require __DIR__.'/v41/world-and-map.php')['payload'];
$rules['key'] = $identity['key'];
$rules['version'] = $identity['version'];
$rules['underground_market'] = (require __DIR__.'/v41/underground-market.php')['payload']['underground_market'];

return $rules;
