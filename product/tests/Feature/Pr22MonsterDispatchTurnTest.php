<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\DomesticCommandExecutor;
use App\Application\MonsterTurnService;
use App\Application\NationCreationService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class Pr22MonsterDispatchTurnTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_dispatched_monster_spawns_now_but_only_existing_monsters_act_until_next_turn(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '派遣国');
        [, $target] = $this->nation($world, '派遣対象国');
        $space = $this->surfaceMapSpace($world);
        $sender->update(['money' => 3_000]);
        $candidate = $this->singleDispatchCandidate($target);
        $beforePopulation = $candidate->population;
        $item = $this->queueDispatch($user, $sender, $target, $space);
        $context = $this->context($world, 2, 'dispatch spawn turn', [$sender->id]);

        app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame('completed', $item->fresh()->status);
        $monster = MonsterInstance::query()->where('world_id', $world->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'mecha_inora'))
            ->with(['definition', 'occupancy'])->firstOrFail();
        $spawnedCell = $monster->occupancy->cell()->with(['terrain', 'facility'])->firstOrFail();
        $this->assertSame($candidate->id, $spawnedCell->id);
        $this->assertSame('wasteland', $spawnedCell->terrain->key);
        $this->assertNull($spawnedCell->facility_definition_id);
        $this->assertSame(0, $spawnedCell->population);
        $this->assertGreaterThan(0, $beforePopulation);
        $this->assertSame($target->id, $spawnedCell->owner_nation_id);

        $defense = $this->neighborCells($space, $spawnedCell)[0];
        $this->setCell($defense, 'plain', 'defense', $target->id, 0);
        $existingOrigin = $this->remoteInteriorCell($space, $spawnedCell);
        $this->setCell($existingOrigin, 'wasteland', null, $target->id, 0);
        foreach ($this->neighborCells($space, $existingOrigin) as $neighbor) {
            $this->setCell($neighbor, 'plain', null, $target->id, 321);
        }
        $existing = $this->createMonster($world, $existingOrigin, 'inora', 1);
        $service = app(MonsterTurnService::class);
        $batch = $service->load($context);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $byCoordinate = $cells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();

        $this->assertSame(1, $batch->metrics()['monsters_loaded']);
        $this->assertFalse($service->processCell(
            $context,
            $space,
            $spawnedCell->fresh(['terrain', 'facility']),
            $byCoordinate,
            $batch,
        ));
        $this->assertSame($spawnedCell->id, $monster->fresh()->occupancy()->value('map_cell_id'));
        $this->assertSame('defense', $defense->fresh()->facility()->value('key'));
        $this->assertTrue($service->processCell(
            $context,
            $space,
            $existingOrigin->fresh(['terrain', 'facility']),
            $byCoordinate,
            $batch,
        ));
        $this->assertSame(1, $batch->metrics()['monster_actions']);
        $this->assertSame(1, $batch->metrics()['monster_moves']);
        $this->assertSame(0, $batch->metrics()['defense_self_destructs']);
        $this->assertNotSame($existingOrigin->id, $existing->fresh()->occupancy()->value('map_cell_id'));
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', ['monster.moved', 'monster.trampled'])
            ->where('subject_id', $monster->id)->count());

        foreach ($this->neighborCells($space, $spawnedCell) as $neighbor) {
            $this->setCell($neighbor, 'plain', null, $target->id, 123);
        }
        $nextContext = $this->context($world, 3, 'dispatch next turn', [$sender->id]);
        $nextBatch = $service->load($nextContext);
        $nextCells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $nextByCoordinate = $nextCells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();

        $this->assertSame(2, $nextBatch->metrics()['monsters_loaded']);
        $this->assertTrue($service->processCell(
            $nextContext,
            $space,
            $spawnedCell->fresh(['terrain', 'facility']),
            $nextByCoordinate,
            $nextBatch,
        ));
        $this->assertSame(1, $nextBatch->metrics()['monster_moves']);
        $this->assertNotSame($spawnedCell->id, $monster->fresh()->occupancy()->value('map_cell_id'));
    }

    public function test_multiple_dispatches_are_all_deferred_and_never_share_occupancy(): void
    {
        $world = $this->lightweightWorld();
        [$firstUser, $firstSender] = $this->nation($world, '第一派遣国');
        [$secondUser, $secondSender] = $this->nation($world, '第二派遣国');
        [, $target] = $this->nation($world, '複数派遣対象国');
        $space = $this->surfaceMapSpace($world);
        $firstSender->update(['money' => 3_000]);
        $secondSender->update(['money' => 3_000]);
        $this->twoDispatchCandidates($target);
        $this->queueDispatch($firstUser, $firstSender, $target, $space);
        $this->queueDispatch($secondUser, $secondSender, $target, $space);
        $context = $this->context(
            $world,
            2,
            'multiple dispatches',
            [$firstSender->id, $secondSender->id],
        );

        app(DomesticCommandExecutor::class)->execute($context);

        $monsters = MonsterInstance::query()->where('world_id', $world->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'mecha_inora'))
            ->with('occupancy')->orderBy('id')->get();
        $this->assertCount(2, $monsters);
        $this->assertCount(2, $monsters->pluck('occupancy.map_cell_id')->unique());
        $this->assertSame(
            $monsters->pluck('id')->sort()->values()->all(),
            $context->state->monsterIdsDeferredFromSpawnTurnMovement(),
        );
        $batch = app(MonsterTurnService::class)->load($context);
        $this->assertSame(0, $batch->metrics()['monsters_loaded']);
        $this->assertSame(0, $batch->metrics()['monster_actions']);
        $this->assertSame(0, $batch->metrics()['monster_moves']);
        $this->assertSame(2, MonsterOccupancy::query()->count());
    }

    public function test_dispatch_rollback_and_retry_reuses_the_position_without_same_turn_action(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '再試行派遣国');
        [, $target] = $this->nation($world, '再試行対象国');
        $space = $this->surfaceMapSpace($world);
        $sender->update(['money' => 3_000]);
        $candidate = $this->singleDispatchCandidate($target);
        $item = $this->queueDispatch($user, $sender, $target, $space);
        $firstCellId = null;

        try {
            DB::transaction(function () use ($world, $sender, &$firstCellId): void {
                $context = $this->context($world, 2, 'dispatch rollback retry', [$sender->id]);
                app(DomesticCommandExecutor::class)->execute($context);
                $firstCellId = (int) MonsterOccupancy::query()->value('map_cell_id');
                $this->assertSame(0, app(MonsterTurnService::class)->load($context)->metrics()['monsters_loaded']);

                throw new RuntimeException('dispatch rollback probe');
            });
            $this->fail('Expected dispatch rollback probe.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dispatch rollback probe', $exception->getMessage());
        }

        $this->assertSame(0, MonsterInstance::query()->count());
        $this->assertSame(0, MonsterOccupancy::query()->count());
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame('village', $candidate->fresh()->facility()->value('key'));

        $retry = $this->context($world, 2, 'dispatch rollback retry', [$sender->id]);
        app(DomesticCommandExecutor::class)->execute($retry);

        $this->assertSame($firstCellId, (int) MonsterOccupancy::query()->value('map_cell_id'));
        $this->assertSame(1, MonsterInstance::query()->count());
        $this->assertSame(1, MonsterOccupancy::query()->count());
        $this->assertSame(0, app(MonsterTurnService::class)->load($retry)->metrics()['monsters_loaded']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.spawned')->count());
    }

    /** @return array{User, Nation} */
    private function nation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name, '試験島主')];
    }

    private function singleDispatchCandidate(Nation $target): MapCell
    {
        $settlements = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', ['village', 'town', 'city']))
            ->with(['terrain', 'facility'])->orderBy('id')->get();
        $candidate = $settlements->first();
        $this->assertInstanceOf(MapCell::class, $candidate);
        MapCell::query()->whereIn('id', $settlements->pluck('id'))->update(['population' => 0]);
        $candidate->update(['population' => 1_000]);

        return $candidate->fresh(['terrain', 'facility']);
    }

    /** @return list<MapCell> */
    private function twoDispatchCandidates(Nation $target): array
    {
        $first = $this->singleDispatchCandidate($target);
        $second = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($first->id)->whereNull('facility_definition_id')
            ->with(['terrain', 'facility'])->firstOrFail();
        $this->setCell($second, 'plain', 'village', $target->id, 1_000);

        return [$first, $second->fresh(['terrain', 'facility'])];
    }

    private function queueDispatch(
        User $user,
        Nation $sender,
        Nation $target,
        MapSpace $space,
    ): NationCommandQueueItem {
        $version = (int) ($sender->commandQueue()->value('version') ?? 1);

        return app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: (string) Str::uuid(),
            expectedVersion: $version,
            quantity: 1,
            parameters: ['target_nation_id' => $target->id],
        )['item'];
    }

    /** @return list<MapCell> */
    private function neighborCells(MapSpace $space, MapCell $cell): array
    {
        return collect((new GridCoordinate($cell->x, $cell->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        ))->map(fn (GridCoordinate $coordinate): MapCell => MapCell::query()
            ->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->with(['terrain', 'facility'])->firstOrFail())->all();
    }

    private function remoteInteriorCell(MapSpace $space, MapCell $excluded): MapCell
    {
        return MapCell::query()->where('map_space_id', $space->id)
            ->whereBetween('x', [$space->min_x + 3, $space->max_x - 3])
            ->whereBetween('y', [$space->min_y + 3, $space->max_y - 3])
            ->with(['terrain', 'facility'])->get()
            ->first(fn (MapCell $cell): bool => (new GridCoordinate($cell->x, $cell->y))->distanceTo(
                new GridCoordinate($excluded->x, $excluded->y),
            ) > 6) ?? throw new RuntimeException('No remote interior cell was available.');
    }

    private function setCell(
        MapCell $cell,
        string $terrainKey,
        ?string $facilityKey,
        ?int $ownerNationId,
        int $population,
    ): void {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        if ($facilityKey !== null) {
            $states->setFacility($cell, FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail());
        }
        $cell->owner_nation_id = $ownerNationId;
        $cell->population = $population;
        $cell->save();
    }

    private function createMonster(World $world, MapCell $cell, string $definitionKey, int $spawnedTurn): MonsterInstance
    {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', $definitionKey)->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $definition->base_hp,
            'spawned_max_hp' => $definition->base_hp,
            'state' => 'alive',
            'spawned_target_turn' => $spawnedTurn,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);

        return $monster->fresh(['definition', 'occupancy']);
    }

    /** @param list<int> $developmentNationIds */
    private function context(World $world, int $targetTurn, string $seedLabel, array $developmentNationIds): TurnContext
    {
        $seed = hash('sha256', $seedLabel);
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
        $state->setStableNationIds($developmentNationIds);
        $state->setDevelopmentNationIds($developmentNationIds);

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
}
