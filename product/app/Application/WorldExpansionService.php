<?php

namespace App\Application;

use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\MapBounds;
use App\Domain\World\WorldMutationLock;
use App\Models\MapChunk;
use App\Models\MapSpace;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WorldExpansionService
{
    private const GENERATOR_ID = 'world-expansion';

    private const GENERATOR_VERSION = 'neutral-sea-v1';

    public function __construct(
        private readonly ChunkCoordinateService $chunks,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly WorldMutationLock $worldMutationLock,
        private readonly MapSpaceCoveragePreflight $coverage,
    ) {}

    public function expand(
        World $world,
        MapBounds $expectedBefore,
        MapBounds $target,
        ?User $actor = null,
        ?string $reason = null,
    ): MapSpace {
        $this->assertRequestedBounds($expectedBefore, $target);
        $this->worldMutationLock->acquire($world);

        try {
            return $this->expandWithinCurrentMutation(
                $world,
                $expectedBefore,
                $target,
                $actor,
                $reason,
            );
        } finally {
            $this->worldMutationLock->release($world);
        }
    }

    /**
     * Expand atomically while a caller such as Nation registration already owns
     * the common World mutation lock. The nested transaction remains part of the
     * caller's outer transaction and therefore rolls back with that operation.
     */
    public function expandWithinCurrentMutation(
        World $world,
        MapBounds $expectedBefore,
        MapBounds $target,
        ?User $actor = null,
        ?string $reason = null,
    ): MapSpace {
        $this->assertRequestedBounds($expectedBefore, $target);
        $this->worldMutationLock->assertHeld($world);

        return DB::transaction(function () use (
            $world,
            $expectedBefore,
            $target,
            $actor,
            $reason,
        ): MapSpace {
            $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
            $mapSpace = MapSpace::query()
                ->where('world_id', $lockedWorld->id)
                ->where('key', config('hakoniwa.world.map_space_key'))
                ->lockForUpdate()
                ->firstOrFail();

            $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
            $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
            $this->assertNoUnresolvedTurnRun($lockedWorld);

            if ($mapSpace->coordinate_system !== 'staggered_square_offset') {
                throw new DomainException('World expansion requires the canonical staggered x/y MapSpace.');
            }

            $current = $mapSpace->currentBounds();
            if (! $current->equals($expectedBefore) && ! $current->equals($target)) {
                throw new DomainException(
                    'Current MapSpace bounds match neither the expected before bounds nor the target bounds.',
                );
            }

            // Existing published bounds must already be complete. This also rejects
            // target-only cells left outside the current rectangle by corruption.
            $this->coverage->assertComplete($mapSpace);
            if ($current->equals($target)) {
                return $mapSpace;
            }

            $now = now();
            $seaId = (int) TerrainDefinition::query()->where('key', 'sea')->valueOrFail('id');
            $neededChunks = $this->neededChunks($current, $target);
            $chunks = MapChunk::query()
                ->where('map_space_id', $mapSpace->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (MapChunk $chunk): string => $chunk->chunk_x.':'.$chunk->chunk_y);
            $createdChunkCount = 0;
            $touchedExistingChunkCount = 0;

            foreach ($neededChunks as $key => $location) {
                if ($chunks->has($key)) {
                    $touchedExistingChunkCount++;

                    continue;
                }

                $chunk = MapChunk::query()->create([
                    'map_space_id' => $mapSpace->id,
                    'chunk_x' => $location['chunk_x'],
                    'chunk_y' => $location['chunk_y'],
                    'version' => 1,
                    'generated_at' => $now,
                    'generator_id' => self::GENERATOR_ID,
                    'generator_version' => self::GENERATOR_VERSION,
                    'generation_seed' => null,
                ]);
                $chunks->put($key, $chunk);
                $createdChunkCount++;
            }

            $inserted = 0;
            $batch = [];
            for ($y = $target->minY; $y <= $target->maxY; $y++) {
                for ($x = $target->minX; $x <= $target->maxX; $x++) {
                    if ($current->contains($x, $y)) {
                        continue;
                    }

                    $location = $this->chunks->locate($x, $y);
                    $chunk = $chunks->get($location['chunk_x'].':'.$location['chunk_y']);
                    if (! $chunk instanceof MapChunk) {
                        throw new RuntimeException("Expansion chunk metadata is missing for ({$x}, {$y}).");
                    }
                    $batch[] = [
                        'map_space_id' => $mapSpace->id,
                        'map_chunk_id' => $chunk->id,
                        'x' => $x,
                        'y' => $y,
                        ...$location,
                        'terrain_definition_id' => $seaId,
                        'facility_definition_id' => null,
                        'owner_nation_id' => null,
                        'population' => 0,
                        'state' => 'generated',
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($batch) === 500) {
                        DB::table('map_cells')->insert($batch);
                        $inserted += count($batch);
                        $batch = [];
                    }
                }
            }
            if ($batch !== []) {
                DB::table('map_cells')->insert($batch);
                $inserted += count($batch);
            }

            $expectedAdded = $target->cellCount() - $current->cellCount();
            if ($inserted !== $expectedAdded) {
                throw new RuntimeException(
                    "World expansion inserted {$inserted} MapCells; expected {$expectedAdded}.",
                );
            }

            $this->afterCellsGenerated($mapSpace, $inserted);
            $this->coverage->assertComplete($mapSpace, $target);

            // Updating current bounds is the publication marker and therefore happens
            // only after complete target coverage has been proven in this transaction.
            $mapSpace->update([
                'min_x' => $target->minX,
                'max_x' => $target->maxX,
                'min_y' => $target->minY,
                'max_y' => $target->maxY,
            ]);

            $metadata = [
                'before_bounds' => $this->boundsArray($current),
                'after_bounds' => $this->boundsArray($target),
                'added_cell_count' => $inserted,
                'created_chunk_count' => $createdChunkCount,
                'touched_existing_chunk_count' => $touchedExistingChunkCount,
            ];
            if ($actor !== null) {
                $metadata['actor_user_id'] = $actor->id;
            }
            if ($reason !== null && trim($reason) !== '') {
                $metadata['reason'] = trim($reason);
            }

            DB::table('audit_events')->insert([
                'actor_user_id' => $actor?->id,
                'world_id' => $lockedWorld->id,
                'turn' => $lockedWorld->current_turn,
                'nation_id' => null,
                'x' => null,
                'y' => null,
                'message' => null,
                'visibility' => 'admin',
                'event_type' => 'world.expanded',
                'severity' => 'info',
                'subject_type' => $lockedWorld->getMorphClass(),
                'subject_id' => $lockedWorld->id,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Keep the detailed expansion record admin-only. The player-facing
            // event deliberately carries no bounds, actor, or operator reason.
            DB::table('audit_events')->insert([
                'actor_user_id' => null,
                'world_id' => $lockedWorld->id,
                'turn' => $lockedWorld->current_turn,
                'nation_id' => null,
                'x' => null,
                'y' => null,
                'message' => null,
                'visibility' => 'public',
                'event_type' => 'world.expanded_public',
                'severity' => 'info',
                'subject_type' => $lockedWorld->getMorphClass(),
                'subject_id' => $lockedWorld->id,
                'metadata' => json_encode((object) [], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $mapSpace;
        }, 1);
    }

    protected function afterCellsGenerated(MapSpace $mapSpace, int $inserted): void {}

    private function assertRequestedBounds(MapBounds $expectedBefore, MapBounds $target): void
    {
        if ($expectedBefore->chunkSize !== $target->chunkSize) {
            throw new DomainException('Expected and target MapBounds must use the same chunk size.');
        }
        if (! $target->containsBounds($expectedBefore)) {
            throw new DomainException(
                'Target MapBounds must completely contain the expected before bounds; shrinking or translation is forbidden.',
            );
        }
    }

    private function assertNoUnresolvedTurnRun(World $world): void
    {
        $unsafe = TurnRun::query()
            ->where('world_id', $world->id)
            ->unresolvedProduction()
            ->orderBy('id')
            ->first(['id', 'status']);
        if ($unsafe !== null) {
            throw new DomainException(
                "World {$world->key} has unresolved production TurnRun {$unsafe->id} ({$unsafe->status}).",
            );
        }
    }

    /**
     * @return array<string, array{chunk_x: int, chunk_y: int}>
     */
    private function neededChunks(MapBounds $current, MapBounds $target): array
    {
        $needed = [];
        for ($y = $target->minY; $y <= $target->maxY; $y++) {
            for ($x = $target->minX; $x <= $target->maxX; $x++) {
                if ($current->contains($x, $y)) {
                    continue;
                }

                $location = $this->chunks->locate($x, $y);
                $key = $location['chunk_x'].':'.$location['chunk_y'];
                $needed[$key] = [
                    'chunk_x' => $location['chunk_x'],
                    'chunk_y' => $location['chunk_y'],
                ];
            }
        }

        return $needed;
    }

    /** @return array{min_x: int, max_x: int, min_y: int, max_y: int} */
    private function boundsArray(MapBounds $bounds): array
    {
        return [
            'min_x' => $bounds->minX,
            'max_x' => $bounds->maxX,
            'min_y' => $bounds->minY,
            'max_y' => $bounds->maxY,
        ];
    }
}
