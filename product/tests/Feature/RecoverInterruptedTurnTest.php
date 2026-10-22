<?php

namespace Tests\Feature;

use App\Application\TurnRunner;
use App\Domain\Turn\TurnSeedGenerator;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RecoverInterruptedTurnTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_stuck_running_run_is_released_and_manual_retry_keeps_run_and_seed(): void
    {
        $world = $this->lightweightWorld();
        $run = $this->stuckRun($world);

        $this->artisan('hakoniwa:turn:recover-interrupted', ['--world' => $world->key, '--run' => $run->id])
            ->expectsOutputToContain("seed={$run->random_seed}")
            ->assertSuccessful();
        $this->assertSame(TurnRun::STATUS_RUNNING, $run->fresh()->status);

        $this->artisan('hakoniwa:turn:run', ['--world' => $world->key, '--source' => 'cron'])->assertFailed();

        $this->artisan('hakoniwa:turn:recover-interrupted', [
            '--world' => $world->key, '--run' => $run->id, '--apply' => true,
        ])->assertSuccessful();
        $released = $run->fresh();
        $this->assertSame(TurnRun::STATUS_FAILED, $released->status);
        $this->assertSame('turn_interrupted', $released->failure_code);
        $this->assertSame('running', $released->failure_context['interrupted_status']);
        $this->assertSame(1, $world->fresh()->current_turn);

        $this->artisan('hakoniwa:turn:run', ['--world' => $world->key, '--source' => 'cron'])->assertFailed();
        $completed = app(TurnRunner::class)->run($world->fresh(), source: 'manual');

        $this->assertSame($run->id, $completed->id);
        $this->assertSame($run->random_seed, $completed->random_seed);
        $this->assertSame(2, $completed->attempt_count);
        $this->assertSame(TurnRun::STATUS_COMPLETED, $completed->status);
        $this->assertSame(2, $world->fresh()->current_turn);
    }

    public function test_release_is_refused_while_another_session_holds_the_world_lock(): void
    {
        $world = $this->lightweightWorld();
        $run = $this->stuckRun($world);
        $connectionName = 'pgsql-recover-lock-probe';
        config(["database.connections.{$connectionName}" => config('database.connections.pgsql')]);
        $key = "hakoniwa.turn.world.{$world->id}";

        try {
            DB::connection($connectionName)->selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);

            $this->artisan('hakoniwa:turn:recover-interrupted', [
                '--world' => $world->key, '--run' => $run->id, '--apply' => true,
            ])->assertFailed();
            $this->assertSame(TurnRun::STATUS_RUNNING, $run->fresh()->status);
        } finally {
            DB::connection($connectionName)->selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
            DB::purge($connectionName);
        }
    }

    public function test_only_the_next_turn_pending_or_running_run_can_be_released(): void
    {
        $world = $this->lightweightWorld();
        $completed = app(TurnRunner::class)->run($world);

        $this->artisan('hakoniwa:turn:recover-interrupted', [
            '--world' => $world->key, '--run' => $completed->id, '--apply' => true,
        ])->assertFailed();
        $this->assertSame(TurnRun::STATUS_COMPLETED, $completed->fresh()->status);

        $stale = $this->stuckRun($world->fresh());
        $world->update(['current_turn' => $stale->target_turn]);
        $this->artisan('hakoniwa:turn:recover-interrupted', [
            '--world' => $world->key, '--run' => $stale->id, '--apply' => true,
        ])->assertFailed();
        $this->assertSame(TurnRun::STATUS_RUNNING, $stale->fresh()->status);
    }

    private function stuckRun(World $world): TurnRun
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $target = $world->current_turn + 1;

        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $target,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => app(TurnSeedGenerator::class)->generate($world, $target, $ruleset),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_RUNNING,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
            'started_at' => now(),
        ]);
    }
}
