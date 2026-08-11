<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\MonumentDefinition;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class DomesticCommandExecutionTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_domestic_commands_revalidate_mutate_queue_and_honor_turn_consumption(): void
    {
        $world = $this->lightweightWorld();
        [$firstUser, $first] = $this->createNation($world, '開発一号');
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $first->update(['money' => 2_000]);

        $forests = MapCell::query()->where('owner_nation_id', $first->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->orderBy('id')->get();
        $this->assertCount(3, $forests);
        $landLevelTarget = $forests[0];
        $factoryTarget = $forests[1];
        $landClearTarget = $forests[2];
        $this->changeTerrain($factoryTarget, 'plain');
        $farmTarget = MapCell::query()->where('owner_nation_id', $first->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->whereKeyNot($factoryTarget->id)
            ->firstOrFail();
        $mineTarget = MapCell::query()->where('owner_nation_id', $first->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'mountain'))
            ->firstOrFail();
        $reclaimTarget = $this->reclaimTarget($first, $space);
        $excavateTarget = MapCell::query()->where('owner_nation_id', $first->id)
            ->whereNull('facility_definition_id')
            ->whereNotIn('id', $forests->modelKeys())
            ->whereKeyNot($farmTarget->id)
            ->firstOrFail();
        $this->changeTerrain($excavateTarget, 'mountain');

        $this->queue($firstUser, $first, $space, 'land_level', $landLevelTarget, 1, 1);
        $farmItem = $this->queue($firstUser, $first, $space, 'build_farm', $farmTarget, 2, 2);
        $this->queue($firstUser, $first, $space, 'reclaim', $reclaimTarget, 1, 3);
        $this->queue($firstUser, $first, $space, 'build_factory', $factoryTarget, 1, 4);
        $this->queue($firstUser, $first, $space, 'build_mine', $mineTarget, 1, 5);
        $this->queue($firstUser, $first, $space, 'land_clear', $landClearTarget, 1, 6);
        $this->queue($firstUser, $first, $space, 'excavate', $excavateTarget, 1, 7);

        $seed = $this->seedWithFirstDraw(TurnRandomStreamFactory::LAND_CLEAR_BURIED_TREASURE, 1_000, 10);
        $context = $this->context($world, [$first->id], $seed);
        $executor = app(DomesticCommandExecutor::class);

        $firstPass = $executor->execute($context);
        $this->assertSame(2, $firstPass['successes']);
        $this->assertSame(1, $firstPass['quantity_decrements']);
        $this->assertSame('plain', $landLevelTarget->fresh()->terrain()->value('key'));
        $this->assertSame(0, $landLevelTarget->fresh()->population);
        $this->assertSame('farm', $farmTarget->fresh()->facility()->value('key'));
        $this->assertSame('mountain', $excavateTarget->fresh()->terrain()->value('key'));
        $this->assertSame('queued', $farmItem->fresh()->status);
        $this->assertSame(1, $farmItem->fresh()->quantity);
        $this->assertSame(1, $farmItem->fresh()->queue_position);
        $this->assertSame(1, NationCommandQueueItem::query()->where('nation_command_queue_id', $first->commandQueue->id)
            ->where('queue_position', 2)->whereHas('definition', fn ($query) => $query->where('key', 'reclaim'))->count());

        $secondPass = $executor->execute($context);
        $this->assertSame(0, $secondPass['failures']);
        $this->assertSame(1, $secondPass['successes']);
        $this->assertSame('completed', $farmItem->fresh()->status);
        $this->assertSame(12, $farmTarget->fresh()->facility_scale);

        $executor->execute($context);
        $this->assertSame('wasteland', $reclaimTarget->fresh()->terrain()->value('key'));
        $this->assertSame($first->id, $reclaimTarget->fresh()->owner_nation_id);

        $executor->execute($context);
        $this->assertSame('factory', $factoryTarget->fresh()->facility()->value('key'));
        $executor->execute($context);
        $this->assertSame('mine', $mineTarget->fresh()->facility()->value('key'));
        $executor->execute($context);
        $this->assertSame('plain', $landClearTarget->fresh()->terrain()->value('key'));
        $executor->execute($context);
        $this->assertSame('wasteland', $excavateTarget->fresh()->terrain()->value('key'));
        $automatic = $executor->execute($context);

        $this->assertSame(1, $automatic['automatic_finance']);
        $this->assertSame(1_115, $first->fresh()->money);
        $this->assertSame(8, DB::table('audit_events')->where('event_type', 'command.success')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.invalid')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.quantity_decremented')->count());
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'facility.constructed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'facility.expanded')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.automatic_finance')->count());
        $this->assertSame(4, DB::table('audit_events')->where('event_type', 'command.terrain_changed_public')
            ->where('visibility', 'public')->count());
        $this->assertSame(4, DB::table('audit_events')->where('event_type', 'command.facility_built_public')
            ->where('visibility', 'public')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'like', '%earthquake%')->count());

        $publicMessages = collect(app(PlayerIslandEventService::class)->publicNationPage($first, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        foreach ([
            sprintf('開発一号(%d,%d)で地ならしが行われました。', $landLevelTarget->x, $landLevelTarget->y),
            sprintf('開発一号(%d,%d)で埋め立てが行われました。', $reclaimTarget->x, $reclaimTarget->y),
            sprintf('開発一号(%d,%d)で整地が行われました。', $landClearTarget->x, $landClearTarget->y),
            sprintf('開発一号(%d,%d)で掘削が行われました。', $excavateTarget->x, $excavateTarget->y),
            sprintf('開発一号(%d,%d)で農場が建設されました。', $farmTarget->x, $farmTarget->y),
            sprintf('開発一号(%d,%d)で工場が建設されました。', $factoryTarget->x, $factoryTarget->y),
            sprintf('開発一号(%d,%d)で採掘場が建設されました。', $mineTarget->x, $mineTarget->y),
        ] as $message) {
            $this->assertContains($message, $publicMessages->all());
        }

        $landLevelEvent = $this->eventMetadata('command.success', function ($query): void {
            $query->whereRaw("metadata->>'command_key' = ?", ['land_level']);
        });
        $this->assertFalse($landLevelEvent['consumes_turn']);
        $this->assertArrayNotHasKey('earthquake', $landLevelEvent);
    }

    public function test_turn_execution_repairs_a_split_queue_without_stopping_the_world(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->createNation($world, 'ターン復旧国');
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $plain = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $first = $this->queue($user, $nation, $space, 'land_clear', $forest, 1, 1);
        $second = $this->queue($user, $nation, $space, 'plant_forest', $plain, 1, 2);
        $third = $this->queue($user, $nation, $space, 'build_farm', $plain, 1, 3);
        $second->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
        $first->update(['queue_position' => 1001]);
        $third->update(['queue_position' => 2]);

        $seed = $this->seedWithFirstDraw(TurnRandomStreamFactory::LAND_CLEAR_BURIED_TREASURE, 1_000, 10);
        $result = app(DomesticCommandExecutor::class)->execute($this->context($world, [$nation->id], $seed));

        $this->assertSame(1, $result['successes']);
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame('queued', $third->fresh()->status);
        $remaining = NationCommandQueueItem::query()->where('nation_command_queue_id', $nation->commandQueue->id)
            ->where('status', 'queued')->with('definition')->orderBy('queue_position')->get();
        $this->assertSame([$third->id], $remaining->pluck('id')->all());
        $this->assertSame(['build_farm'], $remaining->pluck('definition.key')->all());
        $this->assertSame([1], $remaining->pluck('queue_position')->all());
    }

    public function test_queue_reads_and_turn_execution_share_the_legacy_recovered_head(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->createNation($world, '表示実行一致国');
        $nation->update(['money' => 1_000]);
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $reclaimTarget = $this->reclaimTarget($nation, $space);
        $plain = $this->ownedTerrain($nation, 'plain');
        $first = $this->queue($user, $nation, $space, 'reclaim', $reclaimTarget, 1, 1);
        $cancelled = $this->queue($user, $nation, $space, 'plant_forest', $plain, 1, 2);
        $third = $this->queue($user, $nation, $space, 'build_farm', $plain, 1, 3);
        $cancelled->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
        $first->update(['queue_position' => 1001]);
        $third->update(['queue_position' => 2]);

        $base = "/api/v1/nations/{$nation->id}/map-spaces/{$space->id}";
        $this->actingAs($user)->getJson("{$base}/command-queue")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $first->id)
            ->assertJsonPath('data.items.0.command_key', 'reclaim')
            ->assertJsonPath('data.items.0.queue_position', 1)
            ->assertJsonPath('data.items.1.id', $third->id)
            ->assertJsonPath('data.items.1.queue_position', 2)
            ->assertJsonPath('data.plan.0.id', $first->id)
            ->assertJsonPath('data.plan.0.kind', 'explicit');
        $landClear = collect($this->getJson(
            "{$base}/command-definitions?target_x={$reclaimTarget->x}&target_y={$reclaimTarget->y}&position=2",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'land_clear');
        $this->assertSame('executable_after_queue', $landClear['execution_preview_status']);
        $this->assertSame(1001, $first->fresh()->queue_position);
        $this->assertSame(2, $third->fresh()->queue_position);

        $result = app(DomesticCommandExecutor::class)->execute(
            $this->context($world, [$nation->id], str_repeat('e', 64)),
        );

        $this->assertSame(1, $result['successes']);
        $this->assertSame('completed', $first->fresh()->status);
        $remaining = $third->fresh('definition');
        $this->assertSame('queued', $remaining->status);
        $this->assertSame('build_farm', $remaining->definition->key);
        $this->assertSame(1, $remaining->queue_position);
    }

    public function test_terrain_commands_cannot_remove_the_nation_capital(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, 'Capital guard');
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $moneyBefore = $nation->money;
        $populationBefore = $capital->population;
        $items = [];
        foreach (['land_clear', 'land_level', 'excavate'] as $position => $commandKey) {
            $items[] = $this->queue($user, $nation, $space, $commandKey, $capital, 1, $position + 1);
        }

        $result = app(DomesticCommandExecutor::class)->execute(
            $this->context($world, [$nation->id], str_repeat('9', 64)),
        );

        $this->assertSame(3, $result['failures']);
        $this->assertSame(3, $result['removed']);
        $this->assertSame(1, $result['automatic_finance']);
        foreach ($items as $item) {
            $this->assertSame('failed', $item->fresh()->status);
            $this->assertSame('capital_protected', $item->fresh()->failure_code);
        }
        $this->assertSame($moneyBefore + 10, $nation->fresh()->money);
        $this->assertSame($populationBefore, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));
        $this->assertSame('plain', $capital->fresh()->terrain()->value('key'));
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'command.failed')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'terrain.changed')->count());
    }

    public function test_terrain_commands_reject_a_monster_occupied_target_without_mutation_or_cost(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '怪獣占有開発国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $nation->capital()->select('map_cell_id'))
            ->firstOrFail();
        $this->changeTerrain($target, 'wasteland');
        $target = $target->fresh(['terrain', 'facility']);
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        $occupancy = MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $target->id,
        ]);
        $moneyBefore = (int) $nation->money;
        $items = [];
        foreach (['land_clear', 'land_level', 'excavate'] as $position => $commandKey) {
            $items[] = $this->queue($user, $nation, $space, $commandKey, $target, 1, $position + 1);
        }

        $result = app(DomesticCommandExecutor::class)->execute(
            $this->context($world, [$nation->id], str_repeat('7', 64)),
        );

        $this->assertSame(3, $result['failures']);
        $this->assertSame(3, $result['removed']);
        $this->assertSame(0, $result['successes']);
        foreach ($items as $item) {
            $this->assertSame('failed', $item->fresh()->status);
            $this->assertSame('occupied_by_monster', $item->fresh()->failure_code);
        }
        $this->assertSame($moneyBefore + 10, (int) $nation->fresh()->money);
        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        $this->assertSame('alive', $monster->fresh()->state);
        $this->assertSame($target->id, $occupancy->fresh()->map_cell_id);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'terrain.changed')->count());
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'command.failed')->count());
    }

    public function test_water_commands_reject_targets_owned_by_another_nation(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, 'Water command owner');
        [, $rival] = $this->createNation($world, 'Water command rival');
        $target = $this->reclaimTarget($nation, $space);
        $target->update(['owner_nation_id' => $rival->id]);
        $moneyBefore = $nation->money;
        $reclaim = $this->queue($user, $nation, $space, 'reclaim', $target, 1, 1);
        $excavate = $this->queue($user, $nation, $space, 'excavate', $target, 1, 2);

        $result = app(DomesticCommandExecutor::class)->execute(
            $this->context($world, [$nation->id], str_repeat('8', 64)),
        );

        $this->assertSame(2, $result['failures']);
        $this->assertSame(2, $result['removed']);
        $this->assertSame(1, $result['automatic_finance']);
        $this->assertSame('foreign_owned', $reclaim->fresh()->failure_code);
        $this->assertSame('foreign_owned', $excavate->fresh()->failure_code);
        $this->assertSame($moneyBefore + 10, $nation->fresh()->money);
        $this->assertSame($rival->id, $target->fresh()->owner_nation_id);
        $this->assertSame('shallow', $target->fresh()->terrain()->value('key'));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'terrain.changed')->count());
    }

    public function test_reclaim_applies_sea_and_shallow_steps_in_queue_order_and_charges_each_success(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '埋め立て順序国');
        $nation->update(['money' => 1_000]);
        $target = $this->remoteWaterTarget($space);
        $neighbors = $this->neighborCells($target);
        $this->setCellState($neighbors[0], 'wasteland', $nation->id);
        foreach (array_slice($neighbors, 1) as $neighbor) {
            $this->setCellState($neighbor, 'sea', null);
        }
        $this->setCellState($target, 'sea', null);

        $first = $this->queue($user, $nation, $space, 'reclaim', $target, 1, 1);
        $second = $this->queue($user, $nation, $space, 'reclaim', $target, 1, 2);
        $context = $this->context($world, [$nation->id], str_repeat('d', 64));
        $executor = app(DomesticCommandExecutor::class);

        $firstResult = $executor->execute($context);
        $this->assertSame(1, $firstResult['successes']);
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
        $this->assertSame('shallow', $target->fresh()->terrain()->value('key'));
        $this->assertNull($target->fresh()->owner_nation_id);
        $this->assertSame(850, $nation->fresh()->money);

        $secondResult = $executor->execute($context);
        $this->assertSame(1, $secondResult['successes']);
        $this->assertSame('completed', $second->fresh()->status);
        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        $this->assertSame($nation->id, $target->fresh()->owner_nation_id);
        $this->assertSame(700, $nation->fresh()->money);
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'command.success')
            ->whereRaw("metadata->>'command_key' = ?", ['reclaim'])->count());
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'terrain.changed')
            ->where('subject_id', $target->id)->count());
    }

    public function test_excavated_owned_shallow_can_be_reclaimed_to_owned_wasteland_on_the_next_turn(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '掘削復旧国');
        $nation->update(['money' => 1_000]);
        $target = $this->remoteWaterTarget($space);
        $neighbors = $this->neighborCells($target);
        $this->setCellState($target, 'plain', $nation->id);
        $this->setCellState($neighbors[0], 'wasteland', $nation->id);
        foreach (array_slice($neighbors, 1) as $neighbor) {
            $this->setCellState($neighbor, 'sea', null);
        }
        $target = $target->fresh(['terrain', 'facility']);
        $initialCellVersion = $target->version;
        $initialChunkVersion = (int) DB::table('map_chunks')->where('id', $target->map_chunk_id)->value('version');

        $excavate = $this->queue($user, $nation, $space, 'excavate', $target, 1, 1);
        $reclaim = $this->queue($user, $nation, $space, 'reclaim', $target, 1, 2);
        $executor = app(DomesticCommandExecutor::class);
        $engine = app(CompleteTurnEngine::class);

        $excavateContext = $this->context($world, [$nation->id], str_repeat('1', 64), null, 2);
        $excavateResult = $executor->execute($excavateContext);
        $engine->execute('aggregate_nations', $excavateContext);

        $this->assertSame(1, $excavateResult['successes']);
        $this->assertSame('completed', $excavate->fresh()->status);
        $this->assertSame('queued', $reclaim->fresh()->status);
        $this->assertSame('shallow', $target->fresh()->terrain()->value('key'));
        $this->assertSame($nation->id, $target->fresh()->owner_nation_id);
        $this->assertSame(800, $nation->fresh()->money);
        $this->assertSame($initialCellVersion + 1, $target->fresh()->version);
        $this->assertSame(
            $initialChunkVersion + 1,
            (int) DB::table('map_chunks')->where('id', $target->map_chunk_id)->value('version'),
        );
        $this->assertContains($target->map_chunk_id, $excavateContext->state->changedMapChunkIds());

        $reclaimContext = $this->context($world, [$nation->id], str_repeat('2', 64), null, 3);
        $reclaimResult = $executor->execute($reclaimContext);
        $engine->execute('aggregate_nations', $reclaimContext);

        $this->assertSame(1, $reclaimResult['successes']);
        $this->assertSame('completed', $reclaim->fresh()->status);
        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        $this->assertSame($nation->id, $target->fresh()->owner_nation_id);
        $this->assertSame(650, $nation->fresh()->money);
        $this->assertSame($initialCellVersion + 2, $target->fresh()->version);
        $this->assertSame(
            $initialChunkVersion + 2,
            (int) DB::table('map_chunks')->where('id', $target->map_chunk_id)->value('version'),
        );
        $this->assertContains($target->map_chunk_id, $reclaimContext->state->changedMapChunkIds());

        $successes = DB::table('audit_events')->where('event_type', 'command.success')
            ->whereIn('subject_id', [$excavate->id, $reclaim->id])->orderBy('id')->get()
            ->map(static fn ($event): array => json_decode(
                (string) $event->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ));
        $this->assertSame(['excavate', 'reclaim'], $successes->pluck('command_key')->all());
        $this->assertSame([200, 150], $successes->pluck('cost_money')->all());
        $page = app(PlayerIslandEventService::class)->ownerPage($nation->fresh(), 1, 3);
        $messages = collect($page['groups'])->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '掘削') && str_contains($message, '浅瀬'),
        ));
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '埋め立て') && str_contains($message, '荒地'),
        ));
    }

    public function test_shallow_reclaim_spreads_to_the_six_direction_water_neighbors_and_marks_their_chunks(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '埋め立て波及国');
        $nation->update(['money' => 1_000]);
        $target = $this->remoteWaterTarget($space);
        $neighbors = $this->neighborCells($target);
        foreach (array_slice($neighbors, 0, 2) as $neighbor) {
            $this->setCellState($neighbor, 'sea', null);
        }
        $this->setCellState($neighbors[2], 'shallow', null);
        $unchangedShallowVersion = $neighbors[2]->fresh()->version;
        foreach (array_slice($neighbors, 3) as $index => $neighbor) {
            $this->setCellState($neighbor, 'wasteland', $index === 0 ? $nation->id : null);
        }
        $this->setCellState($target, 'shallow', null);
        $this->queue($user, $nation, $space, 'reclaim', $target);
        $context = $this->context($world, [$nation->id], str_repeat('e', 64));

        app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        $this->assertSame($nation->id, $target->fresh()->owner_nation_id);
        foreach (array_slice($neighbors, 0, 3) as $neighbor) {
            $this->assertSame('shallow', $neighbor->fresh()->terrain()->value('key'));
            $this->assertNull($neighbor->fresh()->owner_nation_id);
        }
        $this->assertSame($unchangedShallowVersion, $neighbors[2]->fresh()->version);
        foreach (array_slice($neighbors, 3) as $neighbor) {
            $this->assertSame('wasteland', $neighbor->fresh()->terrain()->value('key'));
        }
        $expectedChunks = collect([$target, ...array_slice($neighbors, 0, 2)])
            ->pluck('map_chunk_id')->unique()->sort()->values()->all();
        $this->assertSame($expectedChunks, $context->state->changedMapChunkIds());
        $events = DB::table('audit_events')->where('event_type', 'terrain.changed')
            ->orderBy('id')->get()->map(static fn ($event): array => json_decode(
                (string) $event->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ))->all();
        $this->assertCount(3, $events);
        $this->assertFalse($events[0]['adjacent_effect']);
        $this->assertSame([true, true], array_column(array_slice($events, 1), 'adjacent_effect'));
    }

    public function test_reclaim_rejects_water_adjacent_to_foreign_territory_without_mutating_neighbors(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, 'Reclaim protection owner');
        [, $rival] = $this->createNation($world, 'Reclaim protection rival');
        $nation->update(['money' => 1_000]);
        $target = $this->remoteWaterTarget($space);
        $neighbors = $this->neighborCells($target);
        $this->setCellState($neighbors[0], 'wasteland', $nation->id);
        $this->setCellState($neighbors[1], 'sea', $rival->id);
        $this->setOilFieldState($neighbors[2], $nation);
        $this->setOilFieldState($neighbors[3], $rival);
        foreach (array_slice($neighbors, 4) as $neighbor) {
            $this->setCellState($neighbor, 'wasteland', null);
        }
        $this->setCellState($target, 'shallow', null);
        $protected = collect(array_slice($neighbors, 1, 3))->mapWithKeys(
            static fn (MapCell $cell): array => [$cell->id => $cell->fresh()->only([
                'terrain_definition_id',
                'facility_definition_id',
                'owner_nation_id',
                'version',
            ])],
        );
        $item = $this->queue($user, $nation, $space, 'reclaim', $target);
        $context = $this->context($world, [$nation->id], str_repeat('a', 64));

        app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame('foreign_adjacent_water', $item->fresh()->failure_code);
        $this->assertSame('shallow', $target->fresh()->terrain()->value('key'));
        foreach ($protected as $cellId => $state) {
            $this->assertSame($state, MapCell::query()->findOrFail($cellId)->only(array_keys($state)));
        }
        $this->assertSame(
            [],
            DB::table('audit_events')->where('event_type', 'terrain.changed')->pluck('subject_id')->all(),
        );
        $this->assertSame([], $context->state->changedMapChunkIds());
    }

    public function test_reclaim_at_world_corner_ignores_out_of_bounds_neighbors(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '埋め立て境界国');
        $nation->update(['money' => 1_000]);
        $target = $this->cellAt($space, 0, 0);
        $neighbors = $this->neighborCells($target);
        $this->assertCount(3, $neighbors);
        $this->setCellState($target, 'shallow', null);
        $this->setCellState($neighbors[0], 'wasteland', $nation->id);
        foreach (array_slice($neighbors, 1) as $neighbor) {
            $this->setCellState($neighbor, 'sea', null);
        }
        $this->queue($user, $nation, $space, 'reclaim', $target);

        app(DomesticCommandExecutor::class)->execute(
            $this->context($world, [$nation->id], str_repeat('f', 64)),
        );

        $this->assertSame(
            $this->boundsFor($world)->cellCount(),
            MapCell::query()->where('map_space_id', $space->id)->count(),
        );
        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        foreach (array_slice($neighbors, 1) as $neighbor) {
            $this->assertSame('shallow', $neighbor->fresh()->terrain()->value('key'));
        }
        $metadata = DB::table('audit_events')->where('event_type', 'terrain.changed')
            ->pluck('metadata')->map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertCount(3, $metadata);
        $this->assertTrue($metadata->every(static fn (array $event): bool => $event['x'] >= 0 && $event['y'] >= 0));
    }

    public function test_seabed_oil_search_is_deterministic_spends_the_investment_and_is_not_applied_twice(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '海底油田国');
        $target = $this->remoteWaterTarget($space);
        $neighbors = $this->neighborCells($target);
        $this->setCellState($neighbors[0], 'wasteland', $nation->id);
        $this->setCellState($target, 'sea', null);
        Nation::query()->whereKey($nation->id)->update(['money' => 999]);
        $item = $this->queue($user, $nation, $space, 'excavate', $target, 5);
        $seed = $this->seedWithFirstDraw(TurnRandomStreamFactory::SEABED_OIL_SEARCH, 100, 3);
        $context = $this->context($world, [$nation->id], $seed);

        app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(199, $nation->fresh()->money);
        $this->assertSame('sea', $target->fresh()->terrain()->value('key'));
        $this->assertSame('seabed_oil_field', $target->fresh()->facility()->value('key'));
        $this->assertSame($nation->id, $target->fresh()->owner_nation_id);
        $this->assertContains($target->map_chunk_id, $context->state->changedMapChunkIds());
        $oil = $this->eventMetadataForSubject('command.seabed_oil_search', $target->id);
        $this->assertSame(3, $oil['draw']);
        $this->assertSame(4, $oil['success_threshold']);
        $this->assertSame(4, $oil['cost_units']);
        $this->assertSame(800, $oil['spent_money']);
        $this->assertTrue($oil['found']);
        $success = $this->eventMetadataForSubject('command.success', $item->id);
        $this->assertSame(800, $success['cost_money']);
        $world->update(['current_turn' => 2]);
        $playerEvents = collect(app(PlayerIslandEventService::class)->ownerPage($nation->fresh(), 1, 2)['groups'])
            ->flatMap(static fn (array $group): array => $group['events']);
        $this->assertStringContainsString(
            '800',
            $playerEvents->firstWhere('type', 'command.seabed_oil_search')['message'],
        );

        $version = $target->fresh()->version;
        app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame($version, $target->fresh()->version);
        $this->assertSame(209, $nation->fresh()->money);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.seabed_oil_search')->count());

        $this->setCellState($target, 'sea', null);
        Nation::query()->whereKey($nation->id)->update(['money' => 999]);
        $replayItem = $this->queue($user, $nation, $space, 'excavate', $target, 5);
        app(DomesticCommandExecutor::class)->execute($this->context($world, [$nation->id], $seed));
        $replay = $this->eventMetadataForSubject('command.seabed_oil_search', $target->id, $replayItem->id);
        $this->assertSame(
            collect($oil)->only(['draw', 'success_threshold', 'denominator', 'found'])->all(),
            collect($replay)->only(['draw', 'success_threshold', 'denominator', 'found'])->all(),
        );

        $this->setCellState($target, 'sea', null);
        Nation::query()->whereKey($nation->id)->update(['money' => 999]);
        $quantityLimitedItem = $this->queue($user, $nation, $space, 'excavate', $target, 2);
        app(DomesticCommandExecutor::class)->execute($this->context($world, [$nation->id], $seed));
        $quantityLimited = $this->eventMetadataForSubject(
            'command.seabed_oil_search',
            $target->id,
            $quantityLimitedItem->id,
        );
        $this->assertSame(2, $quantityLimited['cost_units']);
        $this->assertSame(400, $quantityLimited['spent_money']);
        $this->assertSame(599, $nation->fresh()->money);
        $this->assertSame(
            400,
            $this->eventMetadataForSubject('command.success', $quantityLimitedItem->id)['cost_money'],
        );
    }

    public function test_seabed_oil_search_failure_and_invalid_execution_have_explicit_cost_and_queue_results(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '海底油田失敗国');
        $target = $this->remoteWaterTarget($space);
        $neighbors = $this->neighborCells($target);
        $this->setCellState($neighbors[0], 'wasteland', $nation->id);
        $this->setCellState($target, 'sea', null);
        $nation->update(['money' => 1_000]);
        $failureItem = $this->queue($user, $nation, $space, 'excavate', $target, 3);
        $failureSeed = $this->seedWithFirstDraw(TurnRandomStreamFactory::SEABED_OIL_SEARCH, 100, 3);

        app(DomesticCommandExecutor::class)->execute($this->context($world, [$nation->id], $failureSeed));

        $this->assertSame('completed', $failureItem->fresh()->status);
        $this->assertSame(400, $nation->fresh()->money);
        $this->assertNull($target->fresh()->facility_definition_id);
        $this->assertNull($target->fresh()->owner_nation_id);
        $failure = $this->eventMetadataForSubject('command.seabed_oil_search', $target->id);
        $this->assertSame(3, $failure['draw']);
        $this->assertFalse($failure['found']);
        $this->assertSame(600, $failure['spent_money']);

        Nation::query()->whereKey($nation->id)->update(['money' => 199]);
        $insufficient = $this->queue($user, $nation, $space, 'excavate', $target, 99);
        app(DomesticCommandExecutor::class)->execute($this->context($world, [$nation->id], $failureSeed));
        $this->assertSame('failed', $insufficient->fresh()->status);
        $this->assertSame('insufficient_funds', $insufficient->fresh()->failure_code);
        $this->assertSame(209, $nation->fresh()->money);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.seabed_oil_search')->count());
    }

    public function test_buried_treasure_uses_exact_boundaries_capacity_replay_and_rollback(): void
    {
        $world = $this->lightweightWorld();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$user, $nation] = $this->createNation($world, '埋蔵検証');
        $target = $this->ownedTerrain($nation, 'forest');
        $invalidTarget = $this->ownedTerrain($nation, 'mountain');

        $successItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        $failureItem = null;
        $replayItem = null;
        $capacityItem = null;
        $invalidItem = $this->queue(
            $user,
            $nation,
            $space,
            'land_clear',
            $invalidTarget,
            1,
            2,
        );

        $successSeed = $this->seedWithFirstDraw(TurnRandomStreamFactory::LAND_CLEAR_BURIED_TREASURE, 1_000, 9);
        $failureSeed = $this->seedWithFirstDraw(TurnRandomStreamFactory::LAND_CLEAR_BURIED_TREASURE, 1_000, 10);
        $fixedMinimumRuleset = $this->rulesetWithTreasure($world, 10, 1_000, 100, 100);
        $executor = app(DomesticCommandExecutor::class);
        $executor->execute($this->context($world, [$nation->id], $successSeed, $fixedMinimumRuleset));

        $success = $this->eventMetadataForSubject('command.buried_treasure', $successItem->id);
        $this->assertSame(9, $success['draw']);
        $this->assertTrue($success['found']);
        $this->assertSame(100, $success['reward_money']);
        $this->assertSame(195, $nation->fresh()->money);

        $this->changeTerrain($target, 'forest');
        Nation::query()->whereKey($nation->id)->update(['money' => 100]);
        $failureItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        $executor->execute($this->context($world, [$nation->id], $failureSeed, $fixedMinimumRuleset));
        $failure = $this->eventMetadataForSubject('command.buried_treasure', $failureItem->id);
        $this->assertSame(10, $failure['draw']);
        $this->assertFalse($failure['found']);
        $this->assertSame(0, $failure['reward_money']);
        $this->assertSame(95, $nation->fresh()->money);

        $this->changeTerrain($target, 'forest');
        Nation::query()->whereKey($nation->id)->update(['money' => 100]);
        $replayItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        $executor->execute($this->context($world, [$nation->id], $successSeed, $fixedMinimumRuleset));
        $replay = $this->eventMetadataForSubject('command.buried_treasure', $replayItem->id);
        $this->assertSame(
            collect($success)->only(['draw', 'found', 'reward_money', 'applied_money', 'overflow_money'])->all(),
            collect($replay)->only(['draw', 'found', 'reward_money', 'applied_money', 'overflow_money'])->all(),
        );

        $this->changeTerrain($target, 'forest');
        Nation::query()->whereKey($nation->id)->update(['money' => 9_950]);
        $capacityItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        $maximumRuleset = $this->rulesetWithTreasure($world, 1, 1, 1_000, 1_000);
        $executor->execute($this->context($world, [$nation->id], str_repeat('b', 64), $maximumRuleset));
        $capacity = $this->eventMetadataForSubject('command.buried_treasure', $capacityItem->id);
        $this->assertSame(1_000, $capacity['reward_money']);
        $this->assertSame(54, $capacity['applied_money']);
        $this->assertSame(946, $capacity['overflow_money']);
        $this->assertSame(9_999, $nation->fresh()->money);

        Nation::query()->whereKey($nation->id)->update(['money' => 100]);
        $executor->execute($this->context($world, [$nation->id], $successSeed, $fixedMinimumRuleset));
        $this->assertSame('invalid_terrain', $invalidItem->fresh()->failure_code);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.buried_treasure')
            ->where('subject_id', $invalidItem->id)->count());

        $this->changeTerrain($target, 'forest');
        $insufficientItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        Nation::query()->whereKey($nation->id)->update(['money' => 0]);
        $executor->execute($this->context($world, [$nation->id], $successSeed, $fixedMinimumRuleset));
        $this->assertSame('insufficient_funds', $insufficientItem->fresh()->failure_code);
        $this->assertSame(10, $nation->fresh()->money);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.buried_treasure')
            ->where('subject_id', $insufficientItem->id)->count());

        $this->changeTerrain($target, 'forest');
        Nation::query()->whereKey($nation->id)->update(['money' => 100]);
        $ownershipItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        MapCell::query()->whereKey($target->id)->update(['owner_nation_id' => null]);
        $executor->execute($this->context($world, [$nation->id], $successSeed, $fixedMinimumRuleset));
        $this->assertSame('not_owned', $ownershipItem->fresh()->failure_code);
        $this->assertSame('forest', $target->fresh()->terrain()->value('key'));
        $this->assertSame(110, $nation->fresh()->money);
        MapCell::query()->whereKey($target->id)->update(['owner_nation_id' => $nation->id]);

        $this->changeTerrain($target, 'forest');
        Nation::query()->whereKey($nation->id)->update(['money' => 100]);
        $definition = CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'land_clear')->firstOrFail();
        $definition->update(['required_resources' => ['minerals' => 1]]);
        $resourceItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        $executor->execute($this->context($world, [$nation->id], $successSeed, $fixedMinimumRuleset));
        $this->assertSame('insufficient_resource', $resourceItem->fresh()->failure_code);
        $this->assertSame('forest', $target->fresh()->terrain()->value('key'));
        $this->assertSame(110, $nation->fresh()->money);
        $definition->update(['required_resources' => []]);

        $this->changeTerrain($target, 'forest');
        Nation::query()->whereKey($nation->id)->update(['money' => 9_999]);
        $rollbackItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        $eventCount = DB::table('audit_events')->count();
        DB::beginTransaction();
        try {
            $executor->execute($this->context($world, [$nation->id], str_repeat('c', 64), $maximumRuleset));
            $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
        } finally {
            DB::rollBack();
        }
        $this->assertSame('forest', $target->fresh()->terrain()->value('key'));
        $this->assertSame('queued', $rollbackItem->fresh()->status);
        $this->assertSame(9_999, $nation->fresh()->money);
        $this->assertSame($eventCount, DB::table('audit_events')->count());
    }

    public function test_flatland_construction_replaces_settlements_but_protects_capital_and_other_facilities(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->createNation($world, '集落建設国');
        $nation->update(['money' => 100_000]);
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $targets = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capital->id)->orderBy('id')->limit(5)->get();
        $this->assertCount(5, $targets);

        $states = app(MapCellStateService::class);
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        foreach ($targets as $target) {
            $states->transitionTerrain($target, $plain);
            $states->setFacility($target, null);
            $target->population = 0;
            $target->save();
        }
        $prosperity = MonumentDefinition::query()->where('key', 'prosperity')->firstOrFail();
        $prosperityId = (int) $prosperity->id;

        $items = [
            $this->queue($user, $nation, $space, 'build_farm', $targets[0], 1, 1),
            $this->queue($user, $nation, $space, 'build_factory', $targets[1], 1, 2),
            $this->queue($user, $nation, $space, 'build_defense_facility', $targets[2], 1, 3),
            $this->queue($user, $nation, $space, 'build_monument', $targets[3], $prosperityId, 4),
            $this->queue($user, $nation, $space, 'build_factory', $targets[4], 1, 5),
            $this->queue($user, $nation, $space, 'build_farm', $capital, 1, 6),
        ];

        foreach ([
            0 => 'village',
            1 => 'village',
            2 => 'town',
            3 => 'city',
            4 => 'defense',
        ] as $index => $facilityKey) {
            $states->setFacility(
                $targets[$index],
                FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
            );
            $targets[$index]->population = 1_000 * ($index + 1);
            $targets[$index]->save();
        }
        MonumentDefinition::query()->where('key', 'peace')->update(['enabled' => false]);
        $prosperity->update(['sort_order' => 1]);

        $executor = app(DomesticCommandExecutor::class);
        for ($turn = 2; $turn <= 5; $turn++) {
            $result = $executor->execute($this->context(
                $world,
                [$nation->id],
                hash('sha256', "settlement-overbuild:{$turn}"),
                targetTurn: $turn,
            ));
            $this->assertSame(1, $result['successes']);
        }
        $failureResult = $executor->execute($this->context(
            $world,
            [$nation->id],
            hash('sha256', 'settlement-overbuild:failures'),
            targetTurn: 6,
        ));

        $this->assertSame(['completed', 'completed', 'completed', 'completed', 'failed', 'failed'],
            collect($items)->map(fn (NationCommandQueueItem $item): string => $item->fresh()->status)->all());
        $this->assertSame('facility_exists', $items[4]->fresh()->failure_code);
        $this->assertSame('capital_protected', $items[5]->fresh()->failure_code);
        $this->assertSame(2, $failureResult['failures']);
        $this->assertSame(['farm', 'factory', 'defense', 'monument'],
            $targets->take(4)->map(fn (MapCell $cell): string => $cell->fresh()->facility()->value('key'))->all());
        $this->assertSame('prosperity', $targets[3]->fresh()->monumentDefinition()->value('key'));
        $this->assertSame([0, 0, 0, 0],
            $targets->take(4)->map(fn (MapCell $cell): int => $cell->fresh()->population)->all());
        $this->assertSame('defense', $targets[4]->fresh()->facility()->value('key'));
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));
    }

    public function test_plant_forest_preview_registration_and_execution_replace_only_settlements(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->createNation($world, '集落植林国');
        $nation->update(['money' => 1_000]);
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $targets = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capital->id)->orderBy('id')->limit(6)->get();
        $this->assertCount(6, $targets);

        $states = app(MapCellStateService::class);
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        foreach ($targets as $target) {
            $states->transitionTerrain($target, $plain);
            $states->setFacility($target, null);
            $target->population = 0;
            $target->save();
        }
        foreach (['village', 'town', 'city'] as $index => $facilityKey) {
            $states->setFacility(
                $targets[$index],
                FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
            );
            $targets[$index]->population = 1_000 * ($index + 1);
            $targets[$index]->save();
        }
        foreach (['farm', 'factory', 'missile_base'] as $offset => $facilityKey) {
            $index = $offset + 3;
            $states->setFacility(
                $targets[$index],
                FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
            );
            $targets[$index]->save();
        }

        $base = "/api/v1/nations/{$nation->id}/map-spaces/{$space->id}";
        foreach ($targets->take(3) as $target) {
            $plantForest = collect($this->actingAs($user)->getJson(
                "{$base}/command-definitions?target_x={$target->x}&target_y={$target->y}",
            )->assertOk()->json('data.commands'))->firstWhere('key', 'plant_forest');
            $this->assertSame('currently_executable', $plantForest['execution_preview_status']);
        }
        foreach ($targets->slice(3)->push($capital) as $target) {
            $plantForest = collect($this->getJson(
                "{$base}/command-definitions?target_x={$target->x}&target_y={$target->y}",
            )->assertOk()->json('data.commands'))->firstWhere('key', 'plant_forest');
            $this->assertSame('currently_unavailable', $plantForest['execution_preview_status']);
        }

        $expectedVersion = 1;
        foreach ($targets->take(3) as $target) {
            $this->postJson("{$base}/command-queue", [
                'command_key' => 'plant_forest',
                'target_x' => $target->x,
                'target_y' => $target->y,
                'request_key' => (string) Str::uuid(),
                'expected_version' => $expectedVersion++,
            ])->assertCreated();
        }
        foreach ($targets->slice(3) as $target) {
            $this->postJson("{$base}/command-queue", [
                'command_key' => 'plant_forest',
                'target_x' => $target->x,
                'target_y' => $target->y,
                'request_key' => (string) Str::uuid(),
                'expected_version' => $expectedVersion++,
            ])->assertCreated();
        }
        $this->postJson("{$base}/command-queue", [
            'command_key' => 'plant_forest',
            'target_x' => $capital->x,
            'target_y' => $capital->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => $expectedVersion,
        ])->assertUnprocessable();

        $items = NationCommandQueueItem::query()
            ->whereHas('queue', fn ($query) => $query->where('nation_id', $nation->id))
            ->orderBy('queue_position')->get()->all();
        $items[] = $this->queue($user, $nation, $space, 'plant_forest', $capital, 1, 7);

        $executor = app(DomesticCommandExecutor::class);
        for ($turn = 2; $turn <= 4; $turn++) {
            $result = $executor->execute($this->context(
                $world,
                [$nation->id],
                hash('sha256', "plant-forest-settlement:{$turn}"),
                targetTurn: $turn,
            ));
            $this->assertSame(1, $result['successes']);
        }
        $this->assertSame(850, $nation->fresh()->money);
        $failureResult = $executor->execute($this->context(
            $world,
            [$nation->id],
            hash('sha256', 'plant-forest-non-settlement'),
            targetTurn: 5,
        ));

        $this->assertSame(
            ['completed', 'completed', 'completed', 'failed', 'failed', 'failed', 'failed'],
            collect($items)->map(fn (NationCommandQueueItem $item): string => $item->fresh()->status)->all(),
        );
        $this->assertSame(
            ['facility_exists', 'facility_exists', 'facility_exists'],
            collect(array_slice($items, 3, 3))
                ->map(fn (NationCommandQueueItem $item): ?string => $item->fresh()->failure_code)->all(),
        );
        $this->assertSame('capital_protected', $items[6]->fresh()->failure_code);
        $this->assertSame(4, $failureResult['failures']);
        foreach ($targets->take(3) as $target) {
            $this->assertNull($target->fresh()->facility_definition_id);
            $this->assertSame('forest', $target->fresh()->terrain()->value('key'));
            $this->assertSame(0, $target->fresh()->population);
            $this->assertSame($nation->id, $target->fresh()->owner_nation_id);
        }
        $this->assertSame(
            ['farm', 'factory', 'missile_base'],
            $targets->slice(3)->values()
                ->map(fn (MapCell $cell): string => $cell->fresh()->facility()->value('key'))->all(),
        );
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));
        $this->assertSame(860, $nation->fresh()->money);
    }

    /** @return array{User, Nation} */
    private function createNation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name, '試験島主')];
    }

    private function queue(
        User $user,
        Nation $nation,
        MapSpace $space,
        string $commandKey,
        MapCell $target,
        int $quantity = 1,
        ?int $position = null,
    ): NationCommandQueueItem {
        $queue = NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $space->id, 'version' => 1],
        );
        $position ??= NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)->where('status', 'queued')->count() + 1;
        $definition = CommandDefinition::query()->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
            ->where('key', $commandKey)->firstOrFail();
        $membership = NationMembership::query()->where('user_id', $user->id)->where('nation_id', $nation->id)->firstOrFail();

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $definition->id,
            'queue_position' => $position,
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => $quantity,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => $membership->id,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => [],
        ])->load('definition');
    }

    /** @param list<int> $nationIds */
    private function context(
        World $world,
        array $nationIds,
        string $seed,
        ?RulesetVersion $ruleset = null,
        int $targetTurn = 2,
    ): TurnContext {
        $ruleset ??= $world->rulesetVersion()->firstOrFail();
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

    private function rulesetWithTreasure(
        World $world,
        int $numerator,
        int $denominator,
        int $minimum,
        int $maximum,
    ): RulesetVersion {
        $ruleset = clone $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        $settings['turn_processing']['command_random_effects']['land_clear_buried_treasure'] = [
            'probability' => ['numerator' => $numerator, 'denominator' => $denominator],
            'reward_minimum_money' => $minimum,
            'reward_maximum_money' => $maximum,
        ];
        $ruleset->settings = $settings;

        return $ruleset;
    }

    private function seedWithFirstDraw(string $label, int $denominator, int $expected): string
    {
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$candidate}");
            if ((new TurnRandomStreamFactory($seed))->stream($label)->integer(0, $denominator - 1) === $expected) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic draw {$expected} for {$label}.");
    }

    private function ownedTerrain(Nation $nation, string $terrainKey): MapCell
    {
        return MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', $terrainKey))
            ->orderBy('id')->firstOrFail();
    }

    private function reclaimTarget(Nation $nation, MapSpace $space): MapCell
    {
        $shallowCells = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'shallow'))->orderBy('id')->get();
        foreach ($shallowCells as $cell) {
            foreach ((new GridCoordinate($cell->x, $cell->y))->radius(1) as $coordinate) {
                if (MapCell::query()->where('map_space_id', $space->id)
                    ->where('owner_nation_id', $nation->id)
                    ->where('x', $coordinate->x)->where('y', $coordinate->y)->exists()) {
                    return $cell;
                }
            }
        }

        $this->fail('Initial island did not provide an adjacent shallow reclaim target.');
    }

    private function remoteWaterTarget(MapSpace $space): MapCell
    {
        return MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereBetween('x', [10, 49])
            ->whereBetween('y', [10, 49])
            ->whereNull('owner_nation_id')
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')
            ->firstOrFail();
    }

    /** @return list<MapCell> */
    private function neighborCells(MapCell $cell): array
    {
        $origin = new GridCoordinate($cell->x, $cell->y);
        $neighbors = [];
        foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
            $coordinate = $origin->neighbor($direction);
            $neighbor = MapCell::query()->where('map_space_id', $cell->map_space_id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)->first();
            if ($neighbor !== null) {
                $neighbors[] = $neighbor;
            }
        }

        return $neighbors;
    }

    private function cellAt(MapSpace $space, int $x, int $y): MapCell
    {
        return MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $x)->where('y', $y)->firstOrFail();
    }

    private function setCellState(MapCell $cell, string $terrainKey, ?int $ownerNationId): void
    {
        $this->changeTerrain($cell, $terrainKey);
        $cell->fresh()->update(['owner_nation_id' => $ownerNationId]);
    }

    private function setOilFieldState(MapCell $cell, Nation $owner): void
    {
        $this->setCellState($cell, 'sea', $owner->id);
        $cell = $cell->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'seabed_oil_field')->firstOrFail(),
        );
        $cell->save();
    }

    private function changeTerrain(MapCell $cell, string $terrainKey): void
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        $service = app(MapCellStateService::class);
        $service->setFacility($cell, null);
        $service->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        $cell->population = 0;
        $cell->save();
    }

    /** @param callable(Builder): void $constraint
     * @return array<string, mixed>
     */
    private function eventMetadata(string $eventType, callable $constraint): array
    {
        $query = DB::table('audit_events')->where('event_type', $eventType);
        $constraint($query);

        return json_decode((string) $query->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function eventMetadataForSubject(
        string $eventType,
        int $subjectId,
        ?int $queueItemId = null,
    ): array {
        return $this->eventMetadata($eventType, static function ($query) use ($subjectId, $queueItemId): void {
            $query->where('subject_id', $subjectId);
            if ($queueItemId !== null) {
                $query->whereRaw("metadata->>'queue_item_id' = ?", [(string) $queueItemId]);
            }
        });
    }
}
