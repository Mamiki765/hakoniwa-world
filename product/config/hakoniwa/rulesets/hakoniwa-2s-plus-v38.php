<?php

// One 5.3.0 draft over the immutable, production-used v37 predecessor.
$rules = require __DIR__.'/hakoniwa-2s-plus-v37.php';
$identity = (require __DIR__.'/v38/world-and-map.php')['payload'];
$rules['key'] = $identity['key'];
$rules['version'] = $identity['version'];
$rules['ecopo'] = (require __DIR__.'/v38/ecopo.php')['payload']['ecopo'];

return $rules;
