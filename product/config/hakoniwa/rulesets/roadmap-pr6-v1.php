<?php

use App\Domain\Command\DevelopmentPlanQuantity;

$ruleset = require __DIR__.'/roadmap-pr2-v1.php';

$ruleset['key'] = 'roadmap-pr6-v1';
$ruleset['version'] = 1;
$ruleset['development_plan_quantity'] = DevelopmentPlanQuantity::contract();
$ruleset['initial_resources'] = [
    'wheat' => 10_000,
    'fish' => 0,
    'monster_meat' => 0,
    'industrial_goods' => 0,
    'minerals' => 0,
];
$ruleset['initial_island_minimum_shallow_cells'] = 3;

foreach ($ruleset['resource_definitions'] as &$resourceDefinition) {
    $resourceDefinition['unit_label'] = null;
    if ($resourceDefinition['category'] === 'food') {
        $resourceDefinition['unit'] = 'ton';
        $resourceDefinition['unit_label'] = 'トン';
    }
    if ($resourceDefinition['key'] === 'monster_meat') {
        $resourceDefinition['name'] = '怪獣肉';
    }
}
unset($resourceDefinition);

foreach ($ruleset['command_definitions'] as &$commandDefinition) {
    if (isset($commandDefinition['metadata']['parameters']['quantity'])) {
        unset($commandDefinition['metadata']['parameters']['quantity']);
        if ($commandDefinition['metadata']['parameters'] === []) {
            unset($commandDefinition['metadata']['parameters']);
        }
    }
}
unset($commandDefinition);

return $ruleset;
