<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v39.php';
$identity = (require __DIR__.'/v40/world-and-map.php')['payload'];
$rules['key'] = $identity['key'];
$rules['version'] = $identity['version'];
$rules['secretary'] = (require __DIR__.'/v40/secretary.php')['payload']['secretary'];
$rules['underground_market'] = (require __DIR__.'/v40/underground-market.php')['payload']['underground_market'];

return $rules;
