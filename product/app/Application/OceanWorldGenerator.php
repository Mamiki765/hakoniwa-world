<?php

namespace App\Application;

use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapSpace;
use App\Models\TerrainDefinition;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

class OceanWorldGenerator
{
    public function __construct(
        private readonly ChunkCoordinateService $chunks,
        private readonly RulesetPublisher $rulesets,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly MapSpaceCoveragePreflight $coverage,
        private readonly CurrentCatalogInstaller $catalogs,
    ) {}

    public function initialize(
        WorldGenerationProfile $profile = WorldGenerationProfile::Production,
    ): World {
        return DB::transaction(function () use ($profile): World {
            $rules = config('hakoniwa.ruleset');
            $worldConfig = config('hakoniwa.world');
            $profile->assertAvailable(app()->environment());
            $generatorVersion = $profile->generatorVersion((string) $worldConfig['generator_version']);
            $generationSeed = $profile->seed((string) $worldConfig['seed']);
            $now = now();

            $world = World::query()->where('key', $worldConfig['key'])->lockForUpdate()->first();
            $worldRuleset = null;
            if ($world !== null) {
                $worldRuleset = $world->rulesetVersion()->firstOrFail();
                $this->rulesetGuard->assertMutable($world, $worldRuleset);
            }

            $this->catalogs->install($rules);
            $ruleset = $this->rulesets->publish($rules);
            if ($world === null) {
                $world = World::query()->create([
                    'key' => $worldConfig['key'],
                    'name' => $worldConfig['name'],
                    'ruleset_version_id' => $ruleset->id,
                    'current_turn' => 1,
                ]);
                $worldRuleset = $ruleset;
            }
            $bounds = $profile->bounds($worldRuleset->settings);
            $mapSpace = MapSpace::query()->firstOrCreate(
                ['world_id' => $world->id, 'key' => $worldConfig['map_space_key']],
                [
                    'name' => $worldConfig['map_space_name'],
                    'coordinate_system' => 'staggered_square_offset',
                    'min_x' => $bounds->minX, 'max_x' => $bounds->maxX,
                    'min_y' => $bounds->minY, 'max_y' => $bounds->maxY,
                ],
            );
            $completed = DB::table('world_generation_runs')
                ->where('map_space_id', $mapSpace->id)
                ->where('generator_id', $worldConfig['generator_id'])
                ->where('generator_version', $generatorVersion)
                ->where('seed', $generationSeed)
                ->where('status', 'completed')
                ->exists();

            if ($completed) {
                if ($mapSpace->coordinate_system !== 'staggered_square_offset'
                    || ! $mapSpace->currentBounds()->containsBounds($bounds)) {
                    throw new DomainException(
                        "World {$world->key} current bounds do not contain its immutable initial bounds.",
                    );
                }
                $this->coverage->assertComplete($mapSpace);

                return $world;
            }

            if ($mapSpace->coordinate_system !== 'staggered_square_offset'
                || $mapSpace->min_x !== $bounds->minX || $mapSpace->max_x !== $bounds->maxX
                || $mapSpace->min_y !== $bounds->minY || $mapSpace->max_y !== $bounds->maxY) {
                throw new DomainException(
                    "World {$world->key} already uses different map bounds. "
                    ."Run an explicit World reset before selecting profile {$profile->value}.",
                );
            }

            if ($mapSpace->cells()->exists()) {
                throw new DomainException(
                    "World {$world->key} contains data from an older coordinate system. "
                    ."Run hakoniwa:world:reset --world={$world->key} --confirm=RESET-{$world->key}.",
                );
            }

            $seaId = TerrainDefinition::query()->where('key', 'sea')->valueOrFail('id');
            $chunkRows = [];

            for ($y = $bounds->minY; $y <= $bounds->maxY; $y++) {
                for ($x = $bounds->minX; $x <= $bounds->maxX; $x++) {
                    $location = $this->chunks->locate($x, $y);
                    $key = $location['chunk_x'].':'.$location['chunk_y'];
                    $chunkRows[$key] = [
                        'map_space_id' => $mapSpace->id,
                        'chunk_x' => $location['chunk_x'], 'chunk_y' => $location['chunk_y'],
                        'version' => 1, 'generated_at' => $now,
                        'generator_id' => $worldConfig['generator_id'],
                        'generator_version' => $generatorVersion,
                        'generation_seed' => $generationSeed,
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

            for ($y = $bounds->minY; $y <= $bounds->maxY; $y++) {
                for ($x = $bounds->minX; $x <= $bounds->maxX; $x++) {
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

            // MapSpace bounds become externally visible only when this transaction commits.
            // A future expansion must likewise validate coverage before updating current bounds.
            $this->coverage->assertComplete($mapSpace);

            DB::table('world_generation_runs')->updateOrInsert(
                [
                    'map_space_id' => $mapSpace->id,
                    'generator_id' => $worldConfig['generator_id'],
                    'generator_version' => $generatorVersion,
                    'seed' => $generationSeed,
                ],
                ['status' => 'completed', 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            );

            return $world;
        });
    }

    protected function afterBatchInserted(int $inserted): void {}
}
