<?php

namespace Tests\Feature;

use App\Models\TurnRun;
use App\Models\World;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class TurnScheduleStatusTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_uses_jst_even_hours_on_a_utc_application_clock(): void
    {
        config(['app.timezone' => 'UTC', 'hakoniwa.turn_schedule.grace_minutes' => 15]);
        $world = $this->lightweightWorld();
        $this->turnRun($world->id, 1, TurnRun::STATUS_COMPLETED, '2026-08-09T13:00:00Z'); // 22:00 JST
        Carbon::setTestNow('2026-08-09T15:00:00Z'); // 00:00 JST on the next date

        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.last_successful_turn_at', '2026-08-09T13:00:00+00:00')
            ->assertJsonPath('data.next_scheduled_turn_at', '2026-08-09T15:00:00+00:00')
            ->assertJsonPath('data.turn_status', 'normal')
            ->assertJsonPath('data.turn_schedule_timezone', 'Asia/Tokyo');
    }

    public function test_missing_cron_becomes_delayed_only_after_the_configured_grace(): void
    {
        config(['hakoniwa.turn_schedule.grace_minutes' => 15]);
        $world = $this->lightweightWorld();
        $this->turnRun($world->id, 1, TurnRun::STATUS_COMPLETED, '2026-08-09T13:01:00Z');

        Carbon::setTestNow('2026-08-09T15:15:00Z');
        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()->assertJsonPath('data.turn_status', 'normal');

        Carbon::setTestNow('2026-08-09T15:15:01Z');
        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()->assertJsonPath('data.turn_status', 'delayed');
    }

    public function test_failed_and_blocked_next_runs_stop_countdown_without_leaking_diagnostics(): void
    {
        $world = $this->lightweightWorld();
        Carbon::setTestNow('2026-08-09T18:00:00Z');
        $run = $this->turnRun($world->id, 2, TurnRun::STATUS_FAILED, null, [
            'failure_code' => 'private_code',
            'failure_message' => 'private message',
            'failure_context' => ['exception' => 'private class'],
        ]);

        $failed = $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()->assertJsonPath('data.turn_status', 'failed');
        foreach (['private_code', 'private message', 'private class', $run->random_seed] as $secret) {
            $this->assertStringNotContainsString($secret, $failed->getContent());
        }
        $failed->assertJsonMissingPath('data.failure_code')
            ->assertJsonMissingPath('data.failure_message')
            ->assertJsonMissingPath('data.failure_context')
            ->assertJsonMissingPath('data.ruleset_version_id')
            ->assertJsonMissingPath('data.random_seed');

        $run->update(['status' => TurnRun::STATUS_BLOCKED]);
        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()->assertJsonPath('data.turn_status', 'blocked');
    }

    public function test_jst_schedule_has_the_same_plus_nine_offset_in_winter_and_summer(): void
    {
        $world = $this->lightweightWorld();
        foreach (['2026-01-10T15:00:00Z', '2026-08-10T15:00:00Z'] as $utcMidnightJst) {
            Carbon::setTestNow($utcMidnightJst);
            $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
                ->assertOk()
                ->assertJsonPath('data.next_scheduled_turn_at', CarbonImmutable::parse($utcMidnightJst)->toIso8601String());
        }
    }

    /** @param array<string, mixed> $extra */
    private function turnRun(int $worldId, int $targetTurn, string $status, ?string $completedAt, array $extra = []): TurnRun
    {
        $world = World::query()->findOrFail($worldId);

        $run = TurnRun::query()->create([
            'world_id' => $worldId,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => hash('sha256', "schedule-{$worldId}-{$targetTurn}"),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'started_at' => null,
            'completed_at' => null,
            'failure_context' => [],
            ...$extra,
        ]);
        if ($completedAt !== null) {
            DB::table('turn_runs')->where('id', $run->id)->update([
                'started_at' => $completedAt,
                'completed_at' => $completedAt,
            ]);
        }

        return $run->refresh();
    }
}
