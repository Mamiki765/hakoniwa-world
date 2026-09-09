<?php

namespace Tests\Feature;

use App\Application\CompensationWarehouseService;
use App\Application\NationCreationService;
use App\Application\ParadoxBalanceService;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

final class PostgresRewardMutationConcurrencyTest extends TestCase
{
    use CreatesTestWorlds;
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-reward-mutation-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific reward mutation concurrency test.');
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
            $connection->selectOne('SELECT pg_advisory_unlock_all()');
        }
        DB::purge(self::PROBE_CONNECTION);
        parent::tearDown();
    }

    public function test_daily_login_waits_for_ticket_before_pd_and_does_not_block_a_concurrent_pd_debit(): void
    {
        $user = User::factory()->create();
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 0]);
        app(ParadoxBalanceService::class)->credit($user->id, 5, 'test:reward-lock-order:setup', 'command');
        $directory = $this->workerDirectory();
        $workers = [];
        $primary = DB::connection($this->primaryConnection);
        $parentPid = (int) $primary->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $primary->beginTransaction();

        try {
            UserSkipTicketBalance::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $workers[] = $this->startWorker($directory, 'login', 'daily_login', ['user_id' => $user->id]);
            $this->waitForFile($workers[0]['database_path'], 'daily login worker database');
            $this->waitUntilBlockedByParent((int) file_get_contents($workers[0]['database_path']), $parentPid);

            $workers[] = $this->startWorker($directory, 'debit', 'paradox_debit', [
                'user_id' => $user->id,
                'amount' => 3,
                'entry_key' => 'test:reward-lock-order:debit',
            ]);
            $debit = $this->finishWorker($workers[1]);
            $this->assertSame('success', $debit['status']);
            $this->assertSame(2, $debit['balance_after']);

            $primary->commit();
            $login = $this->finishWorker($workers[0]);
            $this->assertSame('success', $login['status']);
            $this->assertTrue($login['awarded_now']);
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            $this->cleanupWorkers($directory, $workers);
        }

        $this->assertSame(12, app(ParadoxBalanceService::class)->balanceFor($user->id));
        $this->assertSame(50, (int) UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_concurrent_identical_compensation_requests_replay_one_stored_result(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '同時配布島', '同時配布島主');
        $nation->update(['money' => 0]);
        $grant = app(CompensationWarehouseService::class)->createGrant(
            $nation,
            'test-concurrent-compensation',
            'test-operator',
            '同時配布確認',
            ['money' => 25],
        )['grant'];
        $requestId = (string) Str::uuid();
        $lockKey = 'hakoniwa.compensation.claim.request.'.$requestId;
        $directory = $this->workerDirectory();
        $workers = [];
        $primary = DB::connection($this->primaryConnection);
        $parentPid = (int) $primary->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $primary->selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$lockKey]);

        try {
            $payload = [
                'user_id' => $user->id,
                'nation_id' => $nation->id,
                'grant_id' => $grant->id,
                'request_id' => $requestId,
            ];
            $workers[] = $this->startWorker($directory, 'claim-a', 'compensation_claim', $payload);
            $workers[] = $this->startWorker($directory, 'claim-b', 'compensation_claim', $payload);
            foreach ($workers as $index => $worker) {
                $this->waitForFile($worker['database_path'], "claim worker {$index} database");
                $this->waitUntilBlockedByParent((int) file_get_contents($worker['database_path']), $parentPid);
            }
            $primary->selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released', [$lockKey]);

            $results = [$this->finishWorker($workers[0]), $this->finishWorker($workers[1])];
        } finally {
            $primary->selectOne('SELECT pg_advisory_unlock_all()');
            $this->cleanupWorkers($directory, $workers);
        }

        $this->assertSame(['success', 'success'], array_column($results, 'status'));
        $duplicates = array_column($results, 'duplicate');
        sort($duplicates);
        $this->assertSame([false, true], $duplicates);
        $this->assertSame(25, (int) $nation->fresh()->money);
        $this->assertDatabaseCount('compensation_grant_claims', 1);
    }

    private function workerDirectory(): string
    {
        $directory = sys_get_temp_dir().'/reward-mutation-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700, true));

        return $directory;
    }

    /** @param array<string, mixed> $payload
     * @return array{process: resource, pipes: array<int, resource>, database_path: string}
     */
    private function startWorker(string $directory, string $label, string $operation, array $payload): array
    {
        $databasePath = $directory.'/database-'.$label;
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/reward_mutation_concurrency_worker.php'),
            $databasePath,
            $operation,
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

        return ['process' => $process, 'pipes' => $pipes, 'database_path' => $databasePath];
    }

    private function waitForFile(string $path, string $label): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                $this->fail("{$label} barrier timed out.");
            }
            usleep(10_000);
        }
    }

    private function waitUntilBlockedByParent(int $workerPid, int $parentPid): void
    {
        $deadline = microtime(true) + 10;
        do {
            $activity = DB::connection(self::PROBE_CONNECTION)->selectOne(
                'SELECT wait_event_type, CASE WHEN ? = ANY(pg_blocking_pids(pid)) THEN 1 ELSE 0 END AS blocked_by_parent '
                .'FROM pg_stat_activity WHERE pid = ?',
                [$parentPid, $workerPid],
            );
            if (is_object($activity)
                && $activity->wait_event_type === 'Lock'
                && (int) $activity->blocked_by_parent === 1) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Worker did not block behind the parent lock.');
            }
            usleep(10_000);
        } while (true);
    }

    /** @param array{process: resource, pipes: array<int, resource>, database_path: string} $worker
     * @return array<string, mixed>
     */
    private function finishWorker(array &$worker): array
    {
        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + 15;
        do {
            $stdout .= (string) stream_get_contents($worker['pipes'][1]);
            $stderr .= (string) stream_get_contents($worker['pipes'][2]);
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Reward mutation worker did not finish within 15 seconds.');
            }
            usleep(10_000);
        } while (true);

        $stdout .= (string) stream_get_contents($worker['pipes'][1]);
        $stderr .= (string) stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $closeCode = proc_close($worker['process']);
        if ($exitCode === -1) {
            $exitCode = $closeCode;
        }
        unset($worker['process'], $worker['pipes']);
        $this->assertSame(0, $exitCode, $stderr."\n".$stdout);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<int, array<string, mixed>> $workers */
    private function cleanupWorkers(string $directory, array &$workers): void
    {
        foreach ($workers as &$worker) {
            if (isset($worker['process']) && is_resource($worker['process'])) {
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
        unset($worker);
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
