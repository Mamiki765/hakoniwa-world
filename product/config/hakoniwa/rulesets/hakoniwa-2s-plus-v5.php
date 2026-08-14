<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v4.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v5';
$ruleset['version'] = 5;

$settlement = &$ruleset['turn_processing']['settlement'];
unset($settlement['sea_edge_bands']);
$settlement['ordinary_maximum_population'] = 10_000;
$settlement['ordinary_growth']['maximum'] = 1_000;
$settlement['attraction_growth']['maximum'] = 3_000;
$settlement['post_ordinary_attraction_growth']['maximum'] = 300;
unset($settlement);

return $ruleset;
