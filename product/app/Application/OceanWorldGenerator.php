<?php

namespace App\Application;

use App\Domain\Map\ChunkCoordinateService;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapSpace;
use App\Models\ProductionDefinition;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

class OceanWorldGenerator
{
    public function __construct(private readonly ChunkCoordinateService $chunks) {}

    public function initialize(): World
    {
        return DB::transaction(function (): World {
            $rules = config('hakoniwa.ruleset');
            $worldConfig = config('hakoniwa.world');
            $now = now();

            $ruleset = RulesetVersion::query()->updateOrCreate(
                ['key' => $rules['key']],
                ['version' => $rules['version'], 'settings' => $rules, 'is_active' => true],
            );

            $world = World::query()->firstOrCreate(
                ['key' => $worldConfig['key']],
                ['name' => $worldConfig['name'], 'ruleset_version_id' => $ruleset->id, 'current_turn' => 0],
            );
            $world = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
            if ($world->ruleset_version_id !== $ruleset->id) {
                $world->update(['ruleset_version_id' => $ruleset->id]);
            }

            $mapSpace = MapSpace::query()->firstOrCreate(
                ['world_id' => $world->id, 'key' => $worldConfig['map_space_key']],
                [
                    'name' => $worldConfig['map_space_name'],
                    'coordinate_system' => 'staggered_square_offset',
                    'min_x' => $rules['initial_x_min'], 'max_x' => $rules['initial_x_max'],
                    'min_y' => $rules['initial_y_min'], 'max_y' => $rules['initial_y_max'],
                ],
            );

            $this->ensureCatalogs($ruleset);

            $completed = DB::table('world_generation_runs')
                ->where('map_space_id', $mapSpace->id)
                ->where('generator_id', $worldConfig['generator_id'])
                ->where('generator_version', $worldConfig['generator_version'])
                ->where('seed', $worldConfig['seed'])
                ->where('status', 'completed')
                ->exists();

            if ($completed) {
                return $world;
            }

            if ($mapSpace->cells()->exists()) {
                throw new DomainException(
                    "World {$world->key} contains data from an older coordinate system. "
                    ."Run hakoniwa:world:reset --world={$world->key} --confirm=RESET-{$world->key}.",
                );
            }

            $seaId = TerrainDefinition::query()->where('key', 'sea')->valueOrFail('id');
            $chunkRows = [];

            for ($y = $rules['initial_y_min']; $y <= $rules['initial_y_max']; $y++) {
                for ($x = $rules['initial_x_min']; $x <= $rules['initial_x_max']; $x++) {
                    $location = $this->chunks->locate($x, $y);
                    $key = $location['chunk_x'].':'.$location['chunk_y'];
                    $chunkRows[$key] = [
                        'map_space_id' => $mapSpace->id,
                        'chunk_x' => $location['chunk_x'], 'chunk_y' => $location['chunk_y'],
                        'version' => 1, 'generated_at' => $now,
                        'generator_id' => $worldConfig['generator_id'],
                        'generator_version' => $worldConfig['generator_version'],
                        'generation_seed' => $worldConfig['seed'],
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            DB::table('map_chunks')->insertOrIgnore(array_values($chunkRows));
            $chunkIds = DB::table('map_chunks')->where('map_space_id', $mapSpace->id)
                ->get(['id', 'chunk_x', 'chunk_y'])
                ->keyBy(fn (object $row): string => $row->chunk_x.':'.$row->chunk_y);

            $batch = [];
            $inserted = 0;

            for ($y = $rules['initial_y_min']; $y <= $rules['initial_y_max']; $y++) {
                for ($x = $rules['initial_x_min']; $x <= $rules['initial_x_max']; $x++) {
                    $location = $this->chunks->locate($x, $y);
                    $chunk = $chunkIds[$location['chunk_x'].':'.$location['chunk_y']];
                    $batch[] = [
                        'map_space_id' => $mapSpace->id, 'map_chunk_id' => $chunk->id,
                        'x' => $x, 'y' => $y,
                        ...$location,
                        'terrain_definition_id' => $seaId,
                        'facility_definition_id' => null, 'owner_nation_id' => null,
                        'population' => 0, 'state' => 'generated', 'version' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ];

                    if (count($batch) === 500) {
                        DB::table('map_cells')->insertOrIgnore($batch);
                        $inserted += count($batch);
                        $batch = [];
                        $this->afterBatchInserted($inserted);
                    }
                }
            }

            if ($batch !== []) {
                DB::table('map_cells')->insertOrIgnore($batch);
                $inserted += count($batch);
                $this->afterBatchInserted($inserted);
            }

            DB::table('world_generation_runs')->updateOrInsert(
                [
                    'map_space_id' => $mapSpace->id,
                    'generator_id' => $worldConfig['generator_id'],
                    'generator_version' => $worldConfig['generator_version'],
                    'seed' => $worldConfig['seed'],
                ],
                ['status' => 'completed', 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            );

            return $world;
        });
    }

    protected function afterBatchInserted(int $inserted): void {}

    private function ensureCatalogs(RulesetVersion $ruleset): void
    {
        $forest = config('hakoniwa.ruleset.terrain_quantities.forest');
        $terrains = [
            ['key' => 'sea', 'name' => '海', 'asset_key' => 'tile.sea', 'is_water' => true, 'is_buildable' => false],
            ['key' => 'shallow', 'name' => '浅瀬', 'asset_key' => 'tile.shallow', 'is_water' => true, 'is_buildable' => false],
            ['key' => 'wasteland', 'name' => '荒地', 'asset_key' => 'tile.wasteland', 'is_water' => false, 'is_buildable' => true],
            ['key' => 'plain', 'name' => '平地', 'asset_key' => 'tile.plain', 'is_water' => false, 'is_buildable' => true],
            [
                'key' => 'forest', 'name' => '森', 'asset_key' => 'tile.forest', 'is_water' => false, 'is_buildable' => false,
                'quantity_key' => $forest['key'], 'quantity_label' => $forest['label'], 'quantity_unit' => $forest['unit'],
                'initial_quantity' => $forest['initial_quantity'], 'minimum_quantity' => $forest['minimum_quantity'],
                'maximum_quantity' => $forest['maximum_quantity'], 'growth_rule_key' => $forest['growth_rule_key'],
                'metadata' => ['legacy_quantity_unit' => 100, 'growth_increment' => $forest['growth_increment']],
            ],
            ['key' => 'mountain', 'name' => '山', 'asset_key' => 'tile.mountain', 'is_water' => false, 'is_buildable' => false],
        ];

        foreach ($terrains as $definition) {
            TerrainDefinition::query()->updateOrCreate(['key' => $definition['key']], $definition);
        }
        foreach (config('hakoniwa.ruleset.facility_definitions') as $key => $definition) {
            FacilityDefinition::query()->updateOrCreate(['key' => $key], [
                'name' => $definition['name'],
                'asset_key' => $definition['asset_key'],
                'enabled' => true,
                'build_command_key' => $definition['build_command_key'],
                'visibility_policy' => $definition['visibility_policy'],
                'disguise_terrain_key' => $definition['disguise_terrain_key'] ?? null,
                'disguise_asset_key' => $definition['disguise_asset_key'] ?? null,
                'scale_unit_people' => $definition['scale_unit_people'],
                'initial_scale' => $definition['initial_scale'],
                'scale_increment' => $definition['scale_increment'],
                'maximum_scale' => $definition['maximum_scale'],
                'workforce_per_scale_people' => $definition['workforce_per_scale_people'],
                'production_definition_key' => $definition['production_definition_key'],
                'buildable_terrain_keys' => $definition['buildable_terrain_keys'],
                'metadata' => array_filter([
                    'initial_experience' => $definition['initial_experience'] ?? null,
                    'maximum_experience' => $definition['maximum_experience'] ?? null,
                    'level_thresholds' => $definition['level_thresholds'] ?? null,
                    'launch_capacity_by_level' => $definition['launch_capacity_by_level'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ]);
        }
        foreach (config('hakoniwa.ruleset.resource_definitions') as $definition) {
            ResourceDefinition::query()->updateOrCreate(['key' => $definition['key']], $definition);
        }
        foreach (config('hakoniwa.ruleset.command_definitions') as $definition) {
            CommandDefinition::query()->updateOrCreate(
                ['ruleset_version_id' => $ruleset->id, 'key' => $definition['key']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'target_type' => $definition['target_type'],
                    'target_terrain_keys' => $definition['target_terrain_keys'],
                    'target_facility_keys' => $definition['target_facility_keys'],
                    'requires_empty_facility' => $definition['requires_empty_facility'],
                    'cost_money' => $definition['cost_money'],
                    'required_resources' => $definition['required_resources'],
                    'execution_phase' => $definition['execution_phase'],
                    'result_terrain_key' => $definition['result_terrain_key'],
                    'result_facility_key' => $definition['result_facility_key'],
                    'sort_order' => $definition['sort_order'],
                    'metadata' => $definition['metadata'],
                    'enabled' => true,
                ],
            );
        }
        foreach (config('hakoniwa.ruleset.production_definitions') as $definition) {
            $facility = FacilityDefinition::query()->where('key', $definition['facility_key'])->firstOrFail();
            $resource = ResourceDefinition::query()->where('key', $definition['output_resource_key'])->firstOrFail();
            ProductionDefinition::query()->updateOrCreate(
                ['ruleset_version_id' => $ruleset->id, 'key' => $definition['key']],
                [
                    'facility_definition_id' => $facility->id,
                    'output_resource_definition_id' => $resource->id,
                    'production_per_scale' => $definition['production_per_scale'],
                    'required_workforce_per_scale' => $definition['required_workforce_per_scale'],
                    'operating_condition' => $definition['operating_condition'],
                    'price_reference' => $definition['price_reference'],
                    'enabled' => true,
                    'metadata' => $definition['metadata'],
                ],
            );
        }
    }
}
