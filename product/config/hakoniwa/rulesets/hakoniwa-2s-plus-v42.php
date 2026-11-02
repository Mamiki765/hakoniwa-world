<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v41.php';
$identity = (require __DIR__.'/v42/world-and-map.php')['payload'];
$rules['key'] = $identity['key'];
$rules['version'] = $identity['version'];
$rules['command_definitions'] = (require __DIR__.'/v42/commands-and-production.php')['payload']['command_definitions'];

return $rules;
