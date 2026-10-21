<?php

namespace Tests\Feature;

use App\Application\BoostScheduleService;
use App\Application\TurnRunner;
use App\Models\AuthIdentity;
use App\Models\BoostOccurrence;
use App\Models\TurnRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $world->update(['next_regular_turn_at' => '2026-10-31T18:00:00+09:00']); // Ten unpaid even-hour slots.
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
        $world->update(['next_regular_turn_at' => '2026-10-31T22:00:00+09:00']);
        $this->boostOccurrence('turn_acceleration', $world);
        $this->artisan('hakoniwa:turn:work', ['--once' => true])->assertSuccessful();
        $this->assertSame(2, $world->refresh()->current_turn, 'first worker: '.\Illuminate\Support\Facades\Artisan::output());
        $this->assertSame('2026-10-31T15:00:00+00:00', $world->next_regular_turn_at->utc()->toIso8601String()); // 00:00 JST, one debt repaid.
        $this->app->forgetInstance(TurnRunner::class);
        $this->artisan('hakoniwa:turn:work', ['--once' => true])->assertSuccessful();
        $this->artisan('hakoniwa:turn:run', ['--source' => 'cron'])->assertSuccessful();
        $this->assertDatabaseCount('turn_execution_slots', 1);
        $this->assertNotNull(DB::table('turn_execution_slots')->value('completed_at'));
        $this->actingAs($this->admin())->postJson("/api/v1/admin/worlds/{$world->id}/turn-attempt", ['target_turn' => 2])
            ->assertOk()->assertJsonPath('data.duplicate', true);
        Carbon::setTestNow('2026-11-01T02:00:00+09:00');
        $this->artisan('hakoniwa:turn:work', ['--once' => true])->assertSuccessful();
        $this->assertSame(3, $world->refresh()->current_turn, 'next even worker: '.\Illuminate\Support\Facades\Artisan::output());
        $this->assertDatabaseCount('turn_execution_slots', 2);
    }

    public function test_old_slots_no_catchup_no_advance_after_compensation_and_failed_run_never_auto_retries(): void
    {
        Carbon::setTestNow('2026-11-01T01:16:00+09:00');
        $world = $this->lightweightWorld();
        $world->update(['next_regular_turn_at' => '2026-10-31T22:00:00+09:00']);
        $this->boostOccurrence('turn_acceleration', $world)->update(['starts_at' => '2026-11-01T01:00:00+09:00']);
        $this->assertNull(app(TurnRunner::class)->run($world, source: 'scheduler'));
        $this->assertSame(1, $world->refresh()->current_turn);
        $world->update(['next_regular_turn_at' => '2026-11-01T04:00:00+09:00']);
        Carbon::setTestNow('2026-11-01T03:00:00+09:00');
        $this->assertNull(app(TurnRunner::class)->run($world, source: 'scheduler'));
        TurnRun::query()->create(['world_id' => $world->id, 'target_turn' => 2, 'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('a', 64), 'source' => 'cron', 'is_dry_run' => false, 'status' => 'failed', 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => []]);
        $this->expectException(\DomainException::class);
        app(TurnRunner::class)->run($world, source: 'scheduler');
    }

    private function admin(): User
    {
        config(['hakoniwa.admin.discord_user_id' => 'boost-admin']);
        $actor = User::factory()->create();
        AuthIdentity::query()->create(['user_id' => $actor->id, 'provider' => 'discord', 'provider_user_id' => 'boost-admin', 'display_name' => '管理者']);

        return $actor;
    }
}
