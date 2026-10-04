<?php

$domain = require __DIR__.'/../current/economy-and-resources.php';
$domain['payload']['resource_definitions'][] = [
    'key' => 'power', 'name' => '電力', 'category' => 'energy', 'unit' => 'MW',
    'nutrition_per_unit' => null, 'storable' => true, 'tradable' => false,
    'sale_price_key' => 'sale.power', 'sort_order' => 70,
    'metadata' => [], 'unit_label' => 'MW',
];
$domain['payload']['resource_sale_prices']['sale.power'] = 0;
$domain['payload']['initial_resources']['power'] = 0;
$domain['payload']['resource_capacities']['power'] = 0;
$domain['classification']['data'][] = 'power';
$domain['classification']['data'][] = 'sale.power';

return $domain;
