<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\IslandMessage;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class MessageBoardIntegrityTest extends TestCase
{
    use CreatesTestWorlds;
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-message-board-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific message board integrity tests.');
        }
        config([
            'database.connections.'.self::PROBE_CONNECTION => config(
                'database.connections.'.$this->primaryConnection,
            ),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->primaryConnection, self::PROBE_CONNECTION] as $connectionName) {
            $connection = DB::connection($connectionName);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        DB::purge(self::PROBE_CONNECTION);
        parent::tearDown();
    }

    public function test_user_and_target_row_locks_serialize_cooldown_retention_and_money_updates(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = $this->nation($world->id, 1, 'Lock島');
        $primary = DB::connection($this->primaryConnection);
        $probe = DB::connection(self::PROBE_CONNECTION);

        $primary->beginTransaction();
        $primary->table('worlds')->where('id', $world->id)->lockForUpdate()->first();
        $primary->table('users')->where('id', $user->id)->lockForUpdate()->first();
        $primary->table('nations')->where('id', $nation->id)->lockForUpdate()->first();
        $probe->beginTransaction();
        $probe->statement("SET LOCAL lock_timeout = '100ms'");

        foreach (['worlds' => $world->id, 'users' => $user->id, 'nations' => $nation->id] as $table => $id) {
            try {
                $probe->table($table)->where('id', $id)->lockForUpdate()->first();
                $this->fail("A concurrent {$table} lock unexpectedly bypassed serialization.");
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
                $probe->rollBack();
                $probe->beginTransaction();
                $probe->statement("SET LOCAL lock_timeout = '100ms'");
            }
        }

        $primary->rollBack();
        $probe->rollBack();
    }

    public function test_cross_world_message_corruption_fails_closed_at_database_boundary(): void
    {
        $worldOne = $this->lightweightWorld();
        $worldTwo = World::query()->create([
            'key' => 'integrity-world-two',
            'name' => 'Integrity World Two',
            'ruleset_version_id' => $worldOne->ruleset_version_id,
            'current_turn' => 1,
        ]);
        $target = $this->nation($worldOne->id, 1, 'Target');
        $foreignAuthor = $this->nation($worldTwo->id, 1, 'Foreign');
        $user = User::factory()->create();
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => 'integrity-user',
            'display_name' => 'private',
        ]);

        try {
            IslandMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'world_id' => $worldOne->id,
                'target_nation_id' => $target->id,
                'author_user_id' => $user->id,
                'author_kind' => 'nation',
                'author_nation_id' => $foreignAuthor->id,
                'secret_sender_nation_id' => null,
                'message_type' => 'public',
                'body' => 'corrupt',
            ]);
            $this->fail('Cross-World author corruption was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    public function test_database_constraints_reject_invalid_body_shape_and_visitor_code(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->nation($world->id, 1, 'Constraint島');
        $user = User::factory()->create();

        foreach ([['BAD-CODE', '23514'], [str_repeat('A', 9), '22001']] as [$invalidCode, $sqlState]) {
            try {
                $user->forceFill(['visitor_code' => $invalidCode])->save();
                $this->fail('Invalid visitor code was accepted.');
            } catch (QueryException $exception) {
                $this->assertSame($sqlState, $exception->getCode());
                $user->refresh();
            }
        }

        try {
            IslandMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'world_id' => $world->id,
                'target_nation_id' => $nation->id,
                'author_user_id' => $user->id,
                'author_kind' => 'visitor',
                'author_nation_id' => null,
                'secret_sender_nation_id' => null,
                'message_type' => 'public',
                'body' => str_repeat('あ', 141),
            ]);
            $this->fail('A 141-character body was accepted by PostgreSQL.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }

        try {
            IslandMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'world_id' => $world->id,
                'target_nation_id' => $nation->id,
                'author_user_id' => $user->id,
                'author_kind' => 'nation',
                'author_nation_id' => $nation->id,
                'secret_sender_nation_id' => null,
                'message_type' => 'secret',
                'body' => 'sender is required',
            ]);
            $this->fail('A secret message without a sender was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_same_user_normal_and_secret_race_allows_exactly_one_success(): void
    {
        $world = $this->lightweightWorld();
        $sender = $this->nation($world->id, 1, 'Race送信島');
        $target = $this->nation($world->id, 2, 'Race受信島');
        $owner = $this->owner($world->id, $sender->id, 'cooldown-race-owner');

        $primary = DB::connection($this->primaryConnection);
        $primary->beginTransaction();
        $primary->table('worlds')->where('id', $world->id)->lockForUpdate()->first();

        $results = $this->runConcurrentWorkers([
            ['action' => 'public', 'payload' => [
                'user_id' => $owner->id, 'target_nation_id' => $target->id, 'body' => 'normal-race',
            ]],
            ['action' => 'secret', 'payload' => [
                'user_id' => $owner->id, 'target_nation_id' => $target->id, 'body' => 'secret-race',
            ]],
        ], false, 'worlds');

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['cooldown', 'ok'], $statuses);
        $this->assertSame(1, IslandMessage::query()->count());
        $message = IslandMessage::query()->firstOrFail();
        $this->assertSame(
            $message->message_type === IslandMessage::TYPE_SECRET ? 400 : 500,
            $sender->fresh()->money,
        );
        $this->assertNotNull($owner->fresh()->message_board_last_posted_at);
    }

    public function test_same_tourist_cross_world_race_is_serialized_by_the_user_lock(): void
    {
        $firstWorld = $this->lightweightWorld();
        $secondWorld = World::query()->create([
            'key' => 'cooldown-race-world-two',
            'name' => 'Cooldown Race World Two',
            'ruleset_version_id' => $firstWorld->ruleset_version_id,
            'current_turn' => 1,
        ]);
        $firstTarget = $this->nation($firstWorld->id, 1, '観光先一');
        $secondTarget = $this->nation($secondWorld->id, 1, '観光先二');
        $tourist = User::factory()->create();
        AuthIdentity::query()->create([
            'user_id' => $tourist->id,
            'provider' => 'google',
            'provider_user_id' => 'cross-world-cooldown-tourist',
            'display_name' => 'private',
        ]);
        $primary = DB::connection($this->primaryConnection);
        $primary->beginTransaction();
        $primary->table('users')->where('id', $tourist->id)->lockForUpdate()->first();

        $results = $this->runConcurrentWorkers([
            ['action' => 'public', 'payload' => [
                'user_id' => $tourist->id, 'target_nation_id' => $firstTarget->id, 'body' => 'world-one',
            ]],
            ['action' => 'public', 'payload' => [
                'user_id' => $tourist->id, 'target_nation_id' => $secondTarget->id, 'body' => 'world-two',
            ]],
        ], false, 'users');

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['cooldown', 'ok'], $statuses);
        $this->assertSame(1, IslandMessage::query()->where('author_user_id', $tourist->id)->count());
        $this->assertNotNull($tourist->fresh()->message_board_last_posted_at);
    }

    public function test_simultaneous_target_posts_retain_at_most_100_records(): void
    {
        $world = $this->lightweightWorld();
        $target = $this->nation($world->id, 1, 'Retention受信島');
        $firstNation = $this->nation($world->id, 2, 'Retention送信一');
        $secondNation = $this->nation($world->id, 3, 'Retention送信二');
        $firstOwner = $this->owner($world->id, $firstNation->id, 'retention-owner-one');
        $secondOwner = $this->owner($world->id, $secondNation->id, 'retention-owner-two');
        $now = now()->subMinute();
        for ($index = 0; $index < 99; $index++) {
            DB::table('island_messages')->insert([
                'public_id' => (string) Str::uuid(),
                'world_id' => $world->id,
                'target_nation_id' => $target->id,
                'author_user_id' => $firstOwner->id,
                'author_kind' => IslandMessage::AUTHOR_NATION,
                'author_nation_id' => $firstNation->id,
                'secret_sender_nation_id' => null,
                'message_type' => IslandMessage::TYPE_PUBLIC,
                'body' => "existing-{$index}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $oldestId = IslandMessage::query()->min('id');
        $primary = DB::connection($this->primaryConnection);
        $primary->beginTransaction();
        $primary->table('worlds')->where('id', $world->id)->lockForUpdate()->first();

        $results = $this->runConcurrentWorkers([
            ['action' => 'public', 'payload' => [
                'user_id' => $firstOwner->id, 'target_nation_id' => $target->id, 'body' => 'concurrent-one',
            ]],
            ['action' => 'public', 'payload' => [
                'user_id' => $secondOwner->id, 'target_nation_id' => $target->id, 'body' => 'concurrent-two',
            ]],
        ], false, 'worlds');

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame(100, IslandMessage::query()->where('target_nation_id', $target->id)->count());
        $this->assertDatabaseHas('island_messages', ['body' => 'concurrent-one']);
        $this->assertDatabaseHas('island_messages', ['body' => 'concurrent-two']);
        $this->assertDatabaseMissing('island_messages', ['id' => $oldestId]);
    }

    public function test_secret_send_racing_money_update_has_no_lost_update(): void
    {
        $world = $this->lightweightWorld();
        $sender = $this->nation($world->id, 1, 'Money送信島');
        $target = $this->nation($world->id, 2, 'Money受信島');
        $owner = $this->owner($world->id, $sender->id, 'money-race-owner');
        $primary = DB::connection($this->primaryConnection);
        $primary->beginTransaction();
        $primary->table('worlds')->where('id', $world->id)->lockForUpdate()->first();

        $results = $this->runConcurrentWorkers([
            ['action' => 'secret', 'payload' => [
                'user_id' => $owner->id, 'target_nation_id' => $target->id, 'body' => 'money-race-secret',
            ]],
            ['action' => 'money_update', 'payload' => [
                'world_id' => $world->id, 'nation_id' => $sender->id, 'amount' => 50,
            ]],
        ], false, 'worlds');

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame(450, $sender->fresh()->money);
        $this->assertSame(1, IslandMessage::query()->where('message_type', IslandMessage::TYPE_SECRET)->count());
    }

    public function test_concurrent_visitor_collision_allocates_two_unique_codes(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([[$first, 'concurrent-google-one'], [$second, 'concurrent-google-two']] as [$user, $providerId]) {
            AuthIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_user_id' => $providerId,
                'display_name' => 'private',
            ]);
        }

        $results = $this->runConcurrentWorkers([
            ['action' => 'allocate_collision', 'payload' => ['user_id' => $first->id, 'slot' => 0]],
            ['action' => 'allocate_collision', 'payload' => ['user_id' => $second->id, 'slot' => 1]],
        ], true);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $codes = [$first->fresh()->visitor_code, $second->fresh()->visitor_code];
        sort($codes);
        $this->assertSame(['AAAAAAAA', 'BBBBBBBB'], $codes);
        $this->assertSame(2, User::query()->whereNotNull('visitor_code')->distinct()->count('visitor_code'));
    }

    /**
     * @param  list<array{action: string, payload: array<string, mixed>}>  $specifications
     * @return list<array<string, mixed>>
     */
    private function runConcurrentWorkers(
        array $specifications,
        bool $collisionBarrier = false,
        ?string $expectedBlockedTable = null,
    ): array {
        $directory = sys_get_temp_dir().'/message-board-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700, true));
        $goPath = $directory.'/go';
        $workers = [];
        $releasePrimaryTransaction = $expectedBlockedTable !== null;

        try {
            $blockerPid = null;
            if ($releasePrimaryTransaction) {
                $primary = DB::connection($this->primaryConnection);
                $this->assertGreaterThan(0, $primary->transactionLevel());
                $backend = $primary->selectOne('SELECT pg_backend_pid() AS pid');
                $this->assertIsObject($backend);
                $blockerPid = (int) $backend->pid;
            }

            foreach ($specifications as $index => $specification) {
                $readyPath = $directory."/ready-{$index}";
                $databasePath = $directory."/database-{$index}";
                $payload = $specification['payload'];
                if ($collisionBarrier) {
                    $payload['collision_barrier_directory'] = $directory;
                }
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/message_board_concurrency_worker.php'),
                    $specification['action'],
                    $readyPath,
                    $goPath,
                    $databasePath,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = [
                    'process' => $process,
                    'pipes' => $pipes,
                    'readyPath' => $readyPath,
                    'databasePath' => $databasePath,
                    'stdout' => '',
                    'stderr' => '',
                    'exitCode' => null,
                ];
            }

            $deadline = microtime(true) + 10;
            while (collect($workers)->contains(fn (array $worker): bool => ! is_file($worker['readyPath']))) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Concurrent worker start barrier timed out.');
                }
                usleep(10_000);
            }
            file_put_contents($goPath, 'go', LOCK_EX);

            if ($expectedBlockedTable !== null) {
                $this->waitForWorkersToBlockOnServiceLock($workers, $expectedBlockedTable, (int) $blockerPid);
                DB::connection($this->primaryConnection)->rollBack();
                $releasePrimaryTransaction = false;
            }

            $deadline = microtime(true) + 30;
            do {
                $allComplete = true;
                foreach ($workers as &$worker) {
                    $stdout = stream_get_contents($worker['pipes'][1]);
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    $worker['stdout'] .= is_string($stdout) ? $stdout : '';
                    $worker['stderr'] .= is_string($stderr) ? $stderr : '';
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        $allComplete = false;
                    } else {
                        $worker['exitCode'] = (int) $status['exitcode'];
                    }
                }
                unset($worker);

                if (! $allComplete && microtime(true) >= $deadline) {
                    $this->fail('Concurrent workers did not finish within 30 seconds.');
                }
                if (! $allComplete) {
                    usleep(10_000);
                }
            } while (! $allComplete);

            $results = [];
            foreach ($workers as &$worker) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                $worker['stdout'] .= is_string($stdout) ? $stdout : '';
                $worker['stderr'] .= is_string($stderr) ? $stderr : '';
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $closeCode = proc_close($worker['process']);
                $exitCode = $worker['exitCode'] === -1 ? $closeCode : $worker['exitCode'];
                $worker['process'] = null;
                $this->assertSame(0, $exitCode, $worker['stderr']."\n".$worker['stdout']);
                $decoded = json_decode($worker['stdout'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                $results[] = $decoded;
            }
            unset($worker);

            return $results;
        } finally {
            if ($releasePrimaryTransaction) {
                $primary = DB::connection($this->primaryConnection);
                if ($primary->transactionLevel() > 0) {
                    $primary->rollBack();
                }
            }
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        proc_terminate($worker['process']);
                    }
                    foreach ($worker['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($worker['process']);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $workers
     */
    private function waitForWorkersToBlockOnServiceLock(
        array $workers,
        string $expectedTable,
        int $blockerPid,
    ): void {
        $deadline = microtime(true) + 10;
        while (collect($workers)->contains(
            fn (array $worker): bool => ! is_file((string) $worker['databasePath']),
        )) {
            if (microtime(true) >= $deadline) {
                $this->fail('Concurrent worker database barrier timed out.');
            }
            usleep(10_000);
        }

        $workerPids = array_map(
            static fn (array $worker): int => (int) file_get_contents((string) $worker['databasePath']),
            $workers,
        );
        $placeholders = implode(', ', array_fill(0, count($workerPids), '?'));
        $expectedQueryFragment = 'from "'.strtolower($expectedTable).'"';

        do {
            $rows = DB::connection(self::PROBE_CONNECTION)->select(
                'SELECT pid, wait_event_type, query, '
                    .'cardinality(pg_blocking_pids(pid)) AS blocker_count, '
                    .'CASE WHEN ? = ANY(pg_blocking_pids(pid)) THEN 1 ELSE 0 END AS blocked_by_parent '
                    ."FROM pg_stat_activity WHERE pid IN ({$placeholders})",
                [$blockerPid, ...$workerPids],
            );
            $allWaitingOnExpectedLock = count($rows) === count($workerPids)
                && collect($rows)->every(static fn (object $row): bool => $row->wait_event_type === 'Lock'
                    && (int) $row->blocker_count > 0
                    && str_contains(strtolower((string) $row->query), $expectedQueryFragment))
                && collect($rows)->contains(static fn (object $row): bool => (int) $row->blocked_by_parent === 1);
            if ($allWaitingOnExpectedLock) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail("Workers did not overlap on the {$expectedTable} service lock.");
            }
            usleep(10_000);
        } while (true);
    }

    private function owner(int $worldId, int $nationId, string $providerUserId): User
    {
        $user = User::factory()->create();
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => $providerUserId,
            'display_name' => 'private',
        ]);
        NationMembership::query()->create([
            'user_id' => $user->id,
            'world_id' => $worldId,
            'nation_id' => $nationId,
            'role' => 'owner',
        ]);

        return $user;
    }

    private function nation(int $worldId, int $number, string $name): Nation
    {
        return Nation::query()->create([
            'world_id' => $worldId,
            'nation_number' => $number,
            'registered_turn' => 1,
            'name' => $name,
            'owner_name' => $name.'主',
            'profile_comment' => '',
            'money' => 500,
            'state' => 'active',
            'idle_counter' => 0,
        ]);
    }
}
