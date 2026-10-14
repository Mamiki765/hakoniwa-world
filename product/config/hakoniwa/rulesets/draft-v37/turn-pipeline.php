<?php

return [
    'payload' => [
        'turn_processing' => [
            'riot' => [
                'facility_keys' => [
                    'farm',
                    'factory',
                    'missile_base',
                    'defense',
                    'decoy',
                ],
            ],
        ],
    ],
    'classification' => [
        'behavior' => ['/turn_processing/riot/facility_keys/*'],
        'data' => [],
        'flavor' => [],
    ],
];
