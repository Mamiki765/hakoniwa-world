<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\RulesetV11MigrationService;
use App\Domain\Command\CommandRequestConflictException;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\NationMonsterKillStat;
use App\Models\RulesetVersion;
use App\Models\Secretary;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesHistoricalRulesetDatabaseFixtures;
use Tests\TestCase;

final class RulesetV11MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesHistoricalRulesetDatabaseFixtures;

    public function test_conversion_preserves_history_and_items_rebinds_only_live_rows_and_is_exactly_idempotent(): void
    {
        $fixture = $this->v10Fixture();
        $before = $this->preservedPayloads($fixture);
        $fingerprints = NationCommandQueueItem::query()->orderBy('id')
            ->pluck('request_fingerprint', 'id')->all();

        $result = app(RulesetV11MigrationService::class)->migrate();

        $v11 = RulesetVersion::query()->where('key', RulesetV11MigrationService::TARGET_KEY)->sole();
        $this->assertSame($v11->id, $result->rulesetVersionId);
        $this->assertFalse($result->published);
        $this->assertFalse($result->alreadyCompleted);
        $this->assertSame(4, $result->requestProvenanceBackfilled);
        $this->assertSame(2, $result->queuedCommandsRebound);
        $this->assertSame(1, $result->aliveMonstersRebound);
        $this->assertSame(1, $result->killStatsRebound);
        $this->assertSame(1, $result->worldsActivated);
        $this->assertSame($v11->id, $fixture['world']->fresh()->ruleset_version_id);

        foreach ([$fixture['dispatch'], $fixture['queued']] as $queued) {
            $this->assertSame($v11->id, $queued->fresh()->definition()->value('ruleset_version_id'));
            $this->assertSame($fixture['v10']->id, $queued->fresh()->request_ruleset_version_id);
        }
        foreach ($fixture['terminal'] as $terminal) {
            $this->assertSame($fixture['v10']->id, $terminal->fresh()->definition()->value('ruleset_version_id'));
            if ($terminal->request_fingerprint === null) {
                $this->assertNull($terminal->fresh()->request_ruleset_version_id);
            } else {
                $this->assertSame($fixture['v10']->id, $terminal->fresh()->request_ruleset_version_id);
            }
        }
        $this->assertSame($v11->id, $fixture['alive']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($fixture['v10']->id, $fixture['killed']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($fixture['v10']->id, $fixture['removed']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($v11->id, $fixture['stat']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($fixture['occupancy']->map_cell_id, $fixture['occupancy']->fresh()->map_cell_id);
        $this->assertSame($fingerprints, NationCommandQueueItem::query()->orderBy('id')
            ->pluck('request_fingerprint', 'id')->all());
        $this->assertSame($before, $this->preservedPayloads($fixture));
        $this->assertDatabaseMissing('secretary_item_instances', ['item_key' => 'ring']);
        $this->assertIntegrityGuardsEnabled();

        $counts = $this->conversionRowCounts();
        $second = app(RulesetV11MigrationService::class)->migrate();
        $this->assertTrue($second->alreadyCompleted);
        $this->assertFalse($second->published);
        $this->assertSame([0, 0, 0, 0, 0], [
            $second->requestProvenanceBackfilled,
            $second->queuedCommandsRebound,
            $second->aliveMonstersRebound,
            $second->killStatsRebound,
            $second->worldsActivated,
        ]);
        $this->assertSame($counts, $this->conversionRowCounts());
        $this->assertSame($fingerprints, NationCommandQueueItem::query()->orderBy('id')
            ->pluck('request_fingerprint', 'id')->all());
    }

    #[DataProvider('migratedTerminalStatuses')]
    public function test_second_run_accepts_migrated_commands_that_later_become_terminal(
        string $status,
        string $timestampColumn,
    ): void {
        $fixture = $this->v10Fixture();
        app(RulesetV11MigrationService::class)->migrate();

        $v11Id = RulesetVersion::query()->where('key', RulesetV11MigrationService::TARGET_KEY)
            ->valueOrFail('id');
        $items = collect([$fixture['dispatch'], $fixture['queued']])->map->fresh();
        $fingerprints = $items->pluck('request_fingerprint', 'id')->all();

        foreach ($items as $item) {
            $item->update([
                'status' => $status,
                'queue_position' => null,
                $timestampColumn => now(),
            ]);
        }

        $second = app(RulesetV11MigrationService::class)->migrate();

        $this->assertTrue($second->alreadyCompleted);
        foreach ($items as $item) {
            $terminal = $item->fresh();
            $this->assertSame($status, $terminal->status);
            $this->assertSame($v11Id, $terminal->definition()->value('ruleset_version_id'));
            $this->assertSame($fixture['v10']->id, $terminal->request_ruleset_version_id);
            $this->assertSame($fingerprints[$terminal->id], $terminal->request_fingerprint);
        }
    }

    public function test_historical_selector_less_dispatch_retry_converges_after_real_migration_and_selector_two_conflicts(): void
    {
        $fixture = $this->v10Fixture();
        $item = $fixture['dispatch'];
        $requestKey = $item->request_key;
        $fingerprint = $item->request_fingerprint;

        app(RulesetV11MigrationService::class)->migrate();

        $duplicate = app(CommandQueueService::class)->add(
            user: $fixture['user'],
            nation: $fixture['nation'],
            mapSpace: $this->surfaceMapSpace($fixture['world']->fresh()),
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 999,
            parameters: ['target_nation_id' => $fixture['target']->id],
        );
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($fingerprint, $duplicate['item']->request_fingerprint);
        $this->assertSame($fixture['v10']->id, $duplicate['item']->request_ruleset_version_id);

        $this->expectException(CommandRequestConflictException::class);
        app(CommandQueueService::class)->add(
            user: $fixture['user'],
            nation: $fixture['nation'],
            mapSpace: $this->surfaceMapSpace($fixture['world']->fresh()),
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 999,
            quantity: 2,
            parameters: ['target_nation_id' => $fixture['target']->id],
            quantityProvided: true,
        );
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_unresolved_next_nondry_turn_run_blocks_before_any_mutation(string $status): void
    {
        $fixture = $this->v10Fixture(withLiveData: false);
        $run = $this->turnRun($fixture['world'], $fixture['v10'], $status, false,
            $fixture['world']->current_turn + 1);
        $before = [$fixture['world']->fresh()->getAttributes(), $run->fresh()->getAttributes()];

        try {
            app(RulesetV11MigrationService::class)->migrate();
            $this->fail("Expected {$status} TurnRun to block v11 migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing v11 migration', $exception->getMessage());
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertSame($before, [$fixture['world']->fresh()->getAttributes(), $run->fresh()->getAttributes()]);
        $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
    }

    public function test_completed_next_run_dry_history_and_older_failed_history_do_not_block(): void
    {
        $fixture = $this->v10Fixture(withLiveData: false);
        $this->turnRun($fixture['world'], $fixture['v10'], TurnRun::STATUS_COMPLETED, false,
            $fixture['world']->current_turn + 1);
        $this->turnRun($fixture['world'], $fixture['v10'], TurnRun::STATUS_FAILED, true,
            $fixture['world']->current_turn + 1);
        $this->turnRun($fixture['world'], $fixture['v10'], TurnRun::STATUS_FAILED, false,
            $fixture['world']->current_turn);

        $result = app(RulesetV11MigrationService::class)->migrate();

        $this->assertSame(1, $result->worldsActivated);
        $this->assertSame(3, TurnRun::query()->count());
    }

    public function test_turn_run_guard_prevents_even_a_new_v11_publication_row(): void
    {
        $fixture = $this->v10Fixture(withLiveData: false);
        $this->deleteV11Publication($fixture['v11']);
        $this->turnRun($fixture['world'], $fixture['v10'], TurnRun::STATUS_PENDING, false,
            $fixture['world']->current_turn + 1);

        try {
            app(RulesetV11MigrationService::class)->migrate();
            $this->fail('Expected unresolved TurnRun to block before v11 publication.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing v11 migration', $exception->getMessage());
        }

        $this->assertDatabaseMissing('ruleset_versions', ['key' => RulesetV11MigrationService::TARGET_KEY]);
        $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
    }

    public function test_alive_monster_hp_range_mismatch_aborts_before_any_rebind(): void
    {
        $fixture = $this->v10Fixture();
        DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER monster_instance_world_ruleset_guard');
        $fixture['alive']->update(['spawned_max_hp' => 99]);
        DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER monster_instance_world_ruleset_guard');

        try {
            app(RulesetV11MigrationService::class)->migrate();
            $this->fail('Expected invalid alive monster HP to abort v11 migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be mapped exactly', $exception->getMessage());
        }

        $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
        $this->assertSame($fixture['v10']->id, $fixture['alive']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertIntegrityGuardsEnabled();
    }

    #[DataProvider('partialLiveReferenceKinds')]
    public function test_partial_v11_live_monster_or_kill_stat_reference_aborts(string $kind): void
    {
        $fixture = $this->v10Fixture();
        $targetDefinitionId = MonsterDefinition::query()
            ->where('ruleset_version_id', $fixture['v11']->id)
            ->where('key', 'inora')
            ->valueOrFail('id');
        $table = $kind === 'monster' ? 'monster_instances' : 'nation_monster_kill_stats';
        $trigger = $kind === 'monster'
            ? 'monster_instance_world_ruleset_guard'
            : 'nation_monster_kill_stat_guard';
        $model = $kind === 'monster' ? $fixture['alive'] : $fixture['stat'];
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
        $model->update(['monster_definition_id' => $targetDefinitionId]);
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");

        try {
            app(RulesetV11MigrationService::class)->migrate();
            $this->fail("Expected partial v11 {$kind} reference to abort migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial v11 reference', $exception->getMessage());
        }

        $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
        $this->assertIntegrityGuardsEnabled();
    }

    public function test_representative_queue_scale_is_converted_with_exact_counts(): void
    {
        $fixture = $this->v10Fixture();
        $queue = NationCommandQueue::query()->where('nation_id', $fixture['nation']->id)->sole();
        $membershipId = NationMembership::query()->where('nation_id', $fixture['nation']->id)->valueOrFail('id');
        for ($position = 3; $position <= 50; $position++) {
            $this->queueItem(
                $queue,
                $membershipId,
                $fixture['v10'],
                'build_farm',
                'queued',
                $position,
                dechex($position % 16),
            );
        }

        $result = app(RulesetV11MigrationService::class)->migrate();

        $this->assertSame(50, $result->queuedCommandsRebound);
        $this->assertSame(52, $result->requestProvenanceBackfilled);
        $this->assertSame(0, NationCommandQueueItem::query()
            ->where('status', 'queued')
            ->whereHas('definition', fn ($query) => $query
                ->where('ruleset_version_id', '<>', $fixture['v11']->id))
            ->count());
    }

    public function test_null_fingerprint_does_not_allow_contradictory_terminal_provenance(): void
    {
        $fixture = $this->v10Fixture();
        $terminal = collect($fixture['terminal'])->first(
            static fn (NationCommandQueueItem $item): bool => $item->request_fingerprint === null,
        );
        $this->assertInstanceOf(NationCommandQueueItem::class, $terminal);
        $v9Id = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v9')->valueOrFail('id');
        $terminal->update(['request_ruleset_version_id' => $v9Id]);

        try {
            app(RulesetV11MigrationService::class)->migrate();
            $this->fail('Expected contradictory terminal provenance to abort migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contradictory request provenance', $exception->getMessage());
        }

        $this->assertNull($terminal->fresh()->request_fingerprint);
        $this->assertSame($v9Id, $terminal->fresh()->request_ruleset_version_id);
        $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
    }

    public function test_partial_v11_queued_definition_reference_is_not_idempotently_accepted(): void
    {
        $fixture = $this->v10Fixture();
        $sourceDefinitionId = $fixture['queued']->command_definition_id;
        $targetDefinitionId = CommandDefinition::query()->where('ruleset_version_id', $fixture['v11']->id)
            ->where('key', $fixture['queued']->definition->key)->valueOrFail('id');
        DB::statement('ALTER TABLE nation_command_queue_items DISABLE TRIGGER nation_command_queue_items_world_ruleset_match');
        $fixture['queued']->update(['command_definition_id' => $targetDefinitionId]);
        DB::statement('ALTER TABLE nation_command_queue_items ENABLE TRIGGER nation_command_queue_items_world_ruleset_match');

        try {
            try {
                app(RulesetV11MigrationService::class)->migrate();
                $this->fail('Expected partial v11 queue reference to abort migration.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('partial v11 reference', $exception->getMessage());
            }
            $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
        } finally {
            DB::statement('ALTER TABLE nation_command_queue_items DISABLE TRIGGER nation_command_queue_items_world_ruleset_match');
            DB::table('nation_command_queue_items')
                ->where('id', $fixture['queued']->id)
                ->update(['command_definition_id' => $sourceDefinitionId]);
            DB::statement('ALTER TABLE nation_command_queue_items ENABLE TRIGGER nation_command_queue_items_world_ruleset_match');
        }
    }

    public function test_a_second_world_aborts_the_single_world_product_migration_scope(): void
    {
        $fixture = $this->v10Fixture(withLiveData: false);
        World::query()->create([
            'key' => 'unexpected-second-world',
            'name' => 'unexpected-second-world',
            'ruleset_version_id' => $fixture['v10']->id,
            'current_turn' => 1,
        ]);

        try {
            app(RulesetV11MigrationService::class)->migrate();
            $this->fail('Expected a second World to abort v11 migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly the single shared-world', $exception->getMessage());
        }

        $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
    }

    #[DataProvider('invalidQueueStates')]
    public function test_invalid_or_contradictory_queue_state_aborts_without_partial_conversion(string $mutation): void
    {
        $fixture = $this->v10Fixture();
        $item = $fixture['dispatch'];
        $originalFingerprint = $item->request_fingerprint;
        $originalRequestKey = $item->request_key;
        $originalTargetX = $item->target_x;
        if ($mutation === 'fingerprint') {
            DB::statement('ALTER TABLE nation_command_queue_items DROP CONSTRAINT nation_command_queue_items_request_fingerprint_check');
        }
        if ($mutation === 'target') {
            DB::statement('ALTER TABLE nation_command_queue_items ALTER COLUMN target_x DROP NOT NULL');
        }
        if ($mutation === 'request_key') {
            DB::statement(
                'ALTER TABLE nation_command_queue_items ALTER COLUMN request_key TYPE text USING request_key::text',
            );
        }
        match ($mutation) {
            'fingerprint' => $item->update(['request_fingerprint' => 'ABCDEF']),
            'request_key' => $item->update(['request_key' => 'not-a-uuid']),
            'target' => $item->update(['target_x' => null]),
            'parameters' => $item->update(['parameters' => ['target_nation_id' => 'not-an-int']]),
            'quantity' => $item->update(['quantity' => 2]),
            'provenance' => $item->update(['request_ruleset_version_id' => $fixture['v11']->id]),
        };
        $fingerprint = $item->fresh()->request_fingerprint;

        try {
            try {
                app(RulesetV11MigrationService::class)->migrate();
                $this->fail("Expected {$mutation} queue anomaly to abort v11 migration.");
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }

            $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
            $this->assertSame($fixture['v10']->id, $item->fresh()->definition()->value('ruleset_version_id'));
            $this->assertSame($fingerprint, $item->fresh()->request_fingerprint);
        } finally {
            if ($mutation === 'fingerprint') {
                $item->update(['request_fingerprint' => $originalFingerprint]);
                DB::statement(
                    'ALTER TABLE nation_command_queue_items ADD CONSTRAINT nation_command_queue_items_request_fingerprint_check '
                    ."CHECK (request_fingerprint IS NULL OR request_fingerprint ~ '^[0-9a-f]{64}$')",
                );
            }
            if ($mutation === 'target') {
                $item->update(['target_x' => $originalTargetX]);
                DB::statement('ALTER TABLE nation_command_queue_items ALTER COLUMN target_x SET NOT NULL');
            }
            if ($mutation === 'request_key') {
                $item->update(['request_key' => $originalRequestKey]);
                DB::statement(
                    'ALTER TABLE nation_command_queue_items ALTER COLUMN request_key TYPE uuid USING request_key::uuid',
                );
            }
        }
    }

    public function test_injected_failure_after_world_activation_rolls_back_publication_data_and_trigger_state(): void
    {
        $fixture = $this->v10Fixture();
        $fingerprints = NationCommandQueueItem::query()->pluck('request_fingerprint', 'id')->all();
        $terminal = collect($fixture['terminal'])->map->fresh()->map->getAttributes()->all();
        $this->deleteV11Publication($fixture['v11']);

        try {
            app(RulesetV11MigrationService::class)->migrate(static function (string $stage): void {
                if ($stage === RulesetV11MigrationService::FAILURE_AFTER_WORLD_ACTIVATION) {
                    throw new RuntimeException('injected C5 rollback proof');
                }
            });
            $this->fail('Expected injected failure after World activation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected C5 rollback proof', $exception->getMessage());
        }

        $this->assertDatabaseMissing('ruleset_versions', ['key' => RulesetV11MigrationService::TARGET_KEY]);
        $this->assertSame($fixture['v10']->id, $fixture['world']->fresh()->ruleset_version_id);
        $this->assertSame($fixture['v10']->id, $fixture['dispatch']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($fixture['v10']->id, $fixture['alive']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($fixture['v10']->id, $fixture['stat']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertNull($fixture['dispatch']->fresh()->request_ruleset_version_id);
        $this->assertSame($fingerprints, NationCommandQueueItem::query()->pluck('request_fingerprint', 'id')->all());
        $this->assertSame($terminal, collect($fixture['terminal'])->map->fresh()->map->getAttributes()->all());
        $this->assertIntegrityGuardsEnabled();
    }

    /** @return array<string, array{string}> */
    public static function unresolvedStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function migratedTerminalStatuses(): array
    {
        return [
            'completed' => ['completed', 'execution_completed_at'],
            'failed' => ['failed', 'execution_failed_at'],
            'cancelled' => ['cancelled', 'cancelled_at'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidQueueStates(): array
    {
        return [
            'malformed fingerprint' => ['fingerprint'],
            'malformed request key' => ['request_key'],
            'missing target snapshot' => ['target'],
            'malformed parameters' => ['parameters'],
            'historical selector two' => ['quantity'],
            'contradictory provenance' => ['provenance'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function partialLiveReferenceKinds(): array
    {
        return [
            'alive monster' => ['monster'],
            'current kill stat' => ['stat'],
        ];
    }

    /** @return array<string, mixed> */
    private function v10Fixture(bool $withLiveData = true): array
    {
        $world = $this->lightweightWorld();
        $v10 = RulesetVersion::query()->where('key', RulesetV11MigrationService::SOURCE_KEY)->sole();
        $v11 = RulesetVersion::query()->where('key', RulesetV11MigrationService::TARGET_KEY)->sole();
        if (! $withLiveData) {
            $world->update(['ruleset_version_id' => $v10->id]);
            $world = $world->fresh();

            return compact('world', 'v10', 'v11');
        }

        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v11移行元', '移行主');
        $target = app(NationCreationService::class)->create(User::factory()->create(), $world, 'v11移行先', '対象主');
        $world->update(['ruleset_version_id' => $v10->id]);
        $world = $world->fresh();
        config(['hakoniwa.ruleset' => $v10->settings]);
        $space = $this->surfaceMapSpace($world);
        $requestKey = (string) Str::uuid();
        $dispatch = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 1,
            parameters: ['target_nation_id' => $target->id],
        )['item'];
        $dispatch->update(['request_ruleset_version_id' => null]);

        $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->sole();
        $membershipId = NationMembership::query()->where('nation_id', $nation->id)->valueOrFail('id');
        $queued = $this->queueItem($queue, $membershipId, $v10, 'build_farm', 'queued', 2, '1');
        $queued->update(['request_ruleset_version_id' => $v10->id]);
        $terminal = [
            $this->queueItem($queue, $membershipId, $v10, 'build_farm', 'completed', null, '2'),
            $this->queueItem($queue, $membershipId, $v10, 'build_farm', 'failed', null, '3'),
            $this->queueItem($queue, $membershipId, $v10, 'build_farm', 'cancelled', null, '4'),
            $this->queueItem($queue, $membershipId, $v10, 'build_farm', 'completed', null, null),
        ];

        $definition = MonsterDefinition::query()->where('ruleset_version_id', $v10->id)
            ->where('key', 'inora')->sole();
        $alive = $this->monster($world, $definition, 'alive');
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('facility_definition_id')->whereNull('owner_nation_id')->orderBy('id')->firstOrFail();
        $occupancy = MonsterOccupancy::query()->create([
            'monster_instance_id' => $alive->id,
            'map_cell_id' => $cell->id,
        ]);
        $killed = $this->monster($world, $definition, 'killed');
        $removed = $this->monster($world, $definition, 'removed');
        $stat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $definition->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);
        $secretary = Secretary::query()->where('user_id', $user->id)->sole();
        $secretary->forceFill(['equipment_version' => 7])->save();
        config(['hakoniwa.ruleset' => $v11->settings]);

        return compact(
            'world', 'v10', 'v11', 'user', 'nation', 'target', 'dispatch', 'queued', 'terminal',
            'alive', 'occupancy', 'killed', 'removed', 'stat', 'secretary',
        );
    }

    private function queueItem(
        NationCommandQueue $queue,
        int $membershipId,
        RulesetVersion $ruleset,
        string $key,
        string $status,
        ?int $position,
        ?string $fingerprintSeed,
    ): NationCommandQueueItem {
        $definition = CommandDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', $key)->sole();

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $definition->id,
            'request_ruleset_version_id' => null,
            'queue_position' => $position,
            'target_x' => 8,
            'target_y' => 9,
            'quantity' => 1,
            'parameters' => [],
            'status' => $status,
            'queued_by_membership_id' => $membershipId,
            'request_key' => (string) Str::uuid(),
            'request_fingerprint' => $fingerprintSeed === null ? null : str_repeat($fingerprintSeed, 64),
            'queued_at' => now(),
            'failure_metadata' => ['preserve' => $status],
        ]);
    }

    private function monster(World $world, MonsterDefinition $definition, string $state): MonsterInstance
    {
        return MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $state === 'killed' ? 0 : 1,
            'spawned_max_hp' => 1,
            'state' => $state,
            'spawned_target_turn' => 1,
            'version' => 3,
            'removal_reason' => $state === 'alive' ? null : "v11-migration-{$state}",
            'removed_at' => $state === 'alive' ? null : now(),
        ]);
    }

    private function turnRun(World $world, RulesetVersion $ruleset, string $status, bool $dry, int $targetTurn): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => $dry,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function preservedPayloads(array $fixture): array
    {
        return [
            'world_turn' => $fixture['world']->fresh()->current_turn,
            'nation' => Arr::only($fixture['nation']->fresh()->getAttributes(), ['money', 'state', 'registered_turn']),
            'secretary' => $fixture['secretary']->fresh()->getAttributes(),
            'items' => $fixture['secretary']->itemInstances()->orderBy('id')->get()->map->getAttributes()->all(),
            'alive' => Arr::except($fixture['alive']->fresh()->getAttributes(), ['monster_definition_id']),
            'killed' => $fixture['killed']->fresh()->getAttributes(),
            'removed' => $fixture['removed']->fresh()->getAttributes(),
            'stat' => Arr::except($fixture['stat']->fresh()->getAttributes(), ['monster_definition_id']),
            'terminal' => collect($fixture['terminal'])->map(static fn (NationCommandQueueItem $item): array => Arr::except($item->fresh()->getAttributes(), ['request_ruleset_version_id']))->all(),
        ];
    }

    /** @return array<string, int> */
    private function conversionRowCounts(): array
    {
        return collect([
            'rulesets' => 'ruleset_versions',
            'commands' => 'command_definitions',
            'monsters' => 'monster_definitions',
            'queues' => 'nation_command_queue_items',
            'instances' => 'monster_instances',
            'stats' => 'nation_monster_kill_stats',
            'items' => 'secretary_item_instances',
            'events' => 'audit_events',
        ])->mapWithKeys(static fn (string $table, string $key): array => [$key => DB::table($table)->count()])->all();
    }

    private function deleteV11Publication(RulesetVersion $v11): void
    {
        DB::table('command_definitions')->where('ruleset_version_id', $v11->id)->delete();
        DB::table('production_definitions')->where('ruleset_version_id', $v11->id)->delete();
        DB::table('monster_definitions')->where('ruleset_version_id', $v11->id)->delete();
        $v11->delete();
    }

    private function assertIntegrityGuardsEnabled(): void
    {
        foreach ([
            'nation_command_queue_items_world_ruleset_match',
            'monster_instance_world_ruleset_guard',
            'nation_monster_kill_stat_guard',
        ] as $trigger) {
            $this->assertSame('O', DB::table('pg_trigger')->where('tgname', $trigger)->value('tgenabled'), $trigger);
        }
    }
}
