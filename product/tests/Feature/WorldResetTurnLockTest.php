<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\CommandQueueService;
use App\Application\ExternalIdentityData;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Domain\Turn\ScaffoldTurnPhase;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\WorldTurnLock;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorldResetTurnLockTest extends TestCase
{
    use DatabaseMigrations;

    private const PROBE_CONNECTION = 'pgsql-world-reset-turn-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific World reset/turn exclusion tests.');
        }

        config([
            'database.connections.'.self::PROBE_CONNECTION => config(
                'database.connections.'.$this->primaryConnection,
            ),
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->primaryConnection);
        foreach ([$this->primaryConnection, self::PROBE_CONNECTION] as $connectionName) {
            $connection = DB::connection($connectionName);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->selectOne('SELECT pg_advisory_unlock_all()');
        }
        DB::purge(self::PROBE_CONNECTION);

        parent::tearDown();
    }

    public function test_turn_lock_blocks_real_and_dry_reset_without_mutating_either_world(): void
    {
        [$world, $queueItem, $run, $user] = $this->worldWithQueueAndRun();
        $otherWorld = World::query()->create([
            'key' => 'reset-lock-other-world',
            'name' => 'Reset lock other World',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 0,
        ]);
        $otherRun = $this->createTurnRun($otherWorld, 1);
        $this->acquireAdvisoryLock(self::PROBE_CONNECTION, $world);

        try {
            $this->assertSame(1, Artisan::call('hakoniwa:world:reset', [
                '--world' => $world->key,
                '--confirm' => 'RESET-'.$world->key,
            ]));
            $this->assertStringContainsString('currently processing a turn', Artisan::output());
            $this->assertSame(1, Artisan::call('hakoniwa:world:reset', [
                '--world' => $world->key,
                '--dry-run' => true,
            ]));
            $this->assertStringContainsString('currently processing a turn', Artisan::output());

            $this->assertNotNull(World::query()->find($world->id));
            $this->assertNotNull(TurnRun::query()->find($run->id));
            $this->assertNotNull(NationCommandQueueItem::query()->find($queueItem->id));
            $this->assertNotNull(User::query()->find($user->id));
        } finally {
            $this->releaseAdvisoryLock(self::PROBE_CONNECTION, $world);
        }

        $this->assertSame(0, Artisan::call('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--dry-run' => true,
        ]));
        $this->assertMatchesRegularExpression('/\|\s*turn_runs\s*\|\s*1\s*\|/', Artisan::output());
        $this->assertNotNull(World::query()->find($world->id));
        $this->assertNotNull(TurnRun::query()->find($run->id));
        $this->assertNotNull(World::query()->find($otherWorld->id));
        $this->assertNotNull(TurnRun::query()->find($otherRun->id));
    }

    public function test_reset_world_lock_blocks_turn_runner_on_an_independent_connection(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $this->acquireAdvisoryLock($this->primaryConnection, $world);
        $previous = DB::getDefaultConnection();

        try {
            DB::setDefaultConnection(self::PROBE_CONNECTION);
            $this->runner()->run($world);
            $this->fail('TurnRunner unexpectedly passed the reset World advisory lock.');
        } catch (TurnAlreadyRunningException) {
            $this->assertTrue(true);
        } finally {
            DB::setDefaultConnection($previous);
            $this->releaseAdvisoryLock($this->primaryConnection, $world);
        }

        $this->assertSame(0, World::query()->findOrFail($world->id)->current_turn);
        $this->assertSame(0, TurnRun::query()->where('world_id', $world->id)->count());
    }

    public function test_reset_cannot_interleave_between_turn_commit_and_post_commit_refresh(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $attempted = false;
        $resetExit = null;
        $resetOutput = null;
        $eventName = 'eloquent.retrieved: '.TurnRun::class;

        Event::listen($eventName, function (TurnRun $retrieved) use (
            &$attempted,
            &$resetExit,
            &$resetOutput,
            $world,
        ): void {
            if ($attempted || $retrieved->status !== TurnRun::STATUS_COMPLETED) {
                return;
            }

            $attempted = true;
            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection(self::PROBE_CONNECTION);
            try {
                $resetExit = Artisan::call('hakoniwa:world:reset', [
                    '--world' => $world->key,
                    '--confirm' => 'RESET-'.$world->key,
                ]);
                $resetOutput = Artisan::output();
            } finally {
                DB::setDefaultConnection($previous);
            }
        });

        try {
            $run = $this->runner()->run($world);
        } finally {
            Event::forget($eventName);
        }

        $this->assertTrue($attempted);
        $this->assertSame(1, $resetExit);
        $this->assertStringContainsString('currently processing a turn', (string) $resetOutput);
        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, World::query()->findOrFail($world->id)->current_turn);
        $this->assertNotNull(TurnRun::query()->find($run->id));
    }

    /**
     * @return array{World, NationCommandQueueItem, TurnRun, User}
     */
    private function worldWithQueueAndRun(): array
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('reset-turn-lock-user', 'Reset Turn Lock User'),
        );
        $nation = app(NationCreationService::class)->create($user, $world, 'Reset turn lock Nation');
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $target = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $queueItem = app(CommandQueueService::class)->add(
            $user,
            $nation,
            $mapSpace,
            'land_clear',
            $target->x,
            $target->y,
            (string) Str::uuid(),
            1,
        )['item'];

        return [$world, $queueItem, $this->createTurnRun($world, 1), $user];
    }

    private function createTurnRun(World $world, int $targetTurn): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_pad(dechex($targetTurn), 64, '0', STR_PAD_LEFT),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_COMPLETED,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'started_at' => now(),
            'completed_at' => now(),
            'failure_context' => [],
        ]);
    }

    private function runner(): TurnRunner
    {
        $pipeline = new TurnPipeline(array_map(
            static fn (string $key): ScaffoldTurnPhase => new ScaffoldTurnPhase($key, true),
            TurnPipeline::CANONICAL_PHASE_KEYS,
        ));
        $seeds = new class implements TurnSeedGenerator
        {
            public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
            {
                return str_repeat('b', 64);
            }
        };

        return new TurnRunner($pipeline, new WorldTurnLock, $seeds);
    }

    private function acquireAdvisoryLock(string $connectionName, World $world): void
    {
        $acquired = DB::connection($connectionName)->selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$this->advisoryKey($world)],
        );

        $this->assertTrue($acquired->acquired);
    }

    private function releaseAdvisoryLock(string $connectionName, World $world): void
    {
        $released = DB::connection($connectionName)->selectOne(
            'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
            [$this->advisoryKey($world)],
        );

        $this->assertTrue($released->released);
    }

    private function advisoryKey(World $world): string
    {
        return "hakoniwa.turn.world.{$world->id}";
    }
}
