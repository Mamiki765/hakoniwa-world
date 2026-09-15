<?php

namespace Tests\Support;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\KarmaTurnService;
use App\Application\MissileImpactResolver;
use App\Application\NationCreationService;
use App\Application\SecretaryTurnService;
use App\Application\SurfaceShipTurnService;
use App\Domain\Command\PlayerFacingCommandException;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class CommandAndMissileTestCase extends TestCase
{
    public static function ordinaryMissileKeys(): array
    {
        return [
            'normal' => ['missile'],
            'PP' => ['pp_missile'],
            'SPP' => ['spp_missile'],
        ];
    }

    protected function combatants(string $suffix = ''): array
    {
        $world = $this->lightweightWorld();
        [$user, $firing] = $this->nation($world, '発射国'.$suffix);
        [, $target] = $this->nation($world, '標的国'.$suffix);
        $firing->update(['money' => 1_000]);

        return [$world, $user, $firing, $target];
    }

    /** @return array{User, Nation} */
    protected function nation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name, '試験島主')];
    }

    protected function missileBase(Nation $nation): MapCell
    {
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'missile_base')->firstOrFail(),
        );
        $cell->save();

        return $cell->fresh(['terrain', 'facility']);
    }

    protected function equipCollar(User $user, int $level): void
    {
        $user->secretary()->sole()->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::COLLAR,
            'level' => $level,
            'equipped_slot' => 2,
            'grant_key' => "test:collar:{$level}",
            'obtained_at' => now(),
        ]);
    }

    protected function placeFacilityAtDistance(
        MapSpace $space,
        MapCell $center,
        Nation $owner,
        int $distance,
        string $facilityKey,
    ): MapCell {
        $coordinate = collect((new GridCoordinate($center->x, $center->y))->ring($distance))
            ->first(static fn (GridCoordinate $candidate): bool => $candidate->x >= $space->min_x
                && $candidate->x <= $space->max_x
                && $candidate->y >= $space->min_y
                && $candidate->y <= $space->max_y);
        $this->assertInstanceOf(GridCoordinate::class, $coordinate);
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
        );
        $cell->owner_nation_id = $owner->id;
        $cell->population = 0;
        $cell->version++;
        $cell->save();

        return $cell->fresh(['terrain', 'facility', 'ownerNation']);
    }

    protected function ownedWaterFacility(Nation $nation, string $facilityKey, ?int $experience = null): MapCell
    {
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($nation->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
            experience: $experience,
        );
        $cell->owner_nation_id = $nation->id;
        $cell->population = 0;
        $cell->save();

        return $cell->fresh(['terrain', 'facility', 'ownerNation']);
    }

    /** @return list<MapCell> */
    protected function neutralCellsNearTerritory(Nation $nation, MapSpace $space, int $count): array
    {
        $owned = MapCell::query()->where('owner_nation_id', $nation->id)->get(['x', 'y']);
        $candidates = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')->orderBy('id')->get();
        $nearby = $candidates->filter(static function (MapCell $candidate) use ($owned): bool {
            $coordinate = new GridCoordinate($candidate->x, $candidate->y);

            return $owned->contains(static fn (MapCell $cell): bool => $coordinate->distanceTo(
                new GridCoordinate($cell->x, $cell->y),
            ) <= 1);
        })->take($count)->values();
        if ($nearby->count() !== $count) {
            $this->fail("Expected {$count} neutral cells next to the Nation territory.");
        }

        return $nearby->all();
    }

    protected function monster(World $world, MapCell $cell, string $monsterKey = 'inora'): MonsterInstance
    {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', $monsterKey)->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $definition->base_hp,
            'spawned_max_hp' => $definition->base_hp,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);

        return $monster;
    }

    protected function monsterArena(World $world, Nation $owner): MapCell
    {
        $space = $this->surfaceMapSpace($world);
        $origin = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')
            ->whereBetween('x', [$space->min_x + 3, $space->max_x - 3])
            ->whereBetween('y', [$space->min_y + 3, $space->max_y - 3])
            ->whereDoesntHave('monsterOccupancy')
            ->with(['terrain', 'facility'])
            ->orderBy('id')
            ->firstOrFail();
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        foreach ((new GridCoordinate($origin->x, $origin->y))->radius(2) as $coordinate) {
            $cell = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)
                ->with(['terrain', 'facility'])->firstOrFail();
            app(MapCellStateService::class)->setFacility($cell, null);
            app(MapCellStateService::class)->transitionTerrain($cell, $plain);
            $cell->owner_nation_id = $owner->id;
            $cell->population = 0;
            $cell->save();
        }
        app(MapCellStateService::class)->transitionTerrain(
            $origin,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $origin->save();

        return $origin->fresh(['terrain', 'facility', 'ownerNation']);
    }

    protected function executeCellResolution(TurnContext $context, MapCell ...$centers): void
    {
        $space = $this->surfaceMapSpace($context->world);
        $coordinates = [];
        foreach ($centers as $center) {
            foreach ((new GridCoordinate($center->x, $center->y))->radius(2) as $coordinate) {
                if ($coordinate->x >= $space->min_x && $coordinate->x <= $space->max_x
                    && $coordinate->y >= $space->min_y && $coordinate->y <= $space->max_y) {
                    $coordinates[$coordinate->x.':'.$coordinate->y] = $coordinate;
                }
            }
        }
        $cellIds = MapCell::query()->where('map_space_id', $space->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where('x', $coordinate->x)
                        ->where('y', $coordinate->y));
                }
            })->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $centerIds = array_map(static fn (MapCell $cell): int => $cell->id, $centers);
        $context->state->setSurfaceCellIds(array_values(array_unique([
            ...$centerIds,
            ...$cellIds,
        ])));
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $context->state->stableNationIds());
        app(DomesticCommandExecutor::class)->execute($context);
        app(CompleteTurnEngine::class)->execute('process_cells', $context);
    }

    /**
     * @return array{
     *     shots_fired: int,
     *     money_spent: int,
     *     meaningful_impacts: int,
     *     ineffective_impacts: int,
     *     changed_cell_ids: list<int>
     * }
     */
    protected function resolveMissile(TurnContext $context, MapCell $base): array
    {
        app(DomesticCommandExecutor::class)->execute($context);
        $resolver = app(MissileImpactResolver::class);
        $space = $this->surfaceMapSpace($context->world);
        $ships = app(SurfaceShipTurnService::class)->load($context, $space);
        $resolver->begin($this->missileCellIndex($context->world), $ships);
        $metrics = $resolver->processBase($context, $space, $base);
        $resolver->finalize($context);

        return $metrics;
    }

    /**
     * @param  list<MapCell>  $bases
     * @return array{
     *     shots_fired: int,
     *     finalize: array{launches: int, shots_fired: int, ineffective_impacts: int, idle_counter_resets: int}
     * }
     */
    protected function processRegisteredMissiles(TurnContext $context, array $bases): array
    {
        $resolver = app(MissileImpactResolver::class);
        $space = $this->surfaceMapSpace($context->world);
        $ships = app(SurfaceShipTurnService::class)->load($context, $space);
        $resolver->begin($this->missileCellIndex($context->world), $ships);
        $shotsFired = 0;
        foreach ($bases as $base) {
            $metrics = $resolver->processBase($context, $space, $base);
            $shotsFired += $metrics['shots_fired'];
        }

        return [
            'shots_fired' => $shotsFired,
            'finalize' => $resolver->finalize($context),
        ];
    }

    protected function resolveKarmaMissileTurn(
        World $world,
        User $user,
        Nation $firing,
        Nation $target,
        MapCell $base,
        string $missileKey,
        MapCell $targetCell,
        int $targetTurn,
    ): TurnContext {
        $item = $this->queue(
            app(CommandQueueService::class),
            $user,
            $firing->fresh(),
            $this->surfaceMapSpace($world),
            $missileKey,
            $targetCell->fresh(['terrain', 'facility', 'ownerNation']),
        );

        return $this->resolvePreparedKarmaMissileTurn(
            $world,
            $firing,
            $target,
            $base,
            $item,
            $targetTurn,
            hash('sha256', "v13 karma category {$targetTurn} {$missileKey}"),
        );
    }

    protected function resolvePreparedKarmaMissileTurn(
        World $world,
        Nation $firing,
        Nation $target,
        MapCell $base,
        NationCommandQueueItem $item,
        int $targetTurn,
        string $seed,
    ): TurnContext {
        $nationIds = [$firing->id, $target->id];
        $context = $this->context($world, $targetTurn, $seed, $nationIds);
        $context->state->setLifecycleNationIds($nationIds);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        app(DomesticCommandExecutor::class)->execute($context);
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $metrics = $resolver->processBase(
            $context,
            $this->surfaceMapSpace($world),
            $base->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $itemState = $item->fresh();
        $this->assertSame(1, $metrics['shots_fired'], sprintf(
            'Queue item %d must fire exactly once; status=%s failure=%s.',
            $item->id,
            $itemState->status,
            json_encode($itemState->failure_metadata, JSON_THROW_ON_ERROR),
        ));
        $resolver->finalize($context);
        $karma->settleAllianceMoney($context);
        $resolver->resolveSanctions($context);
        $karma->finalize($context);

        return $context;
    }

    /**
     * @param  list<MapCell>  $bases
     * @return array{
     *     shots_fired: int,
     *     crime_points: int,
     *     changed_cell_ids: list<int>,
     *     changed_map_chunk_ids: list<int>,
     *     classification: array{
     *         turn_start_monster: bool,
     *         missile_boundary_monster: bool,
     *         anti_monster_context: bool
     *     }
     * }
     */
    protected function resolveKarmaLaunchWithBoundaryMutation(
        World $world,
        Nation $firing,
        Nation $target,
        NationCommandQueueItem $item,
        array $bases,
        int $targetTurn,
        callable $boundaryMutation,
        ?string $seed = null,
    ): array {
        $nationIds = [$firing->id, $target->id];
        $context = $this->context(
            $world,
            $targetTurn,
            $seed ?? hash('sha256', "v13 anti monster {$targetTurn} {$item->id}"),
            $nationIds,
        );
        $context->state->setLifecycleNationIds($nationIds);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        app(DomesticCommandExecutor::class)->execute($context);
        $boundaryMutation();
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $shotsFired = 0;
        $changedCellIds = [];
        foreach ($bases as $base) {
            $metrics = $resolver->processBase(
                $context,
                $this->surfaceMapSpace($world),
                $base->fresh(['terrain', 'facility', 'ownerNation']),
            );
            $shotsFired += $metrics['shots_fired'];
            $changedCellIds = array_values(array_unique([
                ...$changedCellIds,
                ...$metrics['changed_cell_ids'],
            ]));
        }
        $resolver->finalize($context);
        $karma->settleAllianceMoney($context);
        $resolver->resolveSanctions($context);
        $karma->finalize($context);
        $classification = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'karma.anti_monster_classified')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);

        return [
            'shots_fired' => $shotsFired,
            'crime_points' => $context->state->karmaLedgerForNation($firing->id)['crime_points'],
            'changed_cell_ids' => $changedCellIds,
            'changed_map_chunk_ids' => $context->state->changedMapChunkIds(),
            'classification' => [
                'turn_start_monster' => $classification['turn_start_monster'],
                'missile_boundary_monster' => $classification['missile_boundary_monster'],
                'anti_monster_context' => $classification['anti_monster_context'],
            ],
        ];
    }

    /** @return array<string, MapCell> */
    protected function missileCellIndex(World $world): array
    {
        return MapCell::query()
            ->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->with(['terrain', 'facility', 'ownerNation'])
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn (MapCell $cell): array => [$cell->x.':'.$cell->y => $cell])
            ->all();
    }

    protected function queue(
        CommandQueueService $service,
        User $user,
        Nation $nation,
        MapSpace $space,
        string $key,
        ?MapCell $cell,
        int $quantity = 1,
        ?int $position = null,
        array $parameters = [],
    ): NationCommandQueueItem {
        $version = (int) ($nation->commandQueue()->value('version') ?? 1);

        return $service->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: $key,
            targetX: $cell?->x,
            targetY: $cell?->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: $version,
            quantity: $quantity,
            parameters: $parameters,
            position: $position,
            quantityProvided: true,
        )['item'];
    }

    /** @param callable(): mixed $action */
    protected function assertPlayerFacing(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected a player-facing command rejection.');
        } catch (PlayerFacingCommandException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /** @param list<int> $nationIds */
    protected function context(World $world, int $targetTurn, string $seed, array $nationIds): TurnContext
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);

        return new TurnContext(
            $world,
            $run,
            $ruleset,
            $targetTurn,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
    }

    protected function seedForImpactIndex(
        NationCommandQueueItem $item,
        MapCell $aim,
        int $radius,
        MapCell $desired,
    ): string {
        $candidates = (new GridCoordinate($aim->x, $aim->y))->radius($radius);
        $index = array_search(
            $desired->x.':'.$desired->y,
            array_map(fn (GridCoordinate $candidate): string => $candidate->x.':'.$candidate->y, $candidates),
            true,
        );
        $this->assertIsInt($index);

        return $this->seedForDrawIndex($item, count($candidates), $index);
    }

    protected function seedForImpactAndCollarTrigger(
        NationCommandQueueItem $item,
        MapCell $aim,
        int $radius,
        MapCell $desired,
        int $nationId,
        int $chanceBasisPoints,
    ): string {
        $candidates = (new GridCoordinate($aim->x, $aim->y))->radius($radius);
        $index = array_search(
            $desired->x.':'.$desired->y,
            array_map(fn (GridCoordinate $candidate): string => $candidate->x.':'.$candidate->y, $candidates),
            true,
        );
        $this->assertIsInt($index);
        foreach (range(0, 10_000) as $candidate) {
            $seed = hash('sha256', "collar-impact:{$item->id}:{$candidate}");
            $random = new TurnRandomStreamFactory($seed);
            if ($random->stream(TurnRandomStreamFactory::missileImpact($item->id))
                ->integer(0, count($candidates) - 1) === $index
                && $random->stream(TurnRandomStreamFactory::secretaryCollar($nationId, $item->id, 0, 1))
                    ->integer(0, 9_999) < $chanceBasisPoints) {
                return $seed;
            }
        }

        $this->fail('No deterministic combined missile-impact and Collar trigger seed was found.');
    }

    protected function seedForFirstDraw(string $label, int $denominator, int $expected): string
    {
        $this->assertGreaterThan(0, $denominator);
        $this->assertGreaterThanOrEqual(0, $expected);
        $this->assertLessThan($denominator, $expected);
        for ($candidate = 0; $candidate < 10_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$candidate}");
            if ((new TurnRandomStreamFactory($seed))->stream($label)->integer(0, $denominator - 1) === $expected) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic draw {$expected} for {$label}.");
    }

    /** @param list<MapCell> $desired */
    protected function seedForImpactSequence(
        NationCommandQueueItem $item,
        MapCell $aim,
        int $radius,
        array $desired,
    ): string {
        $candidates = (new GridCoordinate($aim->x, $aim->y))->radius($radius);
        $coordinates = array_map(
            static fn (GridCoordinate $candidate): string => $candidate->x.':'.$candidate->y,
            $candidates,
        );
        $indices = array_map(function (MapCell $cell) use ($coordinates): int {
            $index = array_search($cell->x.':'.$cell->y, $coordinates, true);
            $this->assertIsInt($index);

            return $index;
        }, $desired);
        $label = TurnRandomStreamFactory::missileImpact($item->id);

        for ($candidate = 0; $candidate < 10_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream($label);
            $draws = array_map(
                static fn (): int => $stream->integer(0, count($candidates) - 1),
                $indices,
            );
            if ($draws === $indices) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic missile sequence for {$label}.");
    }

    /**
     * @param  list<array{item: NationCommandQueueItem, aim: MapCell, radius: int, desired: list<MapCell>}>  $sequences
     */
    protected function seedForImpactSequences(array $sequences): string
    {
        $plans = array_map(function (array $sequence): array {
            $candidates = (new GridCoordinate($sequence['aim']->x, $sequence['aim']->y))
                ->radius($sequence['radius']);
            $coordinates = array_map(
                static fn (GridCoordinate $candidate): string => $candidate->x.':'.$candidate->y,
                $candidates,
            );
            $indices = array_map(function (MapCell $cell) use ($coordinates): int {
                $index = array_search($cell->x.':'.$cell->y, $coordinates, true);
                $this->assertIsInt($index);

                return $index;
            }, $sequence['desired']);

            return [
                'label' => TurnRandomStreamFactory::missileImpact($sequence['item']->id),
                'candidate_count' => count($candidates),
                'indices' => $indices,
            ];
        }, $sequences);

        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "combined-missile-sequences:{$candidate}");
            $factory = new TurnRandomStreamFactory($seed);
            $matched = true;
            foreach ($plans as $plan) {
                $stream = $factory->stream($plan['label']);
                $draws = array_map(
                    static fn (): int => $stream->integer(0, $plan['candidate_count'] - 1),
                    $plan['indices'],
                );
                if ($draws !== $plan['indices']) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $seed;
            }
        }

        $this->fail('Unable to find deterministic combined missile sequences.');
    }

    protected function seedForDrawIndex(NationCommandQueueItem $item, int $count, int $index): string
    {
        $label = TurnRandomStreamFactory::missileImpact($item->id);
        for ($candidate = 0; $candidate < 10_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$candidate}");
            if ((new TurnRandomStreamFactory($seed))->stream($label)->integer(0, $count - 1) === $index) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic missile draw {$index} for {$label}.");
    }
}
