<?php

return [
    'payload' => [
        'power_economy' => [
            'wind_minimum_mw' => 45,
            'wind_maximum_mw' => 135,
            'condenser_capacity_mw' => 1200,
            'thermal_power_mw_per_scale' => 100,
            'thermal_oil_mw_per_unit' => 60,
            'thermal_coal_tons_per_oil_unit' => 1000,
            'pizzeria_food_tons_per_scale' => 1000,
            'pizzeria_power_mw_per_scale' => 10,
            'pizzeria_maximum_scale' => 10,
            'pizzeria_revenue_at_maximum' => 55,
        ],
    ],
    'classification' => [
        'behavior' => [
        ],
        'data' => [
            0 => 'wind_minimum_mw',
            1 => 'wind_maximum_mw',
            2 => 'condenser_capacity_mw',
            3 => 'thermal_power_mw_per_scale',
            4 => 'thermal_oil_mw_per_unit',
            5 => 'thermal_coal_tons_per_oil_unit',
            6 => 'pizzeria_food_tons_per_scale',
            7 => 'pizzeria_power_mw_per_scale',
            8 => 'pizzeria_maximum_scale',
            9 => 'pizzeria_revenue_at_maximum',
        ],
        'flavor' => [
        ],
    ],
];
