<?php

namespace Tests\Feature;

use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
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
use Tests\TestCase;

class DomesticCommandExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_domestic_commands_revalidate_mutate_queue_and_honor_turn_consumption(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
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
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'like', '%earthquake%')->count());

        $landLevelEvent = $this->eventMetadata('command.success', function ($query): void {
            $query->whereRaw("metadata->>'command_key' = ?", ['land_level']);
        });
        $this->assertFalse($landLevelEvent['consumes_turn']);
        $this->assertArrayNotHasKey('earthquake', $landLevelEvent);
    }

    public function test_terrain_commands_cannot_remove_the_nation_capital(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
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
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'command.invalid')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'terrain.changed')->count());
    }

    public function test_buried_treasure_uses_exact_boundaries_capacity_replay_and_rollback(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
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
        $this->assertSame('insufficient_money', $insufficientItem->fresh()->failure_code);
        $this->assertSame(10, $nation->fresh()->money);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.buried_treasure')
            ->where('subject_id', $insufficientItem->id)->count());

        $this->changeTerrain($target, 'forest');
        Nation::query()->whereKey($nation->id)->update(['money' => 100]);
        $ownershipItem = $this->queue($user, $nation, $space, 'land_clear', $target);
        MapCell::query()->whereKey($target->id)->update(['owner_nation_id' => null]);
        $executor->execute($this->context($world, [$nation->id], $successSeed, $fixedMinimumRuleset));
        $this->assertSame('ownership_mismatch', $ownershipItem->fresh()->failure_code);
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
        $this->assertSame('insufficient_resources', $resourceItem->fresh()->failure_code);
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

    /** @return array{User, Nation} */
    private function createNation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name)];
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
    ): TurnContext {
        $ruleset ??= $world->rulesetVersion()->firstOrFail();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 1,
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

        return new TurnContext($world, $run, $ruleset, 1, $seed, new TurnRandomStreamFactory($seed), $state);
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
    private function eventMetadataForSubject(string $eventType, int $subjectId): array
    {
        return $this->eventMetadata($eventType, static function ($query) use ($subjectId): void {
            $query->where('subject_id', $subjectId);
        });
    }
}
