<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorldTurnStartingNumberMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_zero_turn_world_with_only_dry_runs_moves_to_one_and_schema_default_is_one(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $world->update(['current_turn' => 0]);
        $this->createRun($world, TurnRun::STATUS_DRY_RUN, true);
        $migration = require database_path('migrations/2026_08_01_000000_start_world_turns_at_one.php');

        $migration->up();

        $this->assertSame(1, $world->fresh()->current_turn);
        $insertedId = DB::table('worlds')->insertGetId([
            'key' => 'schema-default-world',
            'name' => 'Schema default World',
            'ruleset_version_id' => $world->ruleset_version_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(1, (int) DB::table('worlds')->where('id', $insertedId)->value('current_turn'));
        $this->assertSame(1, (new World)->current_turn);
    }

    #[DataProvider('blockingStatusProvider')]
    public function test_migration_refuses_zero_turn_world_with_target_turn_one_non_dry_run(string $status): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $world->update(['current_turn' => 0]);
        $run = $this->createRun($world, $status, false);
        $migration = require database_path('migrations/2026_08_01_000000_start_world_turns_at_one.php');

        try {
            $migration->up();
            $this->fail('Migration renumbered a World with non-dry-run turn history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("world {$world->id}", $exception->getMessage());
            $this->assertStringContainsString("run {$run->id}", $exception->getMessage());
            $this->assertStringContainsString('target_turn=1', $exception->getMessage());
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertSame(0, $world->fresh()->current_turn);
        $this->assertSame($status, $run->fresh()->status);
        $this->assertSame(1, $run->target_turn);
    }

    /** @return array<string, array{string}> */
    public static function blockingStatusProvider(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'failed' => [TurnRun::STATUS_FAILED],
            'completed' => [TurnRun::STATUS_COMPLETED],
        ];
    }

    public function test_one_based_turn_migration_is_forward_only(): void
    {
        $migration = require database_path('migrations/2026_08_01_000000_start_world_turns_at_one.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forward-only');
        $migration->down();
    }

    public function test_migration_fails_closed_while_the_world_turn_lock_is_held(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $world->update(['current_turn' => 0]);
        $connectionName = 'pgsql-turn-number-migration-probe';
        config(["database.connections.{$connectionName}" => config('database.connections.pgsql')]);
        $lockKey = "hakoniwa.turn.world.{$world->id}";
        DB::connection($connectionName)->selectOne(
            'SELECT pg_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$lockKey],
        );

        try {
            $migration = require database_path('migrations/2026_08_01_000000_start_world_turns_at_one.php');
            $migration->up();
            $this->fail('Migration proceeded while the World turn lock was held.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("world {$world->id}", $exception->getMessage());
            $this->assertStringContainsString('advisory lock', $exception->getMessage());
        } finally {
            DB::connection($connectionName)->selectOne(
                'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
                [$lockKey],
            );
            DB::purge($connectionName);
        }

        $this->assertSame(0, $world->fresh()->current_turn);
    }

    private function createRun(World $world, string $status, bool $dryRun): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('b', 64),
            'source' => 'manual',
            'is_dry_run' => $dryRun,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'started_at' => now(),
            'completed_at' => $status === TurnRun::STATUS_PENDING ? null : now(),
            'failure_context' => [],
        ]);
    }
}
