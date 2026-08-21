<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

final class PostgresSecretaryEquipmentConcurrencyTest extends TestCase
{
    use CreatesTestWorlds;
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-secretary-equipment-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific Secretary equipment concurrency test.');
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

    #[DataProvider('concurrentSlotTargets')]
    public function test_simultaneous_mutations_serialize_the_whole_equipment_state(int $secondSlot): void
    {
        [$user, $secretary, $bow] = $this->equipmentFixture();

        [$first, $second] = $this->runOrderedWorkers($user, [
            'operation' => 'equipment',
            'payload' => [
                'user_id' => $user->id, 'slot' => 1,
                'item_id' => $bow->id, 'expected_version' => 1,
            ],
        ], [
            'operation' => 'equipment',
            'payload' => [
                'user_id' => $user->id, 'slot' => $secondSlot,
                'item_id' => $bow->id, 'expected_version' => 1,
            ],
        ]);

        $this->assertSame('success', $first['status']);
        $this->assertSame('exception', $second['status']);
        $this->assertSame('secretary_equipment_version_conflict', $second['code']);
        $this->assertSame(2, $secretary->fresh()->equipment_version);
        $this->assertSame(1, $bow->fresh()->equipped_slot);
        $this->assertSame(1, SecretaryItemInstance::query()
            ->where('secretary_id', $secretary->id)->whereNotNull('equipped_slot')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'secretary.equipment_changed')->where('subject_id', $secretary->id)->count());
    }

    /** @return array<string, array{int}> */
    public static function concurrentSlotTargets(): array
    {
        return ['same slot' => [1], 'different slots and category max' => [2]];
    }

    public function test_equipment_first_then_abandonment_waits_and_preserves_committed_equipment(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '装備先行島', '装備先行島主');
        $secretary = $user->secretary()->firstOrFail();
        $bow = $secretary->itemInstances()->sole();

        [$equipment, $abandonment] = $this->runOrderedWorkers($user, [
            'operation' => 'equipment',
            'payload' => ['user_id' => $user->id, 'slot' => 1, 'item_id' => null, 'expected_version' => 1],
        ], [
            'operation' => 'abandonment',
            'payload' => ['user_id' => $user->id, 'nation_id' => $nation->id],
        ]);

        $this->assertSame('success', $equipment['status']);
        $this->assertSame('success', $abandonment['status']);
        $this->assertSame('abandoned', $nation->fresh()->state);
        $this->assertDatabaseMissing('nation_memberships', ['nation_id' => $nation->id, 'user_id' => $user->id]);
        $this->assertSame(2, $secretary->fresh()->equipment_version);
        $this->assertNull($bow->fresh()->equipped_slot);
    }

    public function test_abandonment_first_makes_the_pending_world_irrelevant_to_equipment(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '破棄先行島', '破棄先行島主');
        $secretary = $user->secretary()->firstOrFail();
        $bow = $secretary->itemInstances()->sole();

        [$abandonment, $equipment] = $this->runOrderedWorkers($user, [
            'operation' => 'abandon_then_block',
            'payload' => ['user_id' => $user->id, 'nation_id' => $nation->id, 'world_id' => $world->id],
        ], [
            'operation' => 'equipment',
            'payload' => ['user_id' => $user->id, 'slot' => 1, 'item_id' => null, 'expected_version' => 1],
        ]);

        $this->assertSame('success', $abandonment['status']);
        $this->assertSame('success', $equipment['status']);
        $this->assertSame('abandoned', $nation->fresh()->state);
        $this->assertDatabaseMissing('nation_memberships', ['nation_id' => $nation->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('turn_runs', ['world_id' => $world->id, 'status' => 'pending']);
        $this->assertSame(2, $secretary->fresh()->equipment_version);
        $this->assertNull($bow->fresh()->equipped_slot);
    }

    public function test_registration_first_exposes_the_new_pending_world_to_equipment_guard(): void
    {
        $oldWorld = $this->lightweightWorld();
        $user = User::factory()->create();
        $oldNation = app(NationCreationService::class)->create($user, $oldWorld, '再登録前島', '再登録島主');
        app(NationAbandonmentService::class)->abandon($user, $oldNation, $oldNation->name);
        $secretary = $user->secretary()->firstOrFail();
        $bow = $secretary->itemInstances()->sole();
        $targetWorld = $this->lightweightWorld();

        [$registration, $equipment] = $this->runOrderedWorkers($user, [
            'operation' => 'register_then_block',
            'payload' => [
                'user_id' => $user->id, 'world_id' => $targetWorld->id,
                'nation_name' => '再登録後島', 'owner_name' => '再登録島主',
            ],
        ], [
            'operation' => 'equipment',
            'payload' => ['user_id' => $user->id, 'slot' => 1, 'item_id' => null, 'expected_version' => 1],
        ]);

        $this->assertSame('success', $registration['status']);
        $this->assertSame('exception', $equipment['status']);
        $this->assertSame('secretary_equipment_turn_unresolved', $equipment['code']);
        $this->assertTrue(NationMembership::query()
            ->where('user_id', $user->id)->where('world_id', $targetWorld->id)->exists());
        $this->assertSame(1, $secretary->fresh()->equipment_version);
        $this->assertSame(1, $bow->fresh()->equipped_slot);
    }

    /** @return array{User, Secretary, SecretaryItemInstance} */
    private function equipmentFixture(): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '並行装備島', '並行装備島主');
        $secretary = $user->secretary()->firstOrFail();
        $firstBow = $secretary->itemInstances()->sole();
        $firstBow->update(['equipped_slot' => null]);

        return [$user, $secretary, $firstBow];
    }

    /**
     * @param  array{operation: string, payload: array<string, mixed>}  $first
     * @param  array{operation: string, payload: array<string, mixed>}  $second
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function runOrderedWorkers(User $user, array $first, array $second): array
    {
        $directory = sys_get_temp_dir().'/secretary-equipment-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700, true));
        $key = app(UserMembershipMutationLock::class)->key($user);
        $primary = DB::connection($this->primaryConnection);
        $parent = $primary->selectOne('SELECT pg_backend_pid() AS pid');
        $this->assertIsObject($parent);
        $parentPid = (int) $parent->pid;
        $primary->selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
        $workers = [];

        try {
            $workers[] = $this->startWorker($directory, 'first', $first);
            $this->waitForFile($workers[0]['database_path'], 'first worker database');
            $this->waitForUserLock((int) file_get_contents($workers[0]['database_path']), $parentPid);
            $workers[] = $this->startWorker($directory, 'second', $second);
            $this->waitForFile($workers[1]['database_path'], 'second worker database');
            $this->waitForUserLock((int) file_get_contents($workers[1]['database_path']), $parentPid);

            $released = $primary->selectOne(
                'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
                [$key],
            );
            $this->assertTrue($released->released);

            return [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
        } finally {
            $primary->selectOne('SELECT pg_advisory_unlock_all()');
            foreach ($workers as $worker) {
                $this->terminateWorker($worker);
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

    /** @param array{operation: string, payload: array<string, mixed>} $spec
     * @return array{process: resource, pipes: array<int, resource>, database_path: string}
     */
    private function startWorker(string $directory, string $label, array $spec): array
    {
        $databasePath = $directory.'/database-'.$label;
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/secretary_equipment_concurrency_worker.php'),
            $databasePath,
            $spec['operation'],
            json_encode($spec['payload'], JSON_THROW_ON_ERROR),
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

    private function waitForUserLock(int $workerPid, int $parentPid): void
    {
        $deadline = microtime(true) + 10;
        do {
            $activity = DB::connection(self::PROBE_CONNECTION)->selectOne(
                'SELECT wait_event_type, wait_event, query, '
                    .'CASE WHEN ? = ANY(pg_blocking_pids(pid)) THEN 1 ELSE 0 END AS blocked_by_parent '
                    .'FROM pg_stat_activity WHERE pid = ?',
                [$parentPid, $workerPid],
            );
            if (is_object($activity)
                && $activity->wait_event_type === 'Lock'
                && $activity->wait_event === 'advisory'
                && (int) $activity->blocked_by_parent === 1
                && str_contains(strtolower((string) $activity->query), 'pg_advisory_lock')) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Worker did not wait on the User membership advisory lock.');
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
        $deadline = microtime(true) + 30;
        do {
            $out = stream_get_contents($worker['pipes'][1]);
            $err = stream_get_contents($worker['pipes'][2]);
            $stdout .= is_string($out) ? $out : '';
            $stderr .= is_string($err) ? $err : '';
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Secretary equipment worker did not finish within 30 seconds.');
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

    /** @param array<string, mixed> $worker */
    private function terminateWorker(array &$worker): void
    {
        if (! isset($worker['process']) || ! is_resource($worker['process'])) {
            return;
        }
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
        unset($worker['process'], $worker['pipes']);
    }
}
