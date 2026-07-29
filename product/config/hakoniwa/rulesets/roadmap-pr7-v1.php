<?php

$ruleset = require __DIR__.'/roadmap-pr6-v1.php';

$ruleset['key'] = 'roadmap-pr7-v1';
$ruleset['version'] = 1;
$ruleset['base_money_capacity'] = 9_999;
$ruleset['base_food_capacity_tons'] = 999_900;
$ruleset['inventory_sale_rates'] = [
    'industrial_goods' => ['inventory_units' => 1_000, 'money_units' => 1],
    'minerals' => ['inventory_units' => 1_000, 'money_units' => 1],
];

return $ruleset;
