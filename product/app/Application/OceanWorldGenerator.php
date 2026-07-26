<?php

namespace App\Application;

use App\Domain\Hex\ChunkCoordinateService;
use App\Models\FacilityDefinition;
use App\Models\MapSpace;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\World;
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

            $ruleset = RulesetVersion::query()->firstOrCreate(
                ['key' => $rules['key']],
                ['version' => $rules['version'], 'settings' => $rules, 'is_active' => true],
            );

            $world = World::query()->firstOrCreate(
                ['key' => $worldConfig['key']],
                ['name' => $worldConfig['name'], 'ruleset_version_id' => $ruleset->id, 'current_turn' => 0],
            );
            $world = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();

            $mapSpace = MapSpace::query()->firstOrCreate(
                ['world_id' => $world->id, 'key' => $worldConfig['map_space_key']],
                [
                    'name' => $worldConfig['map_space_name'],
                    'coordinate_system' => 'pointy_top_axial',
                    'min_q' => $rules['initial_q_min'], 'max_q' => $rules['initial_q_max'],
                    'min_r' => $rules['initial_r_min'], 'max_r' => $rules['initial_r_max'],
                ],
            );

            $this->ensureCatalogs();

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

            $seaId = TerrainDefinition::query()->where('key', 'sea')->valueOrFail('id');
            $chunkRows = [];

            for ($q = $rules['initial_q_min']; $q <= $rules['initial_q_max']; $q++) {
                for ($r = $rules['initial_r_min']; $r <= $rules['initial_r_max']; $r++) {
                    $location = $this->chunks->locate($q, $r);
                    $key = $location['chunk_q'].':'.$location['chunk_r'];
                    $chunkRows[$key] = [
                        'map_space_id' => $mapSpace->id,
                        'chunk_q' => $location['chunk_q'], 'chunk_r' => $location['chunk_r'],
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
                ->get(['id', 'chunk_q', 'chunk_r'])
                ->keyBy(fn (object $row): string => $row->chunk_q.':'.$row->chunk_r);

            $batch = [];
            $inserted = 0;

            for ($q = $rules['initial_q_min']; $q <= $rules['initial_q_max']; $q++) {
                for ($r = $rules['initial_r_min']; $r <= $rules['initial_r_max']; $r++) {
                    $location = $this->chunks->locate($q, $r);
                    $chunk = $chunkIds[$location['chunk_q'].':'.$location['chunk_r']];
                    $batch[] = [
                        'map_space_id' => $mapSpace->id, 'map_chunk_id' => $chunk->id,
                        'q' => $q, 'r' => $r,
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

    private function ensureCatalogs(): void
    {
        $terrains = [
            ['key' => 'sea', 'name' => '海', 'asset_key' => 'hakoniwa_original.sea', 'is_water' => true, 'is_buildable' => false],
            ['key' => 'shallow', 'name' => '浅瀬', 'asset_key' => 'hakoniwa_original.shallow', 'is_water' => true, 'is_buildable' => false],
            ['key' => 'wasteland', 'name' => '荒地', 'asset_key' => 'hakoniwa_original.wasteland', 'is_water' => false, 'is_buildable' => true],
            ['key' => 'plain', 'name' => '平地', 'asset_key' => 'hakoniwa_original.plain', 'is_water' => false, 'is_buildable' => true],
            ['key' => 'forest', 'name' => '森', 'asset_key' => 'hakoniwa_original.forest', 'is_water' => false, 'is_buildable' => false],
            ['key' => 'mountain', 'name' => '山', 'asset_key' => 'hakoniwa_original.mountain', 'is_water' => false, 'is_buildable' => false],
        ];
        $facilities = [
            ['key' => 'village', 'name' => '村', 'asset_key' => 'hakoniwa_original.village'],
            ['key' => 'missile_base', 'name' => 'ミサイル基地', 'asset_key' => 'hakoniwa_original.missile_base'],
            ['key' => 'capital', 'name' => '首都', 'asset_key' => 'hakoniwa_new.capital'],
        ];

        foreach ($terrains as $definition) {
            TerrainDefinition::query()->updateOrCreate(['key' => $definition['key']], $definition);
        }
        foreach ($facilities as $definition) {
            FacilityDefinition::query()->updateOrCreate(['key' => $definition['key']], $definition);
        }
        foreach (config('hakoniwa.ruleset.resource_definitions') as $definition) {
            ResourceDefinition::query()->updateOrCreate(['key' => $definition['key']], $definition);
        }
    }
}
