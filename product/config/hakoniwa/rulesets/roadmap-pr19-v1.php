<?php

$ruleset = require __DIR__.'/roadmap-pr18-v1.php';

$ruleset['key'] = 'roadmap-pr19-v1';
$ruleset['version'] = 1;
$ruleset['resource_capacities'] = [
    'industrial_goods' => 9_999_000,
    'minerals' => 9_999_000,
];
$ruleset['resource_capacity_overflow'] = [
    'behavior' => 'sell_stockpile_overflow_then_discard_unsold',
    'applies_after_sale_policy' => true,
    'converts_unsold_to_money' => false,
    'event_type' => 'capacity.overflow',
];

foreach ($ruleset['resource_definitions'] as &$resourceDefinition) {
    if ($resourceDefinition['key'] === 'industrial_goods') {
        $resourceDefinition['unit'] = 'unit';
        $resourceDefinition['unit_label'] = 'ユニット';
    }
    if ($resourceDefinition['key'] === 'minerals') {
        $resourceDefinition['unit'] = 'ton';
        $resourceDefinition['unit_label'] = 'トン';
    }
}
unset($resourceDefinition);

return $ruleset;
