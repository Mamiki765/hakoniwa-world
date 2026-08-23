<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\TurnRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class ApplicationDatabaseTimezoneTest extends TestCase
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
}
