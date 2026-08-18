<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v9.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v10';
$ruleset['version'] = 10;
$ruleset['turn_processing']['food']['production_overflow_resolution_stage'] =
    'after_population_nutrition_consumption';

return $ruleset;
