<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v2.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v3';
$ruleset['version'] = 3;
$ruleset['territory_transfer'] = [
    'capital_core' => [
        'ownership_transfer_protected' => true,
        'owner_states' => ['active'],
        'radius' => 2,
    ],
];
$ruleset['turn_processing']['territory_influence'] = [
    'enabled' => true,
    'policy_version' => 1,
    'owner_states' => ['active'],
    'target' => [
        'unfacilitated_terrain_keys' => ['forest', 'mountain'],
        'facility_keys' => [
            'village', 'town', 'city',
            'farm', 'factory', 'mine', 'missile_base', 'defense',
        ],
        'excluded_terrain_keys' => ['sea', 'shallow', 'wasteland', 'scorched'],
        'excluded_facility_keys' => ['seabed_base', 'seabed_oil_field', 'monument'],
        'monster_occupancy' => 'exclude',
        'capital_core' => 'exclude',
    ],
    'source' => [
        'excluded_terrain_keys' => ['sea', 'shallow', 'wasteland', 'scorched'],
        'excluded_facility_keys' => ['seabed_base', 'seabed_oil_field'],
        'monster_occupancy' => 'exclude',
        'monument' => 'allowed',
    ],
    'neighbor' => [
        'directions' => 6,
        'selection' => 'uniform_one',
        'reroll_on_missing_or_ineligible' => false,
    ],
    'resolution' => [
        'cell_visit_order' => 'shared_surface_shuffle_once',
        'attempts_per_eligible_target' => 1,
        'source_state' => 'evaluate_at_visit',
        'mutation_timing' => 'immediate',
        'direction_stream' => 'territory_influence:direction:v1',
    ],
    'effect' => [
        'owner' => 'source_owner',
        'terrain' => 'preserve',
        'population' => 'preserve',
        'facility' => 'preserve',
        'facility_scale' => 'preserve',
        'resource_and_state' => 'preserve',
    ],
];

$ruleset['command_definitions'] = array_map(
    static function (array $definition): array {
        if ($definition['key'] !== 'territory_expand') {
            return $definition;
        }

        return [
            ...$definition,
            'description' => '自国領に隣接する中立陸地、またはactiveな他国の荒地・焼け野原を領有します。',
            'metadata' => [
                'consumes_turn' => true,
                'parameters' => [],
                'legacy_command' => 'Widen',
                'policy_version' => 3,
                'actor_states' => ['active'],
                'adjacency' => [
                    'source_owner' => 'actor',
                    'directions' => 6,
                ],
                'neutral_target' => [
                    'allowed' => true,
                    'terrain_keys' => ['wasteland', 'scorched', 'plain', 'forest', 'mountain'],
                    'requires_empty_facility' => true,
                ],
                'foreign_target' => [
                    'owner_states' => ['active'],
                    'terrain_keys' => ['wasteland', 'scorched'],
                    'requires_empty_facility' => true,
                ],
                'monster_occupancy' => 'reject',
                'capital_core' => 'reject',
                'effect' => [
                    'owner' => 'actor',
                    'terrain' => 'preserve',
                    'population' => 'preserve',
                    'facility' => 'preserve',
                    'facility_scale' => 'preserve',
                    'resource_and_state' => 'preserve',
                ],
            ],
        ];
    },
    $ruleset['command_definitions'],
);

return $ruleset;
