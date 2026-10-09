<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v35.php';
$world = (require __DIR__.'/release-4.18.3/world-and-map.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['karma'] = (require __DIR__.'/release-4.18.3/lifecycle-and-karma.php')['payload']['karma'];

return $rules;
