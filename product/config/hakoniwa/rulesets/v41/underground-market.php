<?php

return [
    'payload' => ['underground_market' => [
        'listing_limit' => 5, 'listing_expiration' => 'none', 'proceeds_numerator' => 9,
        'proceeds_denominator' => 10, 'page_size' => 20,
    ]],
    'classification' => [
        'behavior' => ['listing_expiration'],
        'data' => ['listing_limit', 'proceeds_numerator', 'proceeds_denominator', 'page_size'],
        'flavor' => [],
    ],
];
