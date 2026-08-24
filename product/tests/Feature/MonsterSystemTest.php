<?php

namespace Tests\Feature;

use App\Application\DisasterTurnService;
use App\Application\MonsterDamageService;
use App\Application\MonsterRemovalService;
use App\Application\MonsterSpawnService;
use App\Application\MonsterTurnService;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Monster\MonsterNaturalSpawnPolicy;
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
use App\Models\NationMonsterCycleStat;
use App\Models\NationMonsterKillStat;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\V11SecretaryItemRulesetFixture;
use Tests\TestCase;

class MonsterSystemTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_natural_spawn_is_one_independent_draw_per_active_nation_and_uses_snapshot_settlements(): void
    {
        [$world, $active, $ruleset, $space] = $this->worldAndNation('出現国');
        $other = $this->createNation($world, '第二出現国');
        $activeSettlement = $this->prepareSettlement($active, 400_000);
        $otherSettlement = $this->prepareSettlement($other, 400_000);
        $ruleset = $this->guaranteeNaturalSpawn($ruleset);
        [$context, $run] = $this->context($world, $ruleset, 2, 'spawn-active-snapshot', [$active->id, $other->id]);

        $metrics = app(MonsterSpawnService::class)->spawnNatural($context, $space);

        $this->assertSame(2, $metrics['eligible_spawn_nations']);
        $this->assertSame(2, $metrics['spawn_draws']);
        $this->assertSame(2, $metrics['monsters_spawned']);
        $occupancies = MonsterOccupancy::query()->with('monster.definition')->get()->keyBy('map_cell_id');
        $this->assertCount(2, $occupancies);
        foreach ([[$activeSettlement, $active], [$otherSettlement, $other]] as [$settlement, $nation]) {
            $occupancy = $occupancies->get($settlement->id);
            $this->assertNotNull($occupancy);
            $this->assertNotSame('mecha_inora', $occupancy->monster->definition->key);
            $this->assertContains($occupancy->monster->definition->key, [
                'inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost', 'whale', 'king_inora',
            ]);
            $spawnedCell = $settlement->fresh(['terrain', 'facility']);
            $this->assertSame('wasteland', $spawnedCell->terrain->key);
            $this->assertNull($spawnedCell->facility_definition_id);
            $this->assertSame(0, $spawnedCell->population);
            $this->assertSame($nation->id, $spawnedCell->owner_nation_id);
        }
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'monster.spawned')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
    }

    public function test_natural_spawn_supports_ten_definitions_without_adding_non_pool_species_or_changing_the_type_draw(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('十種自然発生国');
        $settings = $ruleset->settings;
        $ruleset->settings = $settings;
        $this->prepareSettlement($nation, 400_000);
        $ruleset = $this->guaranteeNaturalSpawn($ruleset);
        $seedLabel = 'ten-definition-spawn';
        [$context] = $this->context($world, $ruleset, 2, $seedLabel, [$nation->id]);
        $pool = app(MonsterNaturalSpawnPolicy::class)->poolForPopulation(
            $ruleset->settings['monster_system']['natural_spawn'],
            400_000,
        );
        $seed = hash('sha256', $seedLabel);
        $expected = $pool[(new TurnRandomStreamFactory($seed))->stream(
            TurnRandomStreamFactory::monsterSpawn($nation->id, 'type', 1),
        )->integer(0, count($pool) - 1)];

        $metrics = app(MonsterSpawnService::class)->spawnNatural($context, $space);

        $this->assertSame(1, $metrics['monsters_spawned']);
        $spawnedKey = MonsterInstance::query()->with('definition')->sole()->definition->key;
        $this->assertSame($expected, $spawnedKey);
        $this->assertNotContains($spawnedKey, ['mecha_inora_zero', 'aoi_inora']);
        $this->assertSame(10, MonsterDefinition::query()
            ->where('ruleset_version_id', $ruleset->id)->count());
    }

    public function test_natural_spawn_keeps_the_authored_pool_and_draw_with_many_unpooled_definitions(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('多種自然発生国');
        $settings = $ruleset->settings;
        $template = V11SecretaryItemRulesetFixture::newMonsterDefinitions()[0];
        foreach (range(1, 10) as $index) {
            $payload = $template;
            $payload['key'] = "unpooled_monster_{$index}";
            $payload['name'] = "非抽選怪獣{$index}";
            $payload['asset_key'] = "hakoniwa_custom.monster.unpooled_monster_{$index}";
            $payload['display_order'] = 700 + ($index * 100);
            $settings['monster_definitions'][] = $payload;
            MonsterDefinition::query()->create(['ruleset_version_id' => $ruleset->id, ...$payload]);
        }
        $ruleset->settings = $settings;
        $this->prepareSettlement($nation, 400_000);
        $ruleset = $this->guaranteeNaturalSpawn($ruleset);
        $seedLabel = 'many-definition-spawn';
        [$context] = $this->context($world, $ruleset, 2, $seedLabel, [$nation->id]);
        $pool = app(MonsterNaturalSpawnPolicy::class)->poolForPopulation(
            $ruleset->settings['monster_system']['natural_spawn'],
            400_000,
        );
        $seed = hash('sha256', $seedLabel);
        $expected = $pool[(new TurnRandomStreamFactory($seed))->stream(
            TurnRandomStreamFactory::monsterSpawn($nation->id, 'type', 1),
        )->integer(0, count($pool) - 1)];

        $metrics = app(MonsterSpawnService::class)->spawnNatural($context, $space);

        $this->assertSame(1, $metrics['spawn_draws']);
        $this->assertSame(1, $metrics['monsters_spawned']);
        $spawnedKey = MonsterInstance::query()->with('definition')->sole()->definition->key;
        $this->assertSame($expected, $spawnedKey);
        $this->assertContains($spawnedKey, $pool);
        $this->assertSame(20, MonsterDefinition::query()
            ->where('ruleset_version_id', $ruleset->id)->count());
    }

    public function test_natural_spawn_excludes_a_dormant_nation(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('除外国');
        $nation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        $this->prepareSettlement($nation, 400_000);
        $ruleset = $this->guaranteeNaturalSpawn($ruleset);
        [$context] = $this->context($world, $ruleset, 2, 'spawn-dormant', [$nation->id]);

        $metrics = app(MonsterSpawnService::class)->spawnNatural($context, $space);

        $this->assertSame(0, $metrics['eligible_spawn_nations']);
        $this->assertSame(0, $metrics['spawn_draws']);
        $this->assertSame(0, MonsterInstance::query()->count());
    }

    public function test_recovery_excludes_natural_spawn_and_protects_all_territory_from_monster_movement(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('休戦怪獣除外国');
        $this->prepareSettlement($nation, 400_000);
        $ruleset = $this->guaranteeNaturalSpawn($ruleset);
        $nation->update([
            'state' => 'recovery',
            'state_reason' => null,
            'state_started_turn' => 2,
            'resume_at_turn' => 87,
        ]);
        [$spawnContext] = $this->context($world, $ruleset, 2, 'spawn-recovery', [$nation->id]);

        $metrics = app(MonsterSpawnService::class)->spawnNatural($spawnContext, $space);

        $this->assertSame(0, $metrics['eligible_spawn_nations']);
        $this->assertSame(0, $metrics['spawn_draws']);
        $this->assertSame(0, MonsterInstance::query()->count());

        $origin = $this->safeInteriorCell($space, $world);
        $originCoordinate = new GridCoordinate($origin->x, $origin->y);
        $protectedCoordinate = $originCoordinate->neighbor(0);
        $fallbackCoordinate = $originCoordinate->neighbor(1);
        $protected = $this->cellAt($space, $protectedCoordinate->x, $protectedCoordinate->y);
        $fallback = $this->cellAt($space, $fallbackCoordinate->x, $fallbackCoordinate->y);
        $this->setCell($origin, 'wasteland', null, null, 0);
        $this->setCell($protected, 'plain', null, $nation->id, 4_321);
        $this->setCell($fallback, 'plain', null, null, 1_234);
        $monster = $this->createMonster($world, $ruleset, $origin, 'inora', 1);
        $seedLabel = $this->movementSeedForDirections($monster, [0, 1]);
        [$movementContext] = $this->context($world, $ruleset, 2, $seedLabel, [$nation->id]);
        $capital = $nation->capital()->firstOrFail();
        $movementContext->state->setNationLifecycleSnapshot($nation->id, [
            'state' => 'recovery',
            'reason' => null,
            'state_started_turn' => 2,
            'resume_at_turn' => 87,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);
        $movementContext->state->setRecoveryTerritoryNationIds(
            MapCell::query()->where('owner_nation_id', $nation->id)->get(['x', 'y'])
                ->mapWithKeys(static fn (MapCell $cell): array => ["{$cell->x}:{$cell->y}" => $nation->id])
                ->all(),
        );
        $turn = app(MonsterTurnService::class);
        $batch = $turn->load($movementContext);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $index = $cells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();

        $this->assertTrue($turn->processCell(
            $movementContext,
            $space,
            $origin->fresh(['terrain', 'facility']),
            $index,
            $batch,
        ));

        $this->assertSame($fallback->id, (int) MonsterOccupancy::query()
            ->where('monster_instance_id', $monster->id)->value('map_cell_id'));
        $this->assertSame(1, $batch->metrics()['monster_moves']);
        $this->assertSame(4_321, $protected->fresh()->population);
        $this->assertSame(0, $fallback->fresh()->population);
        $this->assertSame('wasteland', $fallback->fresh()->terrain()->value('key'));
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'monster.trampled',
            'x' => $fallback->x,
            'y' => $fallback->y,
        ]);
    }

    public function test_monster_inside_the_dormant_capital_radius_cannot_move_or_trample(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('休止怪獣保護国');
        $capital = $nation->capital()->firstOrFail();
        $coordinate = (new GridCoordinate($capital->x, $capital->y))->ring(2)[0];
        $origin = $this->cellAt($space, $coordinate->x, $coordinate->y);
        $this->setCell($origin, 'wasteland', null, $nation->id, 0);
        $monster = $this->createMonster($world, $ruleset, $origin, 'inora', 1);
        $nation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        [$context] = $this->context($world, $ruleset, 2, 'dormant-monster-protection', [$nation->id]);
        $context->state->setNationLifecycleSnapshot($nation->id, [
            'state' => 'dormant',
            'reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);
        $turn = app(MonsterTurnService::class);
        $batch = $turn->load($context);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $index = $cells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();

        $this->assertTrue($turn->processCell(
            $context,
            $space,
            $origin->fresh(['terrain', 'facility']),
            $index,
            $batch,
        ));

        $this->assertSame($origin->id, (int) MonsterOccupancy::query()
            ->where('monster_instance_id', $monster->id)->value('map_cell_id'));
        $this->assertSame(0, $batch->metrics()['monster_moves']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.stayed')
            ->whereRaw("metadata->>'reason' = 'dormant_capital_protected'")->count());

        $capitalCoordinate = new GridCoordinate($capital->x, $capital->y);
        $outsideOrigin = null;
        $protectedDestination = null;
        $fallbackDestination = null;
        $protectedDirection = null;
        $fallbackDirection = null;
        foreach ($capitalCoordinate->ring(3) as $originCoordinate) {
            $originCandidate = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $originCoordinate->x)->where('y', $originCoordinate->y)->first();
            if (! $originCandidate instanceof MapCell || $originCandidate->id === $origin->id) {
                continue;
            }
            foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
                $destinationCoordinate = $originCoordinate->neighbor($direction);
                if ($capitalCoordinate->distanceTo($destinationCoordinate) !== 2) {
                    continue;
                }
                $destinationCandidate = MapCell::query()->where('map_space_id', $space->id)
                    ->where('x', $destinationCoordinate->x)->where('y', $destinationCoordinate->y)->first();
                if (! $destinationCandidate instanceof MapCell || $destinationCandidate->id === $origin->id) {
                    continue;
                }
                foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $nextDirection) {
                    if ($nextDirection === $direction) {
                        continue;
                    }
                    $fallbackCoordinate = $originCoordinate->neighbor($nextDirection);
                    if ($capitalCoordinate->distanceTo($fallbackCoordinate) <= 2) {
                        continue;
                    }
                    $fallbackCandidate = MapCell::query()->where('map_space_id', $space->id)
                        ->where('x', $fallbackCoordinate->x)->where('y', $fallbackCoordinate->y)->first();
                    if ($fallbackCandidate instanceof MapCell
                        && ! in_array($fallbackCandidate->id, [$origin->id, $destinationCandidate->id], true)) {
                        $outsideOrigin = $originCandidate;
                        $protectedDestination = $destinationCandidate;
                        $fallbackDestination = $fallbackCandidate;
                        $protectedDirection = $direction;
                        $fallbackDirection = $nextDirection;
                        break 3;
                    }
                }
            }
        }
        $this->assertInstanceOf(MapCell::class, $outsideOrigin);
        $this->assertInstanceOf(MapCell::class, $protectedDestination);
        $this->assertInstanceOf(MapCell::class, $fallbackDestination);
        $this->assertNotNull($protectedDirection);
        $this->assertNotNull($fallbackDirection);
        $this->setCell($outsideOrigin, 'wasteland', null, $nation->id, 0);
        $this->setCell($protectedDestination, 'plain', null, $nation->id, 4_321);
        $this->setCell($fallbackDestination, 'plain', null, $nation->id, 1_234);
        $outsideMonster = $this->createMonster($world, $ruleset, $outsideOrigin, 'inora', 1);
        $seedLabel = $this->movementSeedForDirections(
            $outsideMonster,
            [$protectedDirection, $fallbackDirection],
        );
        [$destinationContext] = $this->context($world, $ruleset, 3, $seedLabel, [$nation->id]);
        $destinationContext->state->setNationLifecycleSnapshot($nation->id, [
            'state' => 'dormant',
            'reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);
        $destinationBatch = $turn->load($destinationContext);
        $destinationCells = MapCell::query()->where('map_space_id', $space->id)
            ->with(['terrain', 'facility'])->get();
        $destinationIndex = $destinationCells->keyBy(
            static fn (MapCell $cell): string => $cell->x.':'.$cell->y,
        )->all();

        $this->assertTrue($turn->processCell(
            $destinationContext,
            $space,
            $outsideOrigin->fresh(['terrain', 'facility']),
            $destinationIndex,
            $destinationBatch,
        ));

        $this->assertSame($fallbackDestination->id, (int) MonsterOccupancy::query()
            ->where('monster_instance_id', $outsideMonster->id)->value('map_cell_id'));
        $this->assertSame(1, $destinationBatch->metrics()['monster_moves']);
        $this->assertSame(4_321, $protectedDestination->fresh()->population);
        $this->assertSame(0, $fallbackDestination->fresh()->population);
        $this->assertSame('wasteland', $fallbackDestination->fresh()->terrain()->value('key'));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'monster.stayed')
            ->whereRaw("metadata->>'monster_id' = ?", [(string) $outsideMonster->id])
            ->whereRaw("metadata->>'reason' = 'dormant_destination_protected'")->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.trampled')
            ->where('x', $fallbackDestination->x)->where('y', $fallbackDestination->y)->count());
    }

    public function test_triggered_spawn_without_an_eligible_settlement_is_a_safe_audited_noop(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('候補なし国');
        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        $capital = $nation->capital()->firstOrFail()->cell()->firstOrFail();
        $capital->update(['population' => 400_000]);
        $ruleset = $this->guaranteeNaturalSpawn($ruleset);
        [$context, $run] = $this->context($world, $ruleset, 2, 'spawn-no-candidate', [$nation->id]);

        $metrics = app(MonsterSpawnService::class)->spawnNatural($context, $space);

        $this->assertSame(1, $metrics['eligible_spawn_nations']);
        $this->assertSame(1, $metrics['blocked_no_settlement']);
        $this->assertSame(0, $metrics['monsters_spawned']);
        $this->assertSame(0, MonsterInstance::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.spawn_failed_no_settlement')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
        $this->assertSame('admin', DB::table('audit_events')
            ->where('event_type', 'monster.spawn_failed_no_settlement')->value('visibility'));
    }

    public function test_natural_spawn_replays_the_same_actor_after_transaction_rollback(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('出現再試行国');
        $settlement = $this->prepareSettlement($nation, 400_000);
        $ruleset = $this->guaranteeNaturalSpawn($ruleset);
        [$initialContext, $run] = $this->context($world, $ruleset, 2, 'spawn-retry', [$nation->id]);
        $seed = $initialContext->randomSeed;
        $snapshot = static fn (): array => MonsterOccupancy::query()
            ->with('monster.definition')
            ->get()
            ->map(static fn (MonsterOccupancy $occupancy): array => [
                'cell_id' => $occupancy->map_cell_id,
                'key' => $occupancy->monster->definition->key,
                'hp' => $occupancy->monster->current_hp,
            ])->all();
        $newContext = static function () use ($world, $run, $ruleset, $seed, $nation): TurnContext {
            $state = new TurnState;
            $state->setStableNationIds([$nation->id]);
            $state->setDevelopmentNationIds([$nation->id]);

            return new TurnContext(
                $world,
                $run,
                $ruleset,
                2,
                $seed,
                new TurnRandomStreamFactory($seed),
                $state,
            );
        };

        $first = null;
        try {
            DB::transaction(function () use ($newContext, $space, $snapshot, &$first): void {
                app(MonsterSpawnService::class)->spawnNatural($newContext(), $space);
                $first = $snapshot();
                throw new RuntimeException('spawn rollback probe');
            });
            $this->fail('Expected spawn rollback probe.');
        } catch (RuntimeException $exception) {
            $this->assertSame('spawn rollback probe', $exception->getMessage());
        }

        $this->assertSame(0, MonsterInstance::query()->count());
        $this->assertSame('village', $settlement->fresh()->facility()->value('key'));
        app(MonsterSpawnService::class)->spawnNatural($newContext(), $space);

        $this->assertSame($first, $snapshot());
    }

    public function test_dark_inora_moves_at_most_twice_tramples_and_changes_current_host_nation(): void
    {
        [$world, $first, $ruleset, $space] = $this->worldAndNation('移動元国');
        $second = $this->createNation($world, '移動先国');
        $origin = $this->safeInteriorCell($space, $world);
        foreach ((new GridCoordinate($origin->x, $origin->y))->radius(3) as $coordinate) {
            $cell = $this->cellAt($space, $coordinate->x, $coordinate->y);
            $this->setCell($cell, 'plain', 'village', $second->id, 321);
        }
        $this->setCell($origin, 'wasteland', null, $first->id, 0);
        $monster = $this->createMonster($world, $ruleset, $origin, 'dark_inora', 3);
        $seedLabel = $this->twoMoveSeedThatDoesNotReturnToOrigin(
            $monster,
            new GridCoordinate($origin->x, $origin->y),
        );
        [$context] = $this->context($world, $ruleset, 2, $seedLabel, [$first->id, $second->id]);
        $service = app(MonsterTurnService::class);
        $batch = $service->load($context);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $byCoordinate = $cells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();

        $this->assertTrue($service->processCell($context, $space, $origin->fresh(['terrain', 'facility']), $byCoordinate, $batch));
        $firstDestinationId = MonsterOccupancy::query()->where('monster_instance_id', $monster->id)->value('map_cell_id');
        $firstDestination = $cells->firstWhere('id', $firstDestinationId);
        $this->assertNotNull($firstDestination);
        $this->assertSame($second->id, MapCell::query()->findOrFail($firstDestinationId)->owner_nation_id);
        $this->assertTrue($service->processCell($context, $space, $firstDestination, $byCoordinate, $batch));
        $secondDestinationId = MonsterOccupancy::query()->where('monster_instance_id', $monster->id)->value('map_cell_id');
        $secondDestination = $cells->firstWhere('id', $secondDestinationId);
        $this->assertNotNull($secondDestination);
        $this->assertTrue($service->processCell($context, $space, $secondDestination, $byCoordinate, $batch));

        $this->assertSame(2, $batch->metrics()['monster_moves']);
        $this->assertSame(2, $batch->metrics()['maximum_moves_by_single_monster']);
        $occupiedCell = MapCell::query()->with(['terrain', 'facility'])->findOrFail($secondDestinationId);
        $this->assertSame('wasteland', $occupiedCell->terrain->key);
        $this->assertNull($occupiedCell->facility_definition_id);
        $this->assertSame(0, $occupiedCell->population);
        $this->assertContains($origin->map_chunk_id, $context->state->changedMapChunkIds());
        $this->assertContains($occupiedCell->map_chunk_id, $context->state->changedMapChunkIds());
        $destinationEvents = collect(app(PlayerIslandEventService::class)->publicNationPage($second->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->filter(fn (array $event): bool => in_array($event['type'], ['monster.moved', 'monster.trampled'], true))
            ->values();
        $movedEvents = $destinationEvents->where('type', 'monster.moved')->values();
        $trampledEvents = $destinationEvents->where('type', 'monster.trampled')->values();
        $this->assertCount(2, $movedEvents);
        $this->assertCount(2, $trampledEvents);
        $messages = $destinationEvents->pluck('message')->all();
        $this->assertContains(
            sprintf(
                '%s(%d,%d)へダークいのらが移動した模様です。',
                $second->name,
                $firstDestination->x,
                $firstDestination->y,
            ),
            $messages,
        );
        $this->assertContains(
            sprintf(
                '%s(%d,%d)の村がダークいのらに踏み荒らされました。',
                $second->name,
                $firstDestination->x,
                $firstDestination->y,
            ),
            $messages,
        );
        $this->assertStringNotContainsString(
            sprintf('%s(%d,%d)', $second->name, $origin->x, $origin->y),
            implode("\n", $messages),
        );
        foreach ($trampledEvents as $event) {
            $this->assertStringContainsString('の村がダークいのらに踏み荒らされました。', $event['message']);
        }

        $originEvents = collect(app(PlayerIslandEventService::class)->publicNationPage($first->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->filter(fn (array $event): bool => in_array($event['type'], ['monster.moved', 'monster.trampled'], true));
        $this->assertCount(0, $originEvents);
    }

    public function test_normal_monster_moves_once_then_stops_at_its_definition_limit(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('通常移動国');
        $origin = $this->safeInteriorCell($space, $world);
        foreach ((new GridCoordinate($origin->x, $origin->y))->radius(2) as $coordinate) {
            $this->setCell($this->cellAt($space, $coordinate->x, $coordinate->y), 'plain', null, $nation->id, 0);
        }
        $this->setCell($origin, 'wasteland', null, $nation->id, 0);
        $monster = $this->createMonster($world, $ruleset, $origin, 'inora', 1);
        [$context] = $this->context($world, $ruleset, 2, 'normal-one-move', [$nation->id]);
        $turn = app(MonsterTurnService::class);
        $batch = $turn->load($context);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $index = $cells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();

        $this->assertTrue($turn->processCell($context, $space, $origin->fresh(['terrain', 'facility']), $index, $batch));
        $destinationId = (int) MonsterOccupancy::query()->where('monster_instance_id', $monster->id)
            ->value('map_cell_id');
        $destination = $cells->firstWhere('id', $destinationId);
        $this->assertInstanceOf(MapCell::class, $destination);
        $this->assertTrue($turn->processCell($context, $space, $destination, $index, $batch));

        $this->assertSame($destinationId, (int) MonsterOccupancy::query()
            ->where('monster_instance_id', $monster->id)->value('map_cell_id'));
        $this->assertSame(1, $batch->metrics()['monster_moves']);
        $this->assertSame(2, $batch->metrics()['monster_actions']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.stayed')
            ->whereRaw("metadata->>'reason' = 'movement_limit'")->count());
    }

    public function test_world_edge_and_monster_collisions_leave_actor_in_place(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('端衝突国');
        $origin = $this->cellAt($space, $space->min_x, $space->min_y);
        $this->setCell($origin, 'wasteland', null, $nation->id, 0);
        $monster = $this->createMonster($world, $ruleset, $origin, 'inora', 1);
        $coordinate = new GridCoordinate($origin->x, $origin->y);
        foreach ($coordinate->neighborsWithin($space->min_x, $space->max_x, $space->min_y, $space->max_y) as $neighbor) {
            $cell = $this->cellAt($space, $neighbor->x, $neighbor->y);
            $this->setCell($cell, 'wasteland', null, $nation->id, 0);
            $this->createMonster($world, $ruleset, $cell, 'inora', 1);
        }
        [$context] = $this->context($world, $ruleset, 2, 'edge-collision', [$nation->id]);
        $turn = app(MonsterTurnService::class);
        $batch = $turn->load($context);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $index = $cells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();

        $this->assertTrue($turn->processCell($context, $space, $origin->fresh(['terrain', 'facility']), $index, $batch));

        $this->assertSame($origin->id, (int) MonsterOccupancy::query()
            ->where('monster_instance_id', $monster->id)->value('map_cell_id'));
        $this->assertSame(0, $batch->metrics()['monster_moves']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.stayed')
            ->whereRaw("metadata->>'reason' = 'no_candidate'")->count());
    }

    public function test_hardened_monster_neither_moves_nor_takes_normal_damage(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('硬化国');
        $cell = $this->ownedNonCapitalCell($nation);
        $this->setCell($cell, 'wasteland', null, $nation->id, 0);
        $monster = $this->createMonster($world, $ruleset, $cell, 'sanjira', 2);
        $base = $this->ownedNonCapitalCell($nation);
        $this->setCell($base, 'plain', 'missile_base', $nation->id, 0);
        [$context] = $this->context($world, $ruleset, 3, 'hardened-odd', [$nation->id]);
        $turn = app(MonsterTurnService::class);
        $batch = $turn->load($context);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $index = $cells->keyBy(static fn (MapCell $candidate): string => $candidate->x.':'.$candidate->y)->all();

        $this->assertTrue($turn->processCell($context, $space, $cell->fresh(['terrain', 'facility']), $index, $batch));
        $damage = app(MonsterDamageService::class)->applyDamage(
            $monster,
            1,
            'monster_missile',
            $nation,
            $base,
            $cell,
            $context,
        );

        $this->assertSame(0, $batch->metrics()['monster_moves']);
        $this->assertSame('blocked_hardened', $damage->status);
        $this->assertSame(0, $damage->actualDamage);
        $this->assertSame(0, $damage->firingBaseExperienceApplied);
        $this->assertSame(0, $base->fresh()->facility_experience);
        $this->assertSame(2, $monster->fresh()->current_hp);
        $this->assertSame($cell->id, $monster->fresh()->occupancy()->value('map_cell_id'));
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $metadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'monster.damage_blocked')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($nation->id, $metadata['attacker_nation_id']);
        $this->assertSame('硬化国', $metadata['attacker_nation_name']);
        $this->assertSame($nation->id, $metadata['host_nation_id']);
        $this->assertSame('硬化国', $metadata['host_nation_name']);
    }

    public function test_nonlethal_damage_snapshots_attacker_and_host_for_public_projection(): void
    {
        [$world, $host, $ruleset] = $this->worldAndNation('損傷所在国');
        $attacker = $this->createNation($world, '損傷攻撃国');
        $cell = $this->ownedNonCapitalCell($host);
        $this->setCell($cell, 'wasteland', null, $host->id, 0);
        $monster = $this->createMonster($world, $ruleset, $cell, 'inora', 2);
        [$context] = $this->context($world, $ruleset, 2, 'nonlethal-damage-snapshot', [$host->id, $attacker->id]);

        $result = app(MonsterDamageService::class)->applyDamage(
            $monster,
            1,
            'monster_missile',
            $attacker,
            null,
            $cell,
            $context,
        );

        $this->assertSame('damaged', $result->status);
        $metadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'monster.damaged')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($attacker->id, $metadata['attacker_nation_id']);
        $this->assertSame('損傷攻撃国', $metadata['attacker_nation_name']);
        $this->assertSame($host->id, $metadata['host_nation_id']);
        $this->assertSame('損傷所在国', $metadata['host_nation_name']);

        $host->update(['name' => '現在損傷所在国']);
        $events = collect(app(PlayerIslandEventService::class)->publicNationPage($host->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertSame(1, $events->where('type', 'monster.damaged')->count());
        $this->assertSame(
            sprintf('損傷所在国(%d,%d)のいのらに攻撃が命中し、苦しそうに咆哮しました。', $cell->x, $cell->y),
            $events->firstWhere('type', 'monster.damaged')['message'],
        );
    }

    public function test_monster_movement_replays_the_same_result_after_transaction_rollback(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('再現国');
        $origin = $this->safeInteriorCell($space, $world);
        $originCoordinate = new GridCoordinate($origin->x, $origin->y);
        $this->setCell($origin, 'wasteland', null, $nation->id, 0);
        foreach ($originCoordinate->radius(1) as $coordinate) {
            if ($coordinate->x === $origin->x && $coordinate->y === $origin->y) {
                continue;
            }
            $this->setCell($this->cellAt($space, $coordinate->x, $coordinate->y), 'plain', null, $nation->id, 0);
        }
        $monster = $this->createMonster($world, $ruleset, $origin, 'whale', 4);
        $runOnce = function () use ($world, $nation, $ruleset, $space, $monster): array {
            [$context] = $this->context($world, $ruleset, 2, 'monster-replay', [$nation->id]);
            $turn = app(MonsterTurnService::class);
            $batch = $turn->load($context);
            $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
            $index = $cells->keyBy(static fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();
            $currentCellId = MonsterOccupancy::query()->where('monster_instance_id', $monster->id)
                ->value('map_cell_id');
            $currentCell = MapCell::query()->with(['terrain', 'facility'])->findOrFail($currentCellId);
            $turn->processCell($context, $space, $currentCell, $index, $batch);
            $destination = MonsterOccupancy::query()->where('monster_instance_id', $monster->id)
                ->with('cell')->firstOrFail()->cell;

            return ['x' => $destination->x, 'y' => $destination->y, 'metrics' => $batch->metrics()];
        };

        $first = null;
        try {
            DB::transaction(function () use (&$first, $runOnce): void {
                $first = $runOnce();
                throw new RuntimeException('replay rollback');
            });
            $this->fail('Expected deterministic replay rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('replay rollback', $exception->getMessage());
        }

        $this->assertSame($first, $runOnce());
    }

    public function test_defense_contact_removes_hardened_monster_without_rewards_and_applies_one_huge_blast(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('防衛国');
        $origin = $this->safeInteriorCell($space, $world);
        $this->setCell($origin, 'wasteland', null, $nation->id, 0);
        $monster = $this->createMonster($world, $ruleset, $origin, 'inora', 1);
        [$context] = $this->context($world, $ruleset, 2, 'defense-contact', [$nation->id]);
        $direction = (new TurnRandomStreamFactory(hash('sha256', 'defense-contact')))->stream(
            TurnRandomStreamFactory::monsterMovement($monster->id, 1),
        )->integer(0, 5);
        $defenseCoordinate = (new GridCoordinate($origin->x, $origin->y))->neighbor($direction);
        $defense = $this->cellAt($space, $defenseCoordinate->x, $defenseCoordinate->y);
        $this->assertTrue(FacilityDefinition::query()->where('key', 'defense')->exists());
        $this->setCell($defense, 'plain', 'defense', $nation->id, 0);
        $victimCoordinate = collect($defenseCoordinate->ring(2))->first();
        $this->assertInstanceOf(GridCoordinate::class, $victimCoordinate);
        $victimCell = $this->cellAt($space, $victimCoordinate->x, $victimCoordinate->y);
        $this->setCell($victimCell, 'wasteland', null, $nation->id, 0);
        $blastVictim = $this->createMonster($world, $ruleset, $victimCell, 'inora', 1);
        $beforeMoney = (int) $nation->money;
        $turn = app(MonsterTurnService::class);
        $batch = $turn->load($context);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility'])->get();
        $index = $cells->keyBy(static fn (MapCell $candidate): string => $candidate->x.':'.$candidate->y)->all();

        $this->assertTrue($turn->processCell($context, $space, $origin->fresh(['terrain', 'facility']), $index, $batch));
        $this->assertFalse($turn->processCell(
            $context,
            $space,
            $victimCell->fresh(['terrain', 'facility']),
            $index,
            $batch,
        ));

        $this->assertSame('removed', $monster->fresh()->state);
        $this->assertSame('defense_self_destruct', $monster->fresh()->removal_reason);
        $this->assertFalse($monster->fresh()->occupancy()->exists());
        $this->assertSame('removed', $blastVictim->fresh()->state);
        $this->assertSame('defense_self_destruct', $blastVictim->fresh()->removal_reason);
        $this->assertFalse($blastVictim->fresh()->occupancy()->exists());
        $this->assertSame('wasteland', $victimCell->fresh()->terrain()->value('key'));
        $this->assertSame(1, $batch->metrics()['defense_self_destructs']);
        $this->assertSame(1, $batch->metrics()['monster_actions']);
        $this->assertSame('sea', $defense->fresh()->terrain()->value('key'));
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $this->assertSame($beforeMoney, (int) $nation->fresh()->money);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.defense_self_destructed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'disaster.triggered')
            ->whereRaw("metadata->>'disaster_key' = 'defense_self_destruct'")->count());
    }

    public function test_fire_preserves_hardened_monster_while_huge_blast_removes_center_and_wasteland_ring_two_without_rewards(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('地形相互作用国');
        $cell = $this->safeInteriorCell($space, $world);
        $this->setCell($cell, 'plain', 'factory', $nation->id, 0);
        $monster = $this->createMonster($world, $ruleset, $cell, 'whale', 4);
        $ringTwoCoordinate = (new GridCoordinate($cell->x, $cell->y))->ring(2)[0];
        $ringTwoCell = $this->cellAt($space, $ringTwoCoordinate->x, $ringTwoCoordinate->y);
        $this->setCell($ringTwoCell, 'wasteland', null, $nation->id, 0);
        $ringTwoMonster = $this->createMonster($world, $ruleset, $ringTwoCell, 'inora', 1);
        [$context] = $this->context($world, $ruleset, 2, 'terrain-removal', [$nation->id]);
        $removal = app(MonsterRemovalService::class);
        $removal->beginWorld($context);
        $disasters = app(DisasterTurnService::class);
        $beforeMoney = (int) $nation->money;

        $this->assertFalse($disasters->processFire($context, $cell->fresh(['terrain', 'facility'])));
        $this->assertSame('alive', $monster->fresh()->state);
        $settings = $ruleset->settings['turn_processing']['disasters']['huge_meteor'];
        $settings['radius'] = 0;
        $this->assertGreaterThanOrEqual(1, $disasters->resolveHugeMeteorBlast(
            $context,
            $space,
            new GridCoordinate($cell->x, $cell->y),
            $settings,
            'huge_meteor',
        ));

        $this->assertSame('removed', $monster->fresh()->state);
        $this->assertSame('huge_meteor', $monster->fresh()->removal_reason);
        $this->assertSame('removed', $ringTwoMonster->fresh()->state);
        $this->assertSame('huge_meteor', $ringTwoMonster->fresh()->removal_reason);
        $this->assertFalse($ringTwoMonster->fresh()->occupancy()->exists());
        $this->assertSame('wasteland', $ringTwoCell->fresh()->terrain()->value('key'));
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $this->assertSame(0, NationMonsterCycleStat::query()->count());
        $this->assertSame($beforeMoney, (int) $nation->fresh()->money);
        $this->assertSame(2, DB::table('audit_events')
            ->where('event_type', 'monster.removed_by_terrain_event')->count());
        $this->assertSame('sea', $cell->fresh()->terrain()->value('key'));
    }

    public function test_next_turn_command_blast_does_not_reuse_a_stale_monster_removal_batch(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('跨ターン除去国');
        $cell = $this->safeInteriorCell($space, $world);
        $this->setCell($cell, 'wasteland', null, $nation->id, 0);
        [$previousContext] = $this->context($world, $ruleset, 2, 'previous-turn-removal-batch', [$nation->id]);
        $removal = app(MonsterRemovalService::class);
        $this->assertSame(0, $removal->beginWorld($previousContext));

        $monster = $this->createMonster($world, $ruleset, $cell, 'inora', 1);
        [$nextContext] = $this->context($world, $ruleset, 3, 'next-turn-command-blast', [$nation->id]);
        $settings = $ruleset->settings['turn_processing']['disasters']['huge_meteor'];
        $settings['radius'] = 0;

        $this->assertGreaterThanOrEqual(1, app(DisasterTurnService::class)->resolveHugeMeteorBlast(
            $nextContext,
            $space,
            new GridCoordinate($cell->x, $cell->y),
            $settings,
            'defense_self_destruct',
        ));

        $this->assertSame('removed', $monster->fresh()->state);
        $this->assertSame('defense_self_destruct', $monster->fresh()->removal_reason);
        $this->assertFalse($monster->fresh()->occupancy()->exists());
    }

    public function test_same_run_retry_attempt_does_not_reuse_a_rolled_back_monster_removal_batch(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('再試行除去国');
        $cell = $this->safeInteriorCell($space, $world);
        $this->setCell($cell, 'wasteland', null, $nation->id, 0);
        $monster = $this->createMonster($world, $ruleset, $cell, 'inora', 1);
        [$context, $run] = $this->context($world, $ruleset, 2, 'same-run-retry-blast', [$nation->id]);
        $removal = app(MonsterRemovalService::class);
        $settings = $ruleset->settings['turn_processing']['disasters']['huge_meteor'];
        $settings['radius'] = 0;

        DB::beginTransaction();
        $this->assertSame(1, $removal->beginWorld($context));
        $this->assertGreaterThanOrEqual(1, app(DisasterTurnService::class)->resolveHugeMeteorBlast(
            $context,
            $space,
            new GridCoordinate($cell->x, $cell->y),
            $settings,
            'defense_self_destruct',
        ));
        DB::rollBack();

        $this->assertSame('alive', $monster->fresh()->state);
        $this->assertTrue($monster->fresh()->occupancy()->exists());
        $run->refresh();
        $run->attempt_count++;
        $run->save();

        $this->assertGreaterThanOrEqual(1, app(DisasterTurnService::class)->resolveHugeMeteorBlast(
            $context,
            $space,
            new GridCoordinate($cell->x, $cell->y),
            $settings,
            'defense_self_destruct',
        ));

        $this->assertSame('removed', $monster->fresh()->state);
        $this->assertSame('defense_self_destruct', $monster->fresh()->removal_reason);
        $this->assertFalse($monster->fresh()->occupancy()->exists());
    }

    public function test_final_blow_splits_capacity_bounded_rewards_caps_base_experience_and_is_idempotent(): void
    {
        [$world, $host, $ruleset] = $this->worldAndNation('所在国');
        $killer = $this->createNation($world, '撃破国');
        $spectator = $this->createNation($world, '第三国');
        $hostCell = $this->ownedNonCapitalCell($host);
        $this->setCell($hostCell, 'wasteland', null, $host->id, 0);
        $monster = $this->createMonster($world, $ruleset, $hostCell, 'red_inora', 3);
        $base = $this->ownedNonCapitalCell($killer);
        $this->setCell($base, 'plain', 'missile_base', $killer->id, 0);
        $base->update(['facility_experience' => 195]);
        $killer->update(['money' => 9_500]);
        $hostMoneyBefore = (int) $host->money;
        $monsterMeat = ResourceDefinition::query()->where('key', 'monster_meat')->firstOrFail();
        $wheat = ResourceDefinition::query()->where('key', 'wheat')->firstOrFail();
        NationResource::query()->where('nation_id', $host->id)->where('resource_definition_id', $wheat->id)
            ->update(['amount' => 999_800]);
        [$context] = $this->context($world, $ruleset, 2, 'reward-split', [$host->id, $killer->id]);

        $result = app(MonsterDamageService::class)->applyDamage(
            $monster,
            30,
            'monster_missile',
            $killer,
            $base,
            $hostCell,
            $context,
        );

        $this->assertSame('killed', $result->status);
        $this->assertSame(9_500, $result->killerMoney['before']);
        $this->assertSame(500, $result->killerMoney['requested']);
        $this->assertSame(500, $result->killerMoney['applied']);
        $this->assertSame(0, $result->killerMoney['overflow']);
        $this->assertSame(10_000, $result->killerMoney['after']);
        $this->assertSame(10_098, $result->killerMoney['capacity']);
        $this->assertSame(250_000, $result->hostMeat['requested']);
        $this->assertSame(10_099, $result->hostMeat['applied']);
        $this->assertSame(239_901, $result->hostMeat['overflow']);
        $this->assertSame(3, $result->actualDamage);
        $this->assertSame(4, $result->experiencePerDamage);
        $this->assertSame(12, $result->firingBaseExperienceRequested);
        $this->assertSame(5, $result->firingBaseExperienceApplied);
        $this->assertSame(10_000, (int) $killer->fresh()->money);
        $this->assertSame(10_099, NationResource::query()->where('nation_id', $host->id)
            ->where('resource_definition_id', $monsterMeat->id)->value('amount'));
        $this->assertSame($hostMoneyBefore + 478, (int) $host->fresh()->money);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'monster.reward_distributed',
            'visibility' => 'private',
        ]);

        $playerEvents = app(PlayerIslandEventService::class);
        $killerReward = collect($playerEvents->ownerPage($killer->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'monster.reward_distributed');
        $hostReward = collect($playerEvents->ownerPage($host->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'monster.reward_distributed');
        $hostOverflow = collect($playerEvents->ownerPage($host->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'resource.food_overflow_resolved');
        $spectatorReward = collect($playerEvents->ownerPage($spectator->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'monster.reward_distributed');
        $this->assertIsArray($killerReward);
        $this->assertSame('レッドいのらを撃破し、賞金500億円を受け取りました。', $killerReward['message']);
        $this->assertIsArray($hostReward);
        $this->assertSame('レッドいのらが倒され、怪獣肉10,099トンを受け取りました。', $hostReward['message']);
        $this->assertIsArray($hostOverflow);
        $this->assertSame(
            '食料上限を超えた怪獣肉239,901トンのうち239,000トンを売却して478億円を得て、901トンを破棄しました。',
            $hostOverflow['message'],
        );
        $this->assertNull($spectatorReward);
        $this->assertSame(200, $base->fresh()->facility_experience);
        $stat = NationMonsterKillStat::query()->sole();
        $this->assertSame($killer->id, $stat->nation_id);
        $this->assertSame($monster->monster_definition_id, $stat->monster_definition_id);
        $this->assertSame(1, $stat->kill_count);
        $this->assertSame(2, $stat->first_killed_turn);
        $this->assertSame(2, $stat->last_killed_turn);
        $this->assertSame(1, $stat->version);
        $cycleStat = NationMonsterCycleStat::query()->sole();
        $this->assertSame($killer->id, $cycleStat->nation_id);
        $this->assertSame(1, $cycleStat->cycle_start_turn);
        $this->assertSame(100, $cycleStat->cycle_end_turn);
        $this->assertSame(1, $cycleStat->kill_count);
        $this->assertSame($stat->id, $result->killStatId);
        $this->assertSame(0, $result->previousKillCount);
        $this->assertSame(1, $result->newKillCount);
        $metadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'monster.kill_stat_incremented')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($monster->id, $metadata['monster_instance_id']);
        $this->assertSame('red_inora', $metadata['monster_definition_key']);
        $this->assertSame($killer->id, $metadata['killer_nation_id']);
        $this->assertSame('撃破国', $metadata['killer_nation_name']);
        $this->assertSame($host->id, $metadata['host_nation_id']);
        $this->assertSame('所在国', $metadata['host_nation_name']);
        $this->assertSame(2, $metadata['target_turn']);
        $this->assertSame(0, $metadata['previous_kill_count']);
        $this->assertSame(1, $metadata['new_kill_count']);
        $this->assertSame(0, $metadata['previous_monster_cycle_kill_count']);
        $this->assertSame(1, $metadata['new_monster_cycle_kill_count']);
        $this->assertSame(500, $metadata['killer_money']['requested']);
        $this->assertSame(250_000, $metadata['host_meat_food']['requested']);
        $this->assertSame(239_901, $metadata['host_meat_food']['overflow']);
        $overflowMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'resource.food_overflow_resolved')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('monster_meat', $overflowMetadata['resource_key']);
        $this->assertSame(239_901, $overflowMetadata['requested_overflow_tons']);
        $this->assertSame(239_000, $overflowMetadata['sold_tons']);
        $this->assertSame(478, $overflowMetadata['revenue']);
        $this->assertSame(901, $overflowMetadata['discarded_tons']);
        $this->assertSame(1_000, $overflowMetadata['inventory_units_per_batch']);
        $this->assertSame(2, $overflowMetadata['money_units_per_batch']);
        $this->assertSame($base->id, $metadata['firing_base_id']);
        $this->assertSame('killed', $monster->fresh()->state);
        $this->assertFalse($monster->fresh()->occupancy()->exists());
        $this->assertContains($hostCell->map_chunk_id, $context->state->changedMapChunkIds());
        $publicEvents = collect($playerEvents->publicWorldPage($world, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertSame(1, $publicEvents->where('type', 'monster.killed')->count());
        $this->assertSame(0, $publicEvents->where('type', 'monster.reward_distributed')->count());
        $this->assertSame(0, $publicEvents->where('type', 'resource.food_overflow_resolved')->count());
        $publicJson = json_encode($publicEvents->all(), JSON_THROW_ON_ERROR);
        foreach (['killer_money', 'host_meat_food', '500億円', '10,099トン', '239,901', '478億円'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $publicJson);
        }

        $retry = app(MonsterDamageService::class)->applyDamage(
            $monster,
            3,
            'monster_missile',
            $killer,
            $base,
            $hostCell,
            $context,
        );
        $this->assertSame('already_resolved', $retry->status);
        $this->assertNull($retry->killStatId);
        $this->assertSame(1, NationMonsterKillStat::query()->count());
        $this->assertSame(1, $stat->fresh()->kill_count);
        $this->assertSame(1, $cycleStat->fresh()->kill_count);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.kill_stat_incremented')->count());
        $this->assertSame(10_000, (int) $killer->fresh()->money);
        $this->assertSame(10_099, NationResource::query()->where('nation_id', $host->id)
            ->where('resource_definition_id', $monsterMeat->id)->value('amount'));
    }

    public function test_all_eight_definitions_use_their_exact_wreckage_reward_values(): void
    {
        [$world, $host, $ruleset] = $this->worldAndNation('全種所在国');
        $killer = $this->createNation($world, '全種撃破国');
        $expectedValues = [
            'mecha_inora' => 0,
            'inora' => 400,
            'sanjira' => 500,
            'red_inora' => 1_000,
            'dark_inora' => 800,
            'inora_ghost' => 300,
            'whale' => 1_500,
            'king_inora' => 2_000,
        ];
        $cells = MapCell::query()->where('owner_nation_id', $host->id)
            ->whereNotIn('id', $host->capital()->select('map_cell_id'))
            ->with(['terrain', 'facility'])
            ->limit(count($expectedValues))
            ->get();
        $this->assertCount(count($expectedValues), $cells);

        foreach (array_values($expectedValues) as $index => $_value) {
            $this->setCell($cells[$index], 'wasteland', null, $host->id, 0);
        }
        foreach ($expectedValues as $index => $value) {
            $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
                ->where('key', $index)->firstOrFail();
            $cell = $cells->shift();
            $this->assertInstanceOf(MapCell::class, $cell);
            $monster = $this->createMonster($world, $ruleset, $cell, $index, $definition->base_hp);
            $targetTurn = match ($index) {
                'sanjira' => 100,
                'whale' => 101,
                default => 110 + count($expectedValues) - $cells->count(),
            };
            [$context] = $this->context(
                $world,
                $ruleset,
                $targetTurn,
                'all-rewards-'.$index,
                [$host->id, $killer->id],
            );

            $result = app(MonsterDamageService::class)->applyDamage(
                $monster,
                $definition->base_hp,
                'monster_missile',
                $killer,
                null,
                $cell,
                $context,
            );
            $stat = NationMonsterKillStat::query()
                ->where('nation_id', $killer->id)
                ->where('monster_definition_id', $definition->id)
                ->sole();
            $killerShare = intdiv($value, 2);
            $hostShare = $value - $killerShare;

            $this->assertSame('killed', $result->status, $index);
            $this->assertSame(1, $stat->kill_count, $index);
            $this->assertSame($targetTurn, $stat->first_killed_turn, $index);
            $this->assertSame($targetTurn, $stat->last_killed_turn, $index);
            $this->assertSame($killerShare, $result->killerMoney['requested'], $index);
            $this->assertSame($hostShare * 500, $result->hostMeat['requested'], $index);
        }

        $this->assertSame(8, NationMonsterKillStat::query()->count());
        $this->assertSame(8, (int) NationMonsterKillStat::query()->sum('kill_count'));
        $this->assertSame(1, NationMonsterCycleStat::query()
            ->where('cycle_start_turn', 1)->where('cycle_end_turn', 100)->value('kill_count'));
        $this->assertSame(7, NationMonsterCycleStat::query()
            ->where('cycle_start_turn', 101)->where('cycle_end_turn', 200)->value('kill_count'));
    }

    public function test_same_nation_receives_both_shares_while_neutral_host_share_is_unclaimed(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('同一受取国');
        $ownedCell = $this->ownedNonCapitalCell($nation);
        $this->setCell($ownedCell, 'wasteland', null, $nation->id, 0);
        $sameNationMonster = $this->createMonster($world, $ruleset, $ownedCell, 'inora', 1);
        [$sameContext] = $this->context($world, $ruleset, 2, 'same-nation-reward', [$nation->id]);

        $sameResult = app(MonsterDamageService::class)->applyDamage(
            $sameNationMonster,
            1,
            'monster_missile',
            $nation,
            null,
            $ownedCell,
            $sameContext,
        );

        $this->assertSame(200, $sameResult->killerMoney['requested']);
        $this->assertSame(100_000, $sameResult->hostMeat['requested']);
        $this->assertSame(1, $sameResult->newKillCount);
        $sameNationReward = collect(app(PlayerIslandEventService::class)->ownerPage($nation->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'monster.reward_distributed');
        $this->assertIsArray($sameNationReward);
        $this->assertSame(
            'いのらを撃破し、賞金200億円と怪獣肉100,000トンを受け取りました。',
            $sameNationReward['message'],
        );

        $neutralCell = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')
            ->with(['terrain', 'facility'])
            ->firstOrFail();
        $this->setCell($neutralCell, 'wasteland', null, null, 0);
        $neutralMonster = $this->createMonster($world, $ruleset, $neutralCell, 'inora', 1);
        [$neutralContext] = $this->context($world, $ruleset, 3, 'neutral-host-reward', [$nation->id]);

        $neutralResult = app(MonsterDamageService::class)->applyDamage(
            $neutralMonster,
            1,
            'monster_missile',
            $nation,
            null,
            $neutralCell,
            $neutralContext,
        );

        $this->assertSame(200, $neutralResult->killerMoney['requested']);
        $this->assertNull($neutralResult->hostMeat);
        $this->assertSame(1, $neutralResult->previousKillCount);
        $this->assertSame(2, $neutralResult->newKillCount);
        $stat = NationMonsterKillStat::query()->sole();
        $this->assertSame(2, $stat->kill_count);
        $this->assertSame(2, $stat->first_killed_turn);
        $this->assertSame(3, $stat->last_killed_turn);
        $this->assertSame(2, $stat->version);
        $neutralMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'monster.reward_distributed')
            ->whereRaw("metadata->>'monster_instance_id' = ?", [(string) $neutralMonster->id])
            ->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($neutralMetadata['host_nation_id']);
        $this->assertSame(200, $neutralMetadata['unclaimed_host_value_money']);
    }

    public function test_explicit_hostless_full_killer_policy_uses_exact_turn_ruleset_and_respects_money_capacity(): void
    {
        [$world, $killer, $ruleset, $space] = $this->worldAndNation('あおい撃破国');
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'aoi_inora')->firstOrFail();
        $neutral = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')->with(['terrain', 'facility'])->firstOrFail();
        $this->setCell($neutral, 'wasteland', null, null, 0);
        $monster = $this->createMonster($world, $ruleset, $neutral, 'aoi_inora', 2);
        $killer->update(['money' => 9_500]);
        [$context] = $this->context($world, $ruleset, 2, 'aoi-hostless-full', [$killer->id]);

        $result = app(MonsterDamageService::class)->applyDamage(
            $monster, 2, 'monster_missile', $killer, null, $neutral, $context,
        );

        $this->assertSame('killed', $result->status);
        $this->assertSame(1_200, $result->killerMoney['requested']);
        $this->assertSame(598, $result->killerMoney['applied']);
        $this->assertSame(602, $result->killerMoney['overflow']);
        $this->assertSame(10_098, $killer->fresh()->money);
        $this->assertNull($result->hostMeat);
        $this->assertSame(1, $result->newKillCount);
        $metadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'monster.reward_distributed')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('hostless_full_killer_money', $metadata['monster_reward_policy']);
        $this->assertSame(1_200, $metadata['killer_requested_share_money']);
        $this->assertSame(0, $metadata['host_requested_share_money']);
        $this->assertSame(0, $metadata['unclaimed_host_value_money']);
    }

    public function test_odd_wreckage_value_remainder_goes_to_current_host(): void
    {
        [$world, $host, $ruleset] = $this->worldAndNation('奇数所在国');
        $killer = $this->createNation($world, '奇数撃破国');
        $cell = $this->ownedNonCapitalCell($host);
        $this->setCell($cell, 'wasteland', null, $host->id, 0);
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'inora')->firstOrFail();
        $definition->update(['wreckage_value_money' => 401]);
        $monster = $this->createMonster($world, $ruleset, $cell, 'inora', 1);
        [$context] = $this->context($world, $ruleset, 2, 'odd-remainder', [$host->id, $killer->id]);

        $result = app(MonsterDamageService::class)->applyDamage(
            $monster, 1, 'monster_missile', $killer, null, $cell, $context,
        );

        $this->assertSame(200, $result->killerMoney['requested']);
        $this->assertSame(100_500, $result->hostMeat['requested']);
        $metadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'monster.reward_distributed')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(401, $metadata['wreckage_value_money']);
        $this->assertSame(200, $metadata['killer_money']['requested']);
        $this->assertSame(100_500, $metadata['host_meat_food']['requested']);
    }

    public function test_unattributed_death_has_no_rewards_or_kill_stat(): void
    {
        [$world, $host, $ruleset] = $this->worldAndNation('無帰属国');
        $cell = $this->ownedNonCapitalCell($host);
        $this->setCell($cell, 'wasteland', null, $host->id, 0);
        $monster = $this->createMonster($world, $ruleset, $cell, 'inora', 1);
        $beforeMoney = (int) $host->money;
        [$context] = $this->context($world, $ruleset, 2, 'unattributed', [$host->id]);

        $result = app(MonsterDamageService::class)->applyDamage(
            $monster,
            1,
            'terrain_destruction_missile',
            null,
            null,
            $cell,
            $context,
        );

        $this->assertSame('killed_unattributed', $result->status);
        $this->assertNull($result->killerMoney);
        $this->assertNull($result->hostMeat);
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $this->assertSame(0, NationMonsterCycleStat::query()->count());
        $this->assertSame($beforeMoney, (int) $host->fresh()->money);
    }

    public function test_final_blow_resolves_host_from_the_locked_current_cell_not_a_stale_caller_snapshot(): void
    {
        [$world, $formerHost, $ruleset] = $this->worldAndNation('旧所在国');
        $currentHost = $this->createNation($world, '現在所在国');
        $killer = $this->createNation($world, '現在撃破国');
        $cell = $this->ownedNonCapitalCell($formerHost);
        $this->setCell($cell, 'wasteland', null, $formerHost->id, 0);
        $staleHostCell = $cell->fresh();
        $monster = $this->createMonster($world, $ruleset, $cell, 'inora', 1);
        MapCell::query()->whereKey($cell->id)->update(['owner_nation_id' => $currentHost->id]);
        [$context] = $this->context(
            $world,
            $ruleset,
            2,
            'locked-current-host',
            [$formerHost->id, $currentHost->id, $killer->id],
        );

        app(MonsterDamageService::class)->applyDamage(
            $monster,
            1,
            'monster_missile',
            $killer,
            null,
            $staleHostCell,
            $context,
        );

        $monsterMeat = ResourceDefinition::query()->where('key', 'monster_meat')->firstOrFail();
        $metadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'monster.reward_distributed')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($currentHost->id, $metadata['host_nation_id']);
        $this->assertSame(100_000, NationResource::query()->where('nation_id', $currentHost->id)
            ->where('resource_definition_id', $monsterMeat->id)->value('amount'));
        $this->assertSame(0, NationResource::query()->where('nation_id', $formerHost->id)
            ->where('resource_definition_id', $monsterMeat->id)->value('amount'));
    }

    public function test_damage_and_rewards_roll_back_atomically(): void
    {
        [$world, $host, $ruleset] = $this->worldAndNation('rollback所在国');
        $killer = $this->createNation($world, 'rollback撃破国');
        $cell = $this->ownedNonCapitalCell($host);
        $this->setCell($cell, 'wasteland', null, $host->id, 0);
        $monster = $this->createMonster($world, $ruleset, $cell, 'inora', 1);
        $beforeMoney = (int) $killer->money;
        [$context] = $this->context($world, $ruleset, 2, 'reward-rollback', [$host->id, $killer->id]);

        try {
            DB::transaction(function () use ($monster, $killer, $cell, $context): void {
                app(MonsterDamageService::class)->applyDamage(
                    $monster, 1, 'monster_missile', $killer, null, $cell, $context,
                );
                throw new RuntimeException('rollback probe');
            });
            $this->fail('Expected rollback probe.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback probe', $exception->getMessage());
        }

        $this->assertSame('alive', $monster->fresh()->state);
        $this->assertSame(1, $monster->fresh()->current_hp);
        $this->assertTrue($monster->fresh()->occupancy()->exists());
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $this->assertSame(0, NationMonsterCycleStat::query()->count());
        $this->assertSame($beforeMoney, (int) $killer->fresh()->money);
    }

    public function test_database_rejects_capital_occupancy_and_invalid_kill_stat_mutation(): void
    {
        [$world, $nation, $ruleset] = $this->worldAndNation('DB制約国');
        $capital = $nation->capital()->firstOrFail()->cell()->firstOrFail();
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 2,
            'version' => 1,
        ]);

        try {
            DB::transaction(static fn () => MonsterInstance::query()->whereKey($monster->id)->update([
                'state' => 'removed',
                'current_hp' => -1,
                'removal_reason' => 'invalid_probe',
                'removed_at' => now(),
            ]));
            $this->fail('Expected negative removed HP rejection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('monster_instances_state_check', $exception->getMessage());
        }

        try {
            DB::transaction(static function () use ($monster, $capital): void {
                MonsterOccupancy::query()->create([
                    'monster_instance_id' => $monster->id,
                    'map_cell_id' => $capital->id,
                ]);
            });
            $this->fail('Expected capital occupancy rejection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Capital cells cannot contain monster occupancy', $exception->getMessage());
        }

        $cell = $this->ownedNonCapitalCell($nation);
        $this->setCell($cell, 'wasteland', null, $nation->id, 0);
        MonsterOccupancy::query()->create(['monster_instance_id' => $monster->id, 'map_cell_id' => $cell->id]);
        [$context] = $this->context($world, $ruleset, 2, 'immutable-kill', [$nation->id]);
        app(MonsterDamageService::class)->applyDamage(
            $monster, 1, 'monster_missile', $nation, null, $cell, $context,
        );
        $stat = NationMonsterKillStat::query()->sole();

        try {
            DB::transaction(static fn () => $stat->update(['last_killed_turn' => 99]));
            $this->fail('Expected non-atomic kill stat update rejection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('monster kill stat updates must be one atomic increment', $exception->getMessage());
        }
        $this->assertSame(1, $stat->fresh()->kill_count);
        $this->assertSame(2, $stat->fresh()->last_killed_turn);

        try {
            DB::transaction(static fn () => $stat->delete());
            $this->fail('Expected permanent kill stat deletion rejection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('monster kill stats are permanent while their World exists', $exception->getMessage());
        }
        $this->assertNotNull($stat->fresh());
    }

    public function test_database_rejects_cross_world_kill_stats(): void
    {
        [$world, , $ruleset] = $this->worldAndNation('統計定義国');
        $otherWorld = World::query()->create([
            'key' => 'kill-stat-other-world',
            'name' => '統計別世界',
            'ruleset_version_id' => $ruleset->id,
            'current_turn' => 1,
        ]);
        $otherNation = Nation::query()->create([
            'world_id' => $otherWorld->id,
            'nation_number' => 1,
            'name' => '統計別世界国',
            'owner_name' => '統計別世界国主',
            'profile_comment' => '',
            'money' => 100,
            'state' => 'active',
            'idle_counter' => 0,
        ]);
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'inora')->firstOrFail();

        try {
            DB::transaction(static fn () => NationMonsterKillStat::query()->create([
                'world_id' => $world->id,
                'nation_id' => $otherNation->id,
                'monster_definition_id' => $definition->id,
                'kill_count' => 1,
                'first_killed_turn' => 2,
                'last_killed_turn' => 2,
                'version' => 1,
            ]));
            $this->fail('Expected cross-World kill stat rejection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('monster kill stat references inconsistent World state', $exception->getMessage());
        }

        $this->assertSame(0, NationMonsterKillStat::query()->count());
    }

    /** @return array{World, Nation, RulesetVersion, MapSpace} */
    private function worldAndNation(string $name): array
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, $name);

        return [$world, $nation, $world->rulesetVersion()->firstOrFail(), $this->surfaceMapSpace($world)];
    }

    private function createNation(World $world, string $name): Nation
    {
        return app(NationCreationService::class)->create(User::factory()->create(), $world, $name, $name.'主');
    }

    private function prepareSettlement(Nation $nation, int $population): MapCell
    {
        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        $settlement = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', ['village', 'town', 'city']))
            ->with(['terrain', 'facility'])
            ->firstOrFail();
        $settlement->update(['population' => $population]);

        return $settlement;
    }

    private function guaranteeNaturalSpawn(RulesetVersion $ruleset): RulesetVersion
    {
        $settings = $ruleset->settings;
        $settings['monster_system']['natural_spawn']['probability_per_land_cell'] = [
            'numerator' => 10_000,
            'denominator' => 10_000,
        ];
        $ruleset->settings = $settings;

        return $ruleset;
    }

    /** @return array{TurnContext, TurnRun} */
    private function context(
        World $world,
        RulesetVersion $ruleset,
        int $targetTurn,
        string $seedLabel,
        array $nationIds,
    ): array {
        $seed = hash('sha256', $seedLabel);
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
        $state->setStableNationIds(array_values($nationIds));
        $state->setDevelopmentNationIds(array_values($nationIds));

        return [
            new TurnContext($world, $run, $ruleset, $targetTurn, $seed, new TurnRandomStreamFactory($seed), $state),
            $run,
        ];
    }

    private function createMonster(
        World $world,
        RulesetVersion $ruleset,
        MapCell $cell,
        string $key,
        int $hp,
    ): MonsterInstance {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', $key)->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $hp,
            'spawned_max_hp' => $hp,
            'state' => 'alive',
            'spawned_target_turn' => 2,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);

        return $monster->fresh(['definition', 'occupancy']);
    }

    private function ownedNonCapitalCell(Nation $nation): MapCell
    {
        return MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $nation->capital()->select('map_cell_id'))
            ->with(['terrain', 'facility'])
            ->firstOrFail();
    }

    private function safeInteriorCell(MapSpace $space, World $world): MapCell
    {
        $capitalCoordinates = $world->nations()->with('capital')->get()
            ->pluck('capital')->filter()->map(static fn ($capital): GridCoordinate => new GridCoordinate($capital->x, $capital->y));
        $candidates = MapCell::query()->where('map_space_id', $space->id)
            ->whereBetween('x', [$space->min_x + 4, $space->max_x - 4])
            ->whereBetween('y', [$space->min_y + 4, $space->max_y - 4])
            ->with(['terrain', 'facility'])
            ->orderBy('id')->get();
        foreach ($candidates as $cell) {
            $coordinate = new GridCoordinate($cell->x, $cell->y);
            if ($capitalCoordinates->every(static fn (GridCoordinate $capital): bool => $coordinate->distanceTo($capital) > 4)) {
                return $cell;
            }
        }

        throw new RuntimeException('No interior monster test cell was available.');
    }

    private function twoMoveSeedThatDoesNotReturnToOrigin(
        MonsterInstance $monster,
        GridCoordinate $origin,
    ): string {
        foreach (range(0, 99) as $candidate) {
            $label = "dark-two-moves-{$candidate}";
            $seed = hash('sha256', $label);
            $stream = (new TurnRandomStreamFactory($seed))->stream(
                TurnRandomStreamFactory::monsterMovement($monster->id, 1),
            );
            $firstDestination = $origin->neighbor($stream->integer(0, 5));
            $secondDestination = $firstDestination->neighbor($stream->integer(0, 5));
            if ($secondDestination->x !== $origin->x || $secondDestination->y !== $origin->y) {
                return $label;
            }
        }

        throw new RuntimeException('No deterministic two-move monster seed was available.');
    }

    /** @param non-empty-list<int> $directions */
    private function movementSeedForDirections(MonsterInstance $monster, array $directions): string
    {
        foreach (range(0, 99_999) as $candidate) {
            $label = "protected-destination-{$candidate}";
            $stream = (new TurnRandomStreamFactory(hash('sha256', $label)))->stream(
                TurnRandomStreamFactory::monsterMovement($monster->id, 1),
            );
            $matches = true;
            foreach ($directions as $direction) {
                if ($stream->integer(0, 5) !== $direction) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $label;
            }
        }

        throw new RuntimeException('No deterministic protected-destination monster seed was available.');
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
        $cell->version++;
        $cell->save();
    }

    private function cellAt(MapSpace $space, int $x, int $y): MapCell
    {
        return MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $x)->where('y', $y)->with(['terrain', 'facility'])->firstOrFail();
    }
}
