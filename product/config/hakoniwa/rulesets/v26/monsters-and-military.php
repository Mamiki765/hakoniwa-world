<?php

$domain = require __DIR__.'/../v23/monsters-and-military.php';
$payload = $domain['payload'];

foreach ($payload['monster_definitions'] as &$definition) {
    if (($definition['key'] ?? null) !== 'aoi_inora') {
        continue;
    }
    $definition['source_metadata']['behavior']['world_spawn'] = [
        'type' => 'world_aoi_disaster',
        'probability_per_active_owned_land_cell' => ['numerator' => 1, 'denominator' => 10000],
        'maximum_probability_numerator' => 10000,
        'terrain_keys' => ['sea', 'shallow'],
        'eligible_nation_state' => 'active',
        'minimum_nation_population' => 100000,
        'target_weight' => 'owned_land_cells_times_natural_monster_spawn_modifier',
        'exact_owned_land_distance' => 4,
        'other_land_exclusion_distance' => 3,
        'stream_version' => 2,
    ];
    break;
}
unset($definition);

$classification = $domain['classification'];
$classification['data'] = array_values(array_filter(
    $classification['data'],
    static fn (string $selector): bool => $selector !== 'minimum_land_distance',
));
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    'eligible_nation_state', 'target_weight',
]));
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'minimum_nation_population', 'exact_owned_land_distance', 'other_land_exclusion_distance',
]));

return ['payload' => $payload, 'classification' => $classification];
