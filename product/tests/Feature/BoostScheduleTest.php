<?php

namespace Tests\Feature;

use App\Application\BoostScheduleService;
use App\Application\CurrentDatabaseBaseline;
use App\Application\TurnRunner;
use App\Application\TurnScheduleStatus;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\World\WorldMutationLock;
use App\Models\AuthIdentity;
use App\Models\BoostOccurrence;
use App\Models\TurnRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesBoostSchedules;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class BoostScheduleTest extends TestCase
{
    use CreatesBoostSchedules;
    use CreatesTestWorlds;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_counts_exact_odd_slots_and_stale_manual_compensation_requires_reconfirmation(): void
    {
        Carbon::setTestNow('2026-11-01T12:30:00+09:00');
        $world = $this->lightweightWorld();
        $world->update(['next_regular_turn_at' => CarbonImmutable::parse('2026-10-31T18:00:00+09:00')->utc()]); // Ten unpaid even-hour slots.
        $admin = $this->admin();
        $path = "/api/v1/admin/worlds/{$world->id}/boosts";
        $input = ['kind' => 'turn_acceleration', 'title' => '停止補填', 'starts_at' => '2026-11-02T09:30:00+09:00'];
        $this->actingAs(User::factory()->create())->postJson($path.'/preview', $input)->assertForbidden();
        $preview = $this->actingAs($admin)->postJson($path.'/preview', $input)->assertOk()
            ->assertJsonPath('data.uncompensated_turns', 10)->assertJsonPath('data.windows.0.additional_slots', 10)->json('data');
        $this->assertEquals(20, $preview['duration_hours']);
        $this->assertDatabaseCount('boost_occurrences', 0);
        app(TurnRunner::class)->run($world);
        $this->postJson($path.'/reserve', ['token' => $preview['token']])->assertConflict();
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path.'/reserve', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.duplicate', false);
        $this->postJson($path.'/reserve', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertDatabaseCount('boost_occurrences', 1);
        $service = app(BoostScheduleService::class);
        $this->assertSame(1, $service->additionalSlots(CarbonImmutable::parse('2026-11-01T01:00:00+09:00'), CarbonImmutable::parse('2026-11-01T03:00:00+09:00')));
    }

    public function test_finite_recurrence_overlap_end_exclusive_public_boundary_and_cancellation(): void
    {
        Carbon::setTestNow('2026-11-01T08:00:00+09:00');
        $world = $this->lightweightWorld();
        $path = "/api/v1/admin/worlds/{$world->id}/boosts";
        $this->actingAs($this->admin());
        $input = ['kind' => 'hunting_ground', 'title' => '狩場祭', 'starts_at' => '2026-11-01T09:00:00+09:00',
            'ends_at' => '2026-11-01T11:00:00+09:00', 'repeat' => 'daily', 'repeat_count' => 3];
        $first = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path.'/reserve', ['token' => $first['token']])->assertOk();
        $this->postJson($path.'/reserve', ['token' => $first['token']])->assertOk();
        $this->assertDatabaseCount('boost_occurrences', 3);
        $this->postJson($path.'/preview', $input)->assertConflict();
        $this->postJson($path.'/preview', [...$input, 'starts_at' => '2026-11-01T11:00:00+09:00', 'ends_at' => '2026-11-01T12:00:00+09:00'])
            ->assertOk();
        $public = "/api/v1/public/worlds/{$world->id}/boosts";
        Carbon::setTestNow('2026-11-01T09:00:00+09:00');
        $this->getJson($public)->assertOk()->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.multiplier', 2)->assertJsonMissingPath('data.0.created_by');
        Carbon::setTestNow('2026-11-01T11:00:00+09:00');
        $this->getJson($public)->assertOk()->assertJsonCount(1, 'data');
        $second = BoostOccurrence::query()->orderBy('id')->skip(1)->firstOrFail();
        $this->postJson($path."/{$second->id}/cancel", ['future_series' => true])->assertOk();
        $this->postJson($path."/{$second->id}/cancel", ['future_series' => true])->assertOk();
        $this->getJson($public)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_worker_cron_and_manual_share_current_slot_and_restart_does_not_repeat_it(): void
    {
        Carbon::setTestNow('2026-11-01T01:00:00+09:00');
        $world = $this->lightweightWorld();
        $world->update(['next_regular_turn_at' => CarbonImmutable::parse('2026-10-31T22:00:00+09:00')->utc()]);
        $this->boostOccurrence('turn_acceleration', $world);
        $this->artisan('hakoniwa:turn:work', ['--once' => true])->assertSuccessful();
        $this->assertSame(2, $world->refresh()->current_turn, 'first worker: '.Artisan::output());
        $this->assertSame('2026-10-31T15:00:00+00:00', $world->next_regular_turn_at->utc()->toIso8601String()); // 00:00 JST, one debt repaid.
        $this->assertSame(CarbonImmutable::parse('2026-11-01T02:00:00+09:00')->utc()->toIso8601String(),
            app(TurnScheduleStatus::class)->forWorld($world)['next_scheduled_turn_at']);
        $this->app->forgetInstance(TurnRunner::class);
        $this->artisan('hakoniwa:turn:work', ['--once' => true])->assertSuccessful();
        $this->artisan('hakoniwa:turn:run', ['--source' => 'cron'])->assertSuccessful();
        $this->assertDatabaseCount('turn_execution_slots', 1);
        $this->assertNotNull(DB::table('turn_execution_slots')->value('completed_at'));
        $this->actingAs($this->admin())->postJson("/api/v1/admin/worlds/{$world->id}/turn-attempt", ['target_turn' => 2])
            ->assertOk()->assertJsonPath('data.duplicate', true);
        Carbon::setTestNow('2026-11-01T02:00:00+09:00');
        $this->artisan('hakoniwa:turn:work', ['--once' => true])->assertSuccessful();
        $this->assertSame(3, $world->refresh()->current_turn, 'next even worker: '.Artisan::output());
        $this->assertDatabaseCount('turn_execution_slots', 2);
        $this->assertSame(CarbonImmutable::parse('2026-11-01T03:00:00+09:00')->utc()->toIso8601String(),
            app(TurnScheduleStatus::class)->forWorld($world)['next_scheduled_turn_at']);
    }

    public function test_old_slots_no_catchup_no_advance_after_compensation_and_failed_run_never_auto_retries(): void
    {
        Carbon::setTestNow('2026-11-01T01:16:00+09:00');
        $world = $this->lightweightWorld();
        $world->update(['next_regular_turn_at' => CarbonImmutable::parse('2026-10-31T22:00:00+09:00')->utc()]);
        $this->boostOccurrence('turn_acceleration', $world)->update(['starts_at' => CarbonImmutable::parse('2026-11-01T01:00:00+09:00')->utc()]);
        $this->assertNull(app(TurnRunner::class)->run($world, source: 'scheduler'));
        $this->assertSame(1, $world->refresh()->current_turn);
        $world->update(['next_regular_turn_at' => CarbonImmutable::parse('2026-11-01T04:00:00+09:00')->utc()]);
        Carbon::setTestNow('2026-11-01T03:00:00+09:00');
        $this->assertNull(app(TurnRunner::class)->run($world, source: 'scheduler'));
        TurnRun::query()->create(['world_id' => $world->id, 'target_turn' => 2, 'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('a', 64), 'source' => 'cron', 'is_dry_run' => false, 'status' => 'failed', 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => []]);
        $this->expectException(\DomainException::class);
        app(TurnRunner::class)->run($world, source: 'scheduler');
    }

    public function test_forward_migration_initializes_the_old_calendar_without_changing_turn_progress(): void
    {
        $world = $this->lightweightWorld();
        $origin = CarbonImmutable::parse('2026-11-01T00:00:00+09:00')->utc();
        $world->update(['turn_schedule_origin_at' => $origin, 'current_turn' => 11]);
        // Reconstruct only the immediately preceding schema inside this test's PostgreSQL rollback.
        Schema::drop('turn_execution_slots');
        Schema::drop('boost_occurrences');
        Schema::drop('boost_series');
        DB::statement('ALTER TABLE worlds DROP COLUMN next_regular_turn_at');
        $migration = require database_path('migrations/2026_10_22_000000_add_boost_schedules_and_turn_slots.php');
        $migration->up();
        $world->refresh();
        $this->assertSame(11, $world->current_turn);
        $this->assertTrue($origin->addHours(22)->equalTo($world->next_regular_turn_at));
        $this->assertDatabaseCount('turn_execution_slots', 0);
        app(CurrentDatabaseBaseline::class)->assertExisting();
    }

    public function test_maintenance_and_end_boundary_do_not_consume_a_due_slot(): void
    {
        Carbon::setTestNow('2026-11-01T01:00:00+09:00');
        $world = $this->lightweightWorld();
        $world->update(['next_regular_turn_at' => CarbonImmutable::now()->subHours(3)]);
        $event = $this->boostOccurrence('turn_acceleration', $world);
        $event->update(['ends_at' => CarbonImmutable::now()->addHours(2)]);
        $maintenance = app()->maintenanceMode();
        $maintenance->activate(['time' => now()->timestamp]);
        try {
            $this->assertNull(app(TurnRunner::class)->run($world, source: 'scheduler'));
            $this->assertSame(1, $world->refresh()->current_turn);
            $this->assertDatabaseCount('turn_execution_slots', 0);
        } finally {
            $maintenance->deactivate();
        }
        Carbon::setTestNow('2026-11-01T03:00:00+09:00');
        $this->assertNull(app(TurnRunner::class)->run($world, source: 'scheduler'));
        $this->assertDatabaseCount('turn_execution_slots', 0);
    }

    public function test_reserve_and_cancel_world_lock_conflicts_are_retryable_http_conflicts(): void
    {
        $world = $this->lightweightWorld();
        $this->actingAs($this->admin());
        $event = $this->boostOccurrence('hunting_ground', $world);
        $path = "/api/v1/admin/worlds/{$world->id}/boosts";
        $token = $this->postJson($path.'/preview', ['kind' => 'hunting_ground', 'title' => 'Next festival',
            'starts_at' => CarbonImmutable::now()->addDays(2)->startOfSecond()->toIso8601String(),
            'ends_at' => CarbonImmutable::now()->addDays(2)->addHour()->startOfSecond()->toIso8601String()])->assertOk()->json('data.token');
        $lock = \Mockery::mock(WorldMutationLock::class);
        $lock->shouldReceive('acquire')->twice()->andThrow(new TurnAlreadyRunningException('World busy'));
        $this->app->instance(WorldMutationLock::class, $lock);
        $this->postJson($path.'/reserve', ['token' => $token])->assertConflict();
        $this->postJson($path."/{$event->id}/cancel")->assertConflict();
        $this->assertDatabaseCount('boost_series', 1);
        $this->assertNull($event->refresh()->cancelled_at);
    }

    private function admin(): User
    {
        config(['hakoniwa.admin.discord_user_id' => 'boost-admin']);
        $actor = User::factory()->create();
        AuthIdentity::query()->create(['user_id' => $actor->id, 'provider' => 'discord', 'provider_user_id' => 'boost-admin', 'display_name' => '管理者']);

        return $actor;
    }

    public function test_failed_and_blocked_slot_allow_explicit_admin_retry_during_the_same_grace_window(): void
    {
        Carbon::setTestNow('2026-11-01T02:01:00+09:00');
        $world = $this->lightweightWorld();
        $world->update(['next_regular_turn_at' => CarbonImmutable::now()->startOfHour()]);
        $run = TurnRun::query()->create(['world_id' => $world->id, 'target_turn' => 2, 'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('a', 64), 'source' => 'cron', 'is_dry_run' => false, 'status' => 'failed', 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => []]);
        DB::table('turn_execution_slots')->insert(['world_id' => $world->id, 'turn_run_id' => $run->id,
            'scheduled_at' => CarbonImmutable::now()->startOfHour(), 'kind' => 'regular', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin());
        $path = "/api/v1/admin/worlds/{$world->id}";
        $this->getJson($path.'/turn-status')->assertOk()->assertJsonPath('data.can_attempt', true);
        $run->update(['status' => 'blocked']);
        $this->getJson($path.'/turn-status')->assertOk()->assertJsonPath('data.can_attempt', true);
        $this->postJson($path.'/turn-attempt', ['target_turn' => 2])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(2, $run->refresh()->attempt_count);
        $this->assertDatabaseCount('turn_execution_slots', 1);
    }

    public function test_testing_world_reset_removes_its_boost_schedule_and_slots_but_preserves_users(): void
    {
        Carbon::setTestNow('2026-11-01T01:00:00+09:00');
        $world = $this->lightweightWorld();
        $world->update(['next_regular_turn_at' => CarbonImmutable::now()->subHours(3)]);
        $event = $this->boostOccurrence('turn_acceleration', $world);
        // A minimal completed slot avoids unrelated NPC market generation in a full gameplay turn.
        $run = TurnRun::query()->create(['world_id' => $world->id, 'target_turn' => 2, 'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('a', 64), 'source' => 'scheduler', 'is_dry_run' => false, 'status' => 'completed', 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => [], 'started_at' => now(), 'completed_at' => now()]);
        $world->update(['current_turn' => 2]);
        DB::table('turn_execution_slots')->insert(['world_id' => $world->id, 'turn_run_id' => $run->id,
            'scheduled_at' => CarbonImmutable::now()->startOfHour(), 'kind' => 'turn_acceleration', 'boost_occurrence_id' => $event->id,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertDatabaseCount('turn_execution_slots', 1);
        $users = User::query()->count();
        $exit = Artisan::call('hakoniwa:world:reset', ['--world' => $world->key,
            '--profile' => 'debug-32x32', '--confirm' => 'RESET-'.$world->key]);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertDatabaseCount('boost_series', 0);
        $this->assertDatabaseCount('boost_occurrences', 0);
        $this->assertDatabaseCount('turn_execution_slots', 0);
        $this->assertSame($users, User::query()->count());
    }
}
