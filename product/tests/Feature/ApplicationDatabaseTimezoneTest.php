<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Models\Announcement;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\TurnRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class ApplicationDatabaseTimezoneTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_postgresql_session_and_application_timestamp_binding_use_utc(): void
    {
        $this->assertSame('UTC', DB::scalar("SELECT current_setting('TimeZone')"));
        $this->assertEqualsWithDelta(
            microtime(true),
            (float) DB::scalar('SELECT EXTRACT(EPOCH FROM CURRENT_TIMESTAMP)'),
            5.0,
        );

        $instant = CarbonImmutable::parse('2026-08-09T12:34:56Z');
        $id = DB::table('audit_events')->insertGetId([
            'event_type' => 'test.utc_binding',
            'metadata' => '{}',
            'occurred_at' => $instant,
            'created_at' => $instant,
            'updated_at' => $instant,
        ]);

        $this->assertSame(
            '2026-08-09 12:34:56',
            DB::table('audit_events')->where('id', $id)
                ->value(DB::raw("to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')")),
        );
    }

    public function test_repair_changes_only_exact_legacy_jst_anchor_matches_and_is_idempotent(): void
    {
        $legacyId = DB::table('audit_events')->insertGetId([
            'event_type' => 'test.legacy_shift',
            'metadata' => '{}',
            'occurred_at' => DB::raw("'2026-08-09 12:34:56'::timestamp AT TIME ZONE 'Asia/Tokyo'"),
            'created_at' => DB::raw("'2026-08-09 12:34:56'::timestamp"),
            'updated_at' => DB::raw("'2026-08-09 12:34:56'::timestamp"),
        ]);
        $unrelatedId = DB::table('audit_events')->insertGetId([
            'event_type' => 'test.unrelated_timestamp',
            'metadata' => '{}',
            'occurred_at' => DB::raw("'2026-08-09 11:11:11'::timestamp AT TIME ZONE 'UTC'"),
            'created_at' => DB::raw("'2026-08-09 12:34:56'::timestamp"),
            'updated_at' => DB::raw("'2026-08-09 12:34:56'::timestamp"),
        ]);

        $migration = require database_path('migrations/2026_08_09_030000_repair_deterministic_application_timestamps.php');
        $migration->up();
        $migration->up();

        $this->assertSame('2026-08-09 12:34:56', $this->utcTimestamp($legacyId));
        $this->assertSame('2026-08-09 11:11:11', $this->utcTimestamp($unrelatedId));
    }

    public function test_repair_covers_every_deterministic_series_and_preserves_predicate_mismatches_and_exclusions(): void
    {
        $world = $this->lightweightWorld();
        $mapSpace = $this->surfaceMapSpace($world);
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '時刻修復国', '試験島主');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->firstOrFail();

        $auditId = DB::table('audit_events')->insertGetId([
            'event_type' => 'test.repair_all_series', 'metadata' => '{}',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $moderationId = DB::table('moderation_records')->insertGetId([
            'operator_identifier' => 'test-operator', 'category' => 'repair',
            'target_type' => 'nation', 'target_id' => $nation->id, 'summary' => 'repair fixture',
            'metadata' => '{}', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $chunkId = (int) DB::table('map_chunks')->where('map_space_id', $mapSpace->id)->value('id');
        $generationId = (int) DB::table('world_generation_runs')->where('map_space_id', $mapSpace->id)->value('id');

        $queueItems = [];
        foreach (range(1, 9) as $expectedVersion) {
            $queueItems[] = app(CommandQueueService::class)->add(
                user: $user,
                nation: $nation,
                mapSpace: $mapSpace,
                commandKey: 'land_clear',
                targetX: $target->x,
                targetY: $target->y,
                requestKey: (string) Str::uuid(),
                expectedVersion: $expectedVersion,
            )['item'];
        }

        $completedRun = $this->turnRunFixture($world->id, $world->ruleset_version_id, 10, TurnRun::STATUS_COMPLETED);
        $pendingRun = $this->turnRunFixture($world->id, $world->ruleset_version_id, 11, TurnRun::STATUS_PENDING);
        $dryRun = $this->turnRunFixture($world->id, $world->ruleset_version_id, 12, TurnRun::STATUS_DRY_RUN);
        $monsterDefinition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id, 'monster_definition_id' => $monsterDefinition->id,
            'current_hp' => 0, 'spawned_max_hp' => $monsterDefinition->base_hp,
            'state' => 'removed', 'spawned_target_turn' => 1, 'version' => 1,
            'removal_reason' => 'test', 'removed_at' => now(),
        ]);

        $this->setLegacyTimestamp('audit_events', $auditId, 'occurred_at', 'created_at');
        $this->setLegacyTimestamp('moderation_records', $moderationId, 'occurred_at', 'created_at');
        $this->setLegacyTimestamp('map_chunks', $chunkId, 'generated_at', 'created_at');
        DB::table('world_generation_runs')->where('id', $generationId)->update(['status' => 'completed']);
        $this->setLegacyTimestamp('world_generation_runs', $generationId, 'completed_at', 'created_at');
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[0]->id, 'queued_at', 'created_at');

        DB::table('nation_command_queue_items')->where('id', $queueItems[1]->id)->update(['status' => 'cancelled']);
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[1]->id, 'cancelled_at', 'updated_at');
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[2]->id, 'cancelled_at', 'updated_at');
        DB::table('nation_command_queue_items')->where('id', $queueItems[3]->id)->update(['status' => 'completed']);
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[3]->id, 'execution_completed_at', 'updated_at');
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[4]->id, 'execution_completed_at', 'updated_at');
        DB::table('nation_command_queue_items')->where('id', $queueItems[5]->id)->update(['status' => 'failed']);
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[5]->id, 'execution_failed_at', 'updated_at');
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[6]->id, 'execution_failed_at', 'updated_at');
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[7]->id, 'execution_started_at', 'updated_at');
        DB::table('nation_command_queue_items')->where('id', $queueItems[8]->id)->update(['status' => 'cancelled']);
        $this->setLegacyTimestamp('nation_command_queue_items', $queueItems[8]->id, 'execution_completed_at', 'updated_at');

        $this->setLegacyTimestamp('turn_runs', $completedRun->id, 'completed_at', 'updated_at');
        $this->setLegacyTimestamp('turn_runs', $pendingRun->id, 'completed_at', 'updated_at');
        $this->setLegacyTimestamp('turn_runs', $pendingRun->id, 'started_at', 'updated_at');
        $this->setLegacyTimestamp('turn_runs', $dryRun->id, 'completed_at', 'updated_at');
        $this->setLegacyTimestamp('monster_instances', $monster->id, 'removed_at', 'updated_at');

        $announcement = Announcement::query()->create(['title' => '除外告知', 'body' => '推測修復しない']);
        $this->setPlainTimestamp('announcements', $announcement->id, 'created_at');
        $killStatId = DB::table('nation_monster_kill_stats')->insertGetId([
            'world_id' => $world->id, 'nation_id' => $nation->id,
            'monster_definition_id' => $monsterDefinition->id, 'kill_count' => 1,
            'first_killed_turn' => 1, 'last_killed_turn' => 1, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $killStatCreatedAt = $this->plainColumn('nation_monster_kill_stats', $killStatId, 'created_at');

        $migration = require database_path('migrations/2026_08_09_030000_repair_deterministic_application_timestamps.php');
        $migration->up();
        $migration->up();

        foreach ([
            ['audit_events', $auditId, 'occurred_at'],
            ['moderation_records', $moderationId, 'occurred_at'],
            ['map_chunks', $chunkId, 'generated_at'],
            ['world_generation_runs', $generationId, 'completed_at'],
            ['nation_command_queue_items', $queueItems[0]->id, 'queued_at'],
            ['nation_command_queue_items', $queueItems[1]->id, 'cancelled_at'],
            ['nation_command_queue_items', $queueItems[3]->id, 'execution_completed_at'],
            ['nation_command_queue_items', $queueItems[4]->id, 'execution_completed_at'],
            ['nation_command_queue_items', $queueItems[5]->id, 'execution_failed_at'],
            ['turn_runs', $completedRun->id, 'completed_at'],
            ['turn_runs', $dryRun->id, 'completed_at'],
            ['monster_instances', $monster->id, 'removed_at'],
        ] as [$table, $id, $column]) {
            $this->assertSame('2026-08-09 12:34:56', $this->utcColumn($table, $id, $column), "{$table}.{$column}");
        }

        foreach ([
            ['nation_command_queue_items', $queueItems[2]->id, 'cancelled_at'],
            ['nation_command_queue_items', $queueItems[6]->id, 'execution_failed_at'],
            ['nation_command_queue_items', $queueItems[7]->id, 'execution_started_at'],
            ['nation_command_queue_items', $queueItems[8]->id, 'execution_completed_at'],
            ['turn_runs', $pendingRun->id, 'completed_at'],
            ['turn_runs', $pendingRun->id, 'started_at'],
        ] as [$table, $id, $column]) {
            $this->assertSame('2026-08-09 03:34:56', $this->utcColumn($table, $id, $column), "{$table}.{$column}");
        }
        $this->assertSame('2026-08-09 12:34:56', $this->plainColumn('announcements', $announcement->id, 'created_at'));
        $this->assertSame($killStatCreatedAt, $this->plainColumn('nation_monster_kill_stats', $killStatId, 'created_at'));
    }

    public function test_eloquent_announcement_and_turn_run_round_trip_the_same_utc_instant(): void
    {
        $instant = CarbonImmutable::parse('2026-08-09T12:34:56Z');
        Carbon::setTestNow($instant);

        $announcement = Announcement::query()->create(['title' => 'UTC確認', 'body' => '同一instant']);
        $this->assertSame($instant->getTimestamp(), $announcement->fresh()->created_at?->getTimestamp());
        $this->getJson("/api/v1/public/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('data.created_at', '2026-08-09T12:34:56+00:00');

        $world = $this->lightweightWorld();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => hash('sha256', 'utc-round-trip'),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_COMPLETED,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'started_at' => now(),
            'completed_at' => now(),
            'failure_context' => [],
        ]);
        $this->assertSame($instant->getTimestamp(), $run->fresh()->completed_at?->getTimestamp());
        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.last_successful_turn_at', '2026-08-09T12:34:56+00:00');
    }

    private function utcTimestamp(int $id): string
    {
        return (string) DB::table('audit_events')->where('id', $id)
            ->value(DB::raw("to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')"));
    }

    private function setLegacyTimestamp(string $table, int $id, string $column, string $anchor): void
    {
        DB::table($table)->where('id', $id)->update([
            $column => DB::raw("'2026-08-09 12:34:56'::timestamp AT TIME ZONE 'Asia/Tokyo'"),
            $anchor => DB::raw("'2026-08-09 12:34:56'::timestamp"),
        ]);
    }

    private function setPlainTimestamp(string $table, int $id, string $column): void
    {
        DB::table($table)->where('id', $id)->update([
            $column => DB::raw("'2026-08-09 12:34:56'::timestamp"),
        ]);
    }

    private function utcColumn(string $table, int $id, string $column): string
    {
        return (string) DB::table($table)->where('id', $id)
            ->value(DB::raw("to_char({$column} AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')"));
    }

    private function plainColumn(string $table, int $id, string $column): string
    {
        return (string) DB::table($table)->where('id', $id)
            ->value(DB::raw("to_char({$column}, 'YYYY-MM-DD HH24:MI:SS')"));
    }

    private function turnRunFixture(int $worldId, int $rulesetVersionId, int $targetTurn, string $status): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $worldId, 'target_turn' => $targetTurn,
            'ruleset_version_id' => $rulesetVersionId,
            'random_seed' => hash('sha256', "timestamp-repair-{$targetTurn}"),
            'source' => 'manual', 'is_dry_run' => true, 'status' => $status,
            'attempt_count' => 1, 'pipeline' => [], 'phase_results' => [],
            'started_at' => now(), 'completed_at' => now(), 'failure_context' => [],
        ]);
    }
}
