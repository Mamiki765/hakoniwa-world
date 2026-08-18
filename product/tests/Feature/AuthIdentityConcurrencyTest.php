<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

final class AuthIdentityConcurrencyTest extends TestCase
{
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    public function test_concurrent_first_login_converges_to_one_identity_user_and_audit(): void
    {
        $results = $this->runConcurrent([
            'provider' => 'discord',
            'provider_user_id' => 'concurrent-first-login',
            'display_name' => 'Concurrent User',
        ]);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertCount(1, array_unique(array_column($results, 'user_id')));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, AuthIdentity::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'auth.identity_registered')->count());
    }

    public function test_concurrent_same_user_link_converges_to_one_identity_and_audit(): void
    {
        $user = User::factory()->create();
        $results = $this->runConcurrent([
            'provider' => 'google',
            'provider_user_id' => 'concurrent-link',
            'display_name' => 'Linked User',
            'link_user_id' => $user->id,
        ]);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame([$user->id, $user->id], array_column($results, 'user_id'));
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'auth.identity_linked')->count());
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function runConcurrent(array $payload): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL advisory-lock concurrency test.');
        }
        $directory = sys_get_temp_dir().'/auth-identity-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700, true));
        $goPath = $directory.'/go';
        $workers = [];
        $lockKey = AuthIdentityService::lockKey(
            (string) $payload['provider'],
            (string) $payload['provider_user_id'],
        );
        DB::selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$lockKey]);
        $locked = true;

        try {
            foreach ([0, 1] as $index) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/auth_identity_concurrency_worker.php'),
                    $directory."/ready-{$index}",
                    $goPath,
                    $directory."/database-{$index}",
                    json_encode($payload, JSON_THROW_ON_ERROR),
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = compact('process', 'pipes') + ['stdout' => '', 'stderr' => '', 'exitCode' => null];
            }
            $this->waitForFiles([$directory.'/ready-0', $directory.'/ready-1']);
            file_put_contents($goPath, 'go', LOCK_EX);
            $this->waitForFiles([$directory.'/database-0', $directory.'/database-1']);
            $pids = [(int) file_get_contents($directory.'/database-0'), (int) file_get_contents($directory.'/database-1')];
            $this->waitForAdvisoryLockWaiters($pids);
            DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released', [$lockKey]);
            $locked = false;

            $deadline = microtime(true) + 30;
            do {
                $running = false;
                foreach ($workers as &$worker) {
                    $worker['stdout'] .= stream_get_contents($worker['pipes'][1]) ?: '';
                    $worker['stderr'] .= stream_get_contents($worker['pipes'][2]) ?: '';
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        $running = true;
                    } else {
                        $worker['exitCode'] = (int) $status['exitcode'];
                    }
                }
                unset($worker);
                if ($running && microtime(true) >= $deadline) {
                    $this->fail('Auth identity workers timed out.');
                }
                if ($running) {
                    usleep(10_000);
                }
            } while ($running);

            $results = [];
            foreach ($workers as &$worker) {
                $worker['stdout'] .= stream_get_contents($worker['pipes'][1]) ?: '';
                $worker['stderr'] .= stream_get_contents($worker['pipes'][2]) ?: '';
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $closeCode = proc_close($worker['process']);
                $code = $worker['exitCode'] === -1 ? $closeCode : $worker['exitCode'];
                $worker['process'] = null;
                $this->assertSame(0, $code, $worker['stderr']."\n".$worker['stdout']);
                $results[] = json_decode($worker['stdout'], true, 512, JSON_THROW_ON_ERROR);
            }
            unset($worker);

            return $results;
        } finally {
            if ($locked) {
                DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
            }
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    foreach ($worker['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($worker['process']);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 10;
        while (collect($paths)->contains(static fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                $this->fail('Auth identity worker barrier timed out.');
            }
            usleep(10_000);
        }
    }

    /** @param list<int> $pids */
    private function waitForAdvisoryLockWaiters(array $pids): void
    {
        $deadline = microtime(true) + 10;
        $placeholders = implode(', ', array_fill(0, count($pids), '?'));
        do {
            $rows = DB::select(
                "SELECT pid, wait_event_type, query FROM pg_stat_activity WHERE pid IN ({$placeholders})",
                $pids,
            );
            if (count($rows) === count($pids) && collect($rows)->every(
                static fn (object $row): bool => $row->wait_event_type === 'Lock'
                    && str_contains(strtolower((string) $row->query), 'pg_advisory_xact_lock'),
            )) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Auth identity workers did not overlap on the advisory lock.');
            }
            usleep(10_000);
        } while (true);
    }
}
