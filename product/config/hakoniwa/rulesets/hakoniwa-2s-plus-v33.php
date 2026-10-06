<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v32.php';
$world = (require __DIR__.'/island-secretary/world-and-map.php')['payload'];
$achievements = (require __DIR__.'/island-secretary/user-achievements.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['user_achievements'] = $achievements['user_achievements'];

return $rules;
