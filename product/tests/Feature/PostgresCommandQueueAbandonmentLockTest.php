<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class PostgresCommandQueueAbandonmentLockTest extends TestCase
{
    use CreatesTestWorlds;
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-command-abandonment-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific command abandonment race test.');
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

    public function test_add_revalidates_active_owner_after_waiting_for_abandonment_world_lock(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '予約競合島', '試験島主');
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $target = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();

        $directory = sys_get_temp_dir().'/command-abandonment-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700, true));
        $readyPath = $directory.'/ready';
        $goPath = $directory.'/go';
        $databasePath = $directory.'/database';
        $process = null;
        $pipes = [];
        $primary = DB::connection($this->primaryConnection);
        $primary->beginTransaction();

        try {
            $primary->table('worlds')->where('id', $world->id)->lockForUpdate()->first();
            $backend = $primary->selectOne('SELECT pg_backend_pid() AS pid');
            $this->assertIsObject($backend);
            $blockerPid = (int) $backend->pid;

            $process = proc_open([
                PHP_BINARY,
                base_path('tests/Support/command_queue_abandonment_worker.php'),
                $readyPath,
                $goPath,
                $databasePath,
                json_encode([
                    'user_id' => $owner->id,
                    'nation_id' => $nation->id,
                    'map_space_id' => $mapSpace->id,
                    'target_x' => $target->x,
                    'target_y' => $target->y,
                    'request_key' => (string) Str::uuid(),
                ], JSON_THROW_ON_ERROR),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            $this->assertIsResource($process);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $this->waitForFile($readyPath, 'queue worker start');
            file_put_contents($goPath, 'go', LOCK_EX);
            $this->waitForFile($databasePath, 'queue worker database');
            $workerPid = (int) file_get_contents($databasePath);
            $this->waitForWorldRowLock($workerPid, $blockerPid);

            $result = app(NationAbandonmentService::class)->abandon($owner, $nation, $nation->name);
            $this->assertSame('abandoned', $result['state']);
            $this->assertSame('abandoned', Nation::query()->findOrFail($nation->id)->state);
            $this->assertDatabaseMissing('nation_memberships', [
                'user_id' => $owner->id,
                'nation_id' => $nation->id,
            ]);

            $primary->commit();
            $worker = $this->finishWorker($process, $pipes);
            $process = null;
            $pipes = [];

            $this->assertSame(0, $worker['exit_code'], $worker['stderr']."\n".$worker['stdout']);
            $response = json_decode($worker['stdout'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('authorization', $response['status']);
            $this->assertSame(403, $response['http_status']);
            $this->assertStringContainsString('現在の島ではないcommand queue', $response['message']);
            $this->assertSame('abandoned', $nation->fresh()->state);
            $this->assertDatabaseMissing('nation_memberships', ['nation_id' => $nation->id]);
            $this->assertSame(0, NationCommandQueue::query()->where('nation_id', $nation->id)->count());
            $this->assertSame(0, NationCommandQueueItem::query()->count());
            $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.queued')->count());
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
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

    private function waitForWorldRowLock(int $workerPid, int $blockerPid): void
    {
        $deadline = microtime(true) + 10;
        do {
            $activity = DB::connection(self::PROBE_CONNECTION)->selectOne(
                'SELECT wait_event_type, query, '
                    .'CASE WHEN ? = ANY(pg_blocking_pids(pid)) THEN 1 ELSE 0 END AS blocked_by_parent '
                    .'FROM pg_stat_activity WHERE pid = ?',
                [$blockerPid, $workerPid],
            );
            if (is_object($activity)
                && $activity->wait_event_type === 'Lock'
                && (int) $activity->blocked_by_parent === 1
                && str_contains(strtolower((string) $activity->query), 'from "worlds"')) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Queue worker did not wait on the abandonment World row lock.');
            }
            usleep(10_000);
        } while (true);
    }

    /** @param array<int, resource> $pipes
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function finishWorker(mixed $process, array $pipes): array
    {
        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + 30;
        do {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $stdout .= is_string($out) ? $out : '';
            $stderr .= is_string($err) ? $err : '';
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Queue worker did not finish within 30 seconds.');
            }
            usleep(10_000);
        } while (true);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $stdout .= is_string($out) ? $out : '';
        $stderr .= is_string($err) ? $err : '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $closeCode;
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
