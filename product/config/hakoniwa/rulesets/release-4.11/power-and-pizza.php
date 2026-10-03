<?php

// Release draft fragment. Compose into the next Ruleset only after the remaining
// fuel, storage and Secretary decisions, with its forward publication migration.
return [
    'payload' => [
        'power_economy' => [
            'wind_minimum_mw' => 45,
            'wind_maximum_mw' => 135,
            'condenser_capacity_mw' => 1200,
            'pizzeria_food_tons_per_scale' => 1000,
            'pizzeria_power_mw_per_scale' => 10,
            'pizzeria_maximum_scale' => 10,
            // Revenue is provisional, pending the final release balance choice.
            'pizzeria_revenue_at_maximum' => 55,
            'pizzeria_maintenance' => 1,
        ],
    ],
    'classification' => [
        'behavior' => [],
        'data' => [
            'wind_minimum_mw', 'wind_maximum_mw', 'condenser_capacity_mw',
            'pizzeria_food_tons_per_scale', 'pizzeria_power_mw_per_scale',
            'pizzeria_maximum_scale', 'pizzeria_revenue_at_maximum', 'pizzeria_maintenance',
        ],
        'flavor' => [],
    ],
];
