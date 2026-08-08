<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\TurnRunner;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnOrderService;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\WorldTurnLock;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMonsterKillStat;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RulesetV2LiveMonsterReferenceRepairTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_all_monster_states_and_kill_stats_are_repointed_without_changing_other_state(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, 'hotfix audit nation');
        $this->moveWorldToV1($world);
        $v1 = $world->rulesetVersion()->firstOrFail();
        $definition = $this->definition($v1, 'inora');

        $instances = collect([
            $this->monster($world, $definition, 'alive'),
            $this->monster($world, $definition, 'killed'),
            $this->monster($world, $definition, 'removed'),
        ]);
        $stat = $this->killStat($world, $nation, $definition, [4, 9, 17, 23, 29, 34, 38]);
        $historicalRun = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 1,
            'ruleset_version_id' => $v1->id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_COMPLETED,
            'attempt_count' => 1,
            'pipeline' => [['key' => 'historical']],
            'phase_results' => [['phase' => 'historical']],
            'failure_context' => [],
        ]);
        $instanceSnapshots = $instances->mapWithKeys(static function (MonsterInstance $instance): array {
            $fresh = $instance->fresh();

            return [
                $instance->id => collect(Arr::except($fresh->getAttributes(), ['monster_definition_id']))
                    ->sortKeys()->all(),
            ];
        })->all();
        $statSnapshot = collect(Arr::except($stat->getAttributes(), ['monster_definition_id']))->sortKeys()->all();
        $runSnapshot = collect($historicalRun->fresh()->getAttributes())->sortKeys()->all();

        $this->v2Migration()->up();
        $v2 = $world->fresh()->rulesetVersion()->firstOrFail();
        $this->assertSame('hakoniwa-2s-plus-v2', $v2->key);
        $this->assertSame($definition->id, $instances->first()->fresh()->monster_definition_id);
        $this->assertSame($v1->id, $stat->fresh()->definition()->value('ruleset_version_id'));

        $failedRun = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v2->id,
            'random_seed' => str_repeat('b', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_FAILED,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_code' => 'turn_execution_failed',
            'failure_message' => 'production-shaped failed run',
            'failure_context' => ['phase' => 'process_cells'],
        ]);
        $failedRunSnapshot = collect($failedRun->fresh()->getAttributes())->sortKeys()->all();

        $this->repairMigration()->up();

        $v2Definition = $this->definition($v2, 'inora');
        foreach ($instances as $instance) {
            $this->assertSame($v2Definition->id, $instance->fresh()->monster_definition_id);
            $this->assertSame(
                $instanceSnapshots[$instance->id],
                collect(Arr::except($instance->fresh()->getAttributes(), ['monster_definition_id']))
                    ->sortKeys()->all(),
            );
        }
        $this->assertSame($v2Definition->id, $stat->fresh()->monster_definition_id);
        $this->assertSame(
            $statSnapshot,
            collect(Arr::except($stat->fresh()->getAttributes(), ['monster_definition_id']))->sortKeys()->all(),
        );
        $this->assertSame($runSnapshot, collect($historicalRun->fresh()->getAttributes())->sortKeys()->all());
        $this->assertSame($failedRunSnapshot, collect($failedRun->fresh()->getAttributes())->sortKeys()->all());
        $this->assertSame('O', $this->triggerState('nation_monster_kill_stat_guard'));
        $this->assertLiveRulesetReferenceConsistency($world->fresh());

        $afterFirstRun = [
            'instances' => MonsterInstance::query()->where('world_id', $world->id)->orderBy('id')->get()->map->getAttributes()->all(),
            'stats' => NationMonsterKillStat::query()->where('world_id', $world->id)->orderBy('id')->get()->map->getAttributes()->all(),
            'runs' => TurnRun::query()->where('world_id', $world->id)->orderBy('id')->get()->map->getAttributes()->all(),
        ];
        $this->repairMigration()->up();
        $this->assertSame($afterFirstRun['instances'], MonsterInstance::query()->where('world_id', $world->id)->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($afterFirstRun['stats'], NationMonsterKillStat::query()->where('world_id', $world->id)->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($afterFirstRun['runs'], TurnRun::query()->where('world_id', $world->id)->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_missing_monster_definition_mapping_fails_without_partial_repair(): void
    {
        [$world, $instance, $stat, $v1Definition] = $this->worldWithV1MonsterState();
        $this->v2Migration()->up();
        $v2 = $world->fresh()->rulesetVersion()->firstOrFail();
        DB::table('monster_definitions')->where('ruleset_version_id', $v2->id)->where('key', 'inora')->delete();

        $this->expectRepairFailure('different monster definition sets');

        $this->assertSame($v1Definition->id, $instance->fresh()->monster_definition_id);
        $this->assertSame($v1Definition->id, $stat->fresh()->monster_definition_id);
        $this->assertSame('O', $this->triggerState('nation_monster_kill_stat_guard'));
    }

    public function test_ambiguous_monster_definition_mapping_fails_without_partial_repair(): void
    {
        [$world, $instance, $stat, $v1Definition] = $this->worldWithV1MonsterState();
        $this->v2Migration()->up();
        $v2 = $world->fresh()->rulesetVersion()->firstOrFail();
        $copy = MonsterDefinition::query()->where('ruleset_version_id', $v2->id)->where('key', 'inora')
            ->firstOrFail()->getAttributes();
        unset($copy['id']);
        $copy['asset_key'] = 'test.ambiguous.inora';
        DB::statement('ALTER TABLE monster_definitions DROP CONSTRAINT monster_definitions_ruleset_version_id_key_unique');
        DB::table('monster_definitions')->insert($copy);

        $this->expectRepairFailure('ambiguous monster definition keys');

        $this->assertSame($v1Definition->id, $instance->fresh()->monster_definition_id);
        $this->assertSame($v1Definition->id, $stat->fresh()->monster_definition_id);
        $this->assertSame('O', $this->triggerState('nation_monster_kill_stat_guard'));
    }

    public function test_existing_v2_kill_stat_collision_fails_instead_of_merging(): void
    {
        [$world, $instance, $stat, $v1Definition] = $this->worldWithV1MonsterState();
        $this->v2Migration()->up();
        $v2 = $world->fresh()->rulesetVersion()->firstOrFail();
        $v2Definition = $this->definition($v2, 'inora');
        $v2Stat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $stat->nation_id,
            'monster_definition_id' => $v2Definition->id,
            'kill_count' => 1,
            'first_killed_turn' => 39,
            'last_killed_turn' => 39,
            'version' => 1,
        ]);

        $this->expectRepairFailure('refusing to merge aggregates');

        $this->assertSame($v1Definition->id, $instance->fresh()->monster_definition_id);
        $this->assertSame($v1Definition->id, $stat->fresh()->monster_definition_id);
        $this->assertSame(3, $stat->fresh()->kill_count);
        $this->assertSame(1, $v2Stat->fresh()->kill_count);
        $this->assertSame('O', $this->triggerState('nation_monster_kill_stat_guard'));
    }

    public function test_v1_monster_is_killed_by_pp_missile_after_repair_and_failed_turn_manual_retry_completes(): void
    {
        $world = $this->lightweightWorld();
        [$user, $firingNation] = $this->nation($world, 'hotfix firing nation');
        [, $targetNation] = $this->nation($world, 'hotfix target nation');
        $this->moveWorldToV1($world);
        $v1 = $world->rulesetVersion()->firstOrFail();
        $v1Definition = $this->definition($v1, 'inora');
        $target = MapCell::query()->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->value('map_cell_id'))->firstOrFail();
        app(MapCellStateService::class)->setFacility($target, null);
        app(MapCellStateService::class)->transitionTerrain(
            $target,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $target->save();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $v1Definition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 9,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $target->id,
        ]);

        $this->v2Migration()->up();
        $this->repairMigration()->up();
        $world->refresh();
        $this->assertLiveRulesetReferenceConsistency($world);

        $base = MapCell::query()->where('owner_nation_id', $firingNation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $base,
            FacilityDefinition::query()->where('key', 'missile_base')->firstOrFail(),
        );
        $base->save();
        $firingNation->update(['money' => 10_000]);
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $firingNation,
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'pp_missile',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: (int) ($firingNation->commandQueue()->value('version') ?? 1),
            quantity: 1,
            parameters: [],
            position: 1,
        )['item'];
        $seed = $this->seedForPpHitBeforeMonsterMoves($world, $item, $base, $target);
        $failedRun = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_FAILED,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_code' => 'turn_execution_failed',
            'failure_message' => 'pre-hotfix production failure',
            'failure_context' => ['phase' => 'process_cells'],
        ]);

        $run = (new TurnRunner(
            app(TurnPipeline::class),
            new WorldTurnLock,
            new HotfixFixedTurnSeedGenerator(str_repeat('f', 64)),
            app(CurrentRulesetGuard::class),
        ))->run($world, source: 'manual');

        $this->assertSame($failedRun->id, $run->id);
        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->attempt_count);
        $this->assertSame($seed, $run->random_seed);
        $this->assertSame($run->target_turn, $world->fresh()->current_turn);
        $this->assertSame('killed', $monster->fresh()->state);
        $this->assertSame('completed', $item->fresh()->status);
        $stat = NationMonsterKillStat::query()->where('world_id', $world->id)
            ->where('nation_id', $firingNation->id)->firstOrFail();
        $this->assertSame(1, $stat->kill_count);
        $this->assertSame('hakoniwa-2s-plus-v2', $stat->definition()->firstOrFail()->rulesetVersion()->value('key'));
    }

    /** @return array{World, MonsterInstance, NationMonsterKillStat, MonsterDefinition} */
    private function worldWithV1MonsterState(): array
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, 'hotfix failure nation');
        $this->moveWorldToV1($world);
        $definition = $this->definition($world->rulesetVersion()->firstOrFail(), 'inora');
        $instance = $this->monster($world, $definition, 'alive');
        $stat = $this->killStat($world, $nation, $definition, [7, 14, 20]);

        return [$world, $instance, $stat, $definition];
    }

    private function monster(World $world, MonsterDefinition $definition, string $state): MonsterInstance
    {
        $removed = $state !== 'alive';

        return MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $state === 'killed' ? 0 : 1,
            'spawned_max_hp' => 1,
            'state' => $state,
            'spawned_target_turn' => 12,
            'version' => 11,
            'removal_reason' => $removed ? "test_{$state}" : null,
            'removed_at' => $removed ? now()->subMinute() : null,
        ]);
    }

    /** @param non-empty-list<int> $turns */
    private function killStat(
        World $world,
        Nation $nation,
        MonsterDefinition $definition,
        array $turns,
    ): NationMonsterKillStat {
        $firstTurn = array_shift($turns);
        $stat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $definition->id,
            'kill_count' => 1,
            'first_killed_turn' => $firstTurn,
            'last_killed_turn' => $firstTurn,
            'version' => 1,
        ]);
        foreach ($turns as $turn) {
            $stat->update([
                'kill_count' => $stat->kill_count + 1,
                'last_killed_turn' => $turn,
                'version' => $stat->version + 1,
            ]);
        }

        return $stat->fresh();
    }

    /** @return array{User, Nation} */
    private function nation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name, 'test owner')];
    }

    private function moveWorldToV1(World $world): void
    {
        $v1 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->firstOrFail();
        DB::transaction(function () use ($world, $v1): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $items = NationCommandQueueItem::query()
                ->whereIn('nation_command_queue_id', NationCommandQueue::query()
                    ->whereIn('nation_id', DB::table('nations')->where('world_id', $world->id)->select('id'))
                    ->select('id'))
                ->with('definition')->get();
            foreach ($items as $item) {
                $definition = CommandDefinition::query()->where('ruleset_version_id', $v1->id)
                    ->where('key', $item->definition->key)->firstOrFail();
                $item->update(['command_definition_id' => $definition->id]);
            }
            $world->update(['ruleset_version_id' => $v1->id]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });
        $world->refresh();
    }

    private function definition(RulesetVersion $ruleset, string $key): MonsterDefinition
    {
        return MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', $key)->firstOrFail();
    }

    private function expectRepairFailure(string $message): void
    {
        try {
            $this->repairMigration()->up();
            $this->fail('Expected the live monster reference repair to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function assertLiveRulesetReferenceConsistency(World $world): void
    {
        $counts = DB::selectOne(<<<'SQL'
SELECT
    (SELECT count(*)
       FROM nation_command_queue_items item
       JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
       JOIN nations nation ON nation.id = queue.nation_id
       JOIN command_definitions definition ON definition.id = item.command_definition_id
      WHERE nation.world_id = ? AND definition.ruleset_version_id <> ?) AS queue_mismatches,
    (SELECT count(*)
       FROM monster_instances instance
       JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
      WHERE instance.world_id = ? AND definition.ruleset_version_id <> ?) AS instance_mismatches,
    (SELECT count(*)
       FROM nation_monster_kill_stats stat
       JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
      WHERE stat.world_id = ? AND definition.ruleset_version_id <> ?) AS stat_mismatches
SQL, [
            $world->id, $world->ruleset_version_id,
            $world->id, $world->ruleset_version_id,
            $world->id, $world->ruleset_version_id,
        ]);

        $this->assertSame(0, (int) $counts->queue_mismatches);
        $this->assertSame(0, (int) $counts->instance_mismatches);
        $this->assertSame(0, (int) $counts->stat_mismatches);
    }

    private function seedForPpHitBeforeMonsterMoves(
        World $world,
        NationCommandQueueItem $item,
        MapCell $base,
        MapCell $target,
    ): string {
        $coordinates = (new GridCoordinate($target->x, $target->y))->radius(1);
        $desired = array_search(
            $target->x.':'.$target->y,
            array_map(static fn ($coordinate): string => $coordinate->x.':'.$coordinate->y, $coordinates),
            true,
        );
        $this->assertIsInt($desired);
        $missileLabel = TurnRandomStreamFactory::missileImpact($item->id);
        $orders = app(TurnOrderService::class);
        for ($candidate = 0; $candidate < 20_000; $candidate++) {
            $seed = hash('sha256', "hotfix-pp-{$candidate}");
            $random = new TurnRandomStreamFactory($seed);
            if ($random->stream($missileLabel)->integer(0, count($coordinates) - 1) !== $desired) {
                continue;
            }
            $cellIds = $orders->shuffledSurfaceCellIds($world, $random);
            $basePosition = array_search($base->id, $cellIds, true);
            $targetPosition = array_search($target->id, $cellIds, true);
            if (is_int($basePosition) && is_int($targetPosition) && $basePosition < $targetPosition) {
                return $seed;
            }
        }

        $this->fail('Unable to find a deterministic PP hit before monster movement.');
    }

    private function triggerState(string $trigger): string
    {
        return (string) DB::table('pg_trigger')->where('tgname', $trigger)->value('tgenabled');
    }

    private function v2Migration(): object
    {
        return require database_path('migrations/2026_08_09_000000_publish_hakoniwa_2s_plus_v2.php');
    }

    private function repairMigration(): object
    {
        return require database_path('migrations/2026_08_09_020000_repair_hakoniwa_2s_plus_v2_live_monster_references.php');
    }
}

final readonly class HotfixFixedTurnSeedGenerator implements TurnSeedGenerator
{
    public function __construct(private string $seed) {}

    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return $this->seed;
    }
}
