<?php

// The single release draft retains the immutable, production-used v38 payload.
$rules = require __DIR__.'/hakoniwa-2s-plus-v38.php';
$identity = (require __DIR__.'/v39/world-and-map.php')['payload'];
$rules['key'] = $identity['key'];
$rules['version'] = $identity['version'];
$rules['secretary'] = (require __DIR__.'/v39/secretary.php')['payload']['secretary'];
$rules['command_definitions'] = (require __DIR__.'/v39/commands-and-production.php')['payload']['command_definitions'];

return $rules;
