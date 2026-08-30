<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundIntroService;
use App\Application\Underground\UndergroundProfileService;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

final class PostgresUndergroundRuntimeConcurrencyTest extends TestCase
{
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-underground-runtime-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific Underground runtime concurrency test.');
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

    public function test_same_request_concurrent_explorations_settle_one_battle_under_secretary_lock(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $this->openExploration($user, $secretary);
        $profile = $profile->fresh();
        $this->assertNotNull($profile);
        $requestId = (string) Str::uuid();

        $results = $this->runConcurrentExplore($user, $secretary, $requestId);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame([$user->id, $user->id], array_column($results, 'user_id'));
        $this->assertSame([$profile->id, $profile->id], array_column($results, 'profile_id'));
        $this->assertCount(1, array_unique(array_column($results, 'battle_id')));
        $this->assertSame([$requestId, $requestId], array_column($results, 'request_id'));

        $duplicateFlags = array_map(
            static fn (array $result): bool => (bool) $result['duplicate'],
            $results,
        );
        sort($duplicateFlags);
        $this->assertSame([false, true], $duplicateFlags);

        $battle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_EXPLORATION)
            ->sole();
        $persistedProfile = $profile->fresh();
        $this->assertNotNull($persistedProfile);
        $this->assertSame(1, $battle->log()->count());
        $this->assertSame(0, UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)->count());
        $this->assertSame($battle->combat_xp_after, $persistedProfile->combat_xp);
        $this->assertSame($battle->shard_balance_after, $persistedProfile->shard_balance);
        $this->assertSame(
            $battle->xp_awarded,
            $battle->combat_xp_after - $battle->combat_xp_before,
        );
        $this->assertSame(
            $battle->shard_delta,
            $battle->shard_balance_after - $battle->shard_balance_before,
        );
        $this->assertNotNull($persistedProfile->next_battle_at);
        $this->assertSame(
            $battle->finished_at->getTimestamp() + 10,
            $persistedProfile->next_battle_at->getTimestamp(),
        );
    }

    public function test_concurrent_bank_withdrawals_cannot_duplicate_banked_shards(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $this->openExploration($user, $secretary);
        $profile->update(['shard_balance' => 0, 'banked_shard_balance' => 1000]);

        $results = $this->runConcurrentOperations($user, $secretary, [
            [
                'operation' => 'bank_transfer',
                'request_id' => (string) Str::uuid(),
                'action' => 'withdraw',
                'amount' => 1000,
            ],
            [
                'operation' => 'bank_transfer',
                'request_id' => (string) Str::uuid(),
                'action' => 'withdraw',
                'amount' => 1000,
            ],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $conflict = collect($results)->firstWhere('status', 'conflict');
        $this->assertIsArray($conflict);
        $this->assertSame('underground_bank_insufficient_banked_shards', $conflict['error_code']);
        $profile->refresh();
        $this->assertSame([1000, 0], [$profile->shard_balance, $profile->banked_shard_balance]);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'bank_transfer')->count());
    }

    public function test_concurrent_different_shopkeeper_names_commit_exactly_one_under_secretary_lock(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $secretary->update(['name' => 'ペリドット', 'named_at' => now()]);
        $introService = app(UndergroundIntroService::class);
        $introService->enter($user, (string) Str::uuid());
        $introService->advance($user, (string) Str::uuid(), 'initial_story_complete');
        $introService->tutorial($user, (string) Str::uuid());
        $introService->advance($user, (string) Str::uuid(), 'escape_complete');
        $introService->enter($user, (string) Str::uuid());
        $introService->advance($user, (string) Str::uuid(), 'shopkeeper_encounter_complete');

        $names = ['最初の店員', 'もう一人の店員'];
        $results = $this->runConcurrentOperations($user, $secretary, [
            [
                'operation' => 'name_shopkeeper',
                'request_id' => (string) Str::uuid(),
                'name' => $names[0],
            ],
            [
                'operation' => 'name_shopkeeper',
                'request_id' => (string) Str::uuid(),
                'name' => $names[1],
            ],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $conflict = collect($results)->firstWhere('status', 'conflict');
        $success = collect($results)->firstWhere('status', 'ok');
        $this->assertIsArray($conflict);
        $this->assertIsArray($success);
        $this->assertSame('underground_shopkeeper_already_named', $conflict['error_code']);

        $progress = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->sole();
        $this->assertContains($progress->shopkeeper_name, $names);
        $this->assertSame($progress->shopkeeper_name, $success['shopkeeper_name']);
        $this->assertSame('shop_explanation', $progress->stage);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'shopkeeper_name')
            ->count());
    }

    public function test_same_request_concurrent_tutorials_settle_one_battle_and_reward_under_secretary_lock(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $secretary->update(['name' => 'ペリドット', 'named_at' => now()]);
        $introService = app(UndergroundIntroService::class);
        $introService->enter($user, (string) Str::uuid());
        $introService->advance($user, (string) Str::uuid(), 'initial_story_complete');
        $requestId = (string) Str::uuid();
        $payload = ['operation' => 'tutorial', 'request_id' => $requestId];

        $results = $this->runConcurrentOperations($user, $secretary, [$payload, $payload]);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame([$requestId, $requestId], array_column($results, 'battle_id'));
        $this->assertSame(['escape_pending', 'escape_pending'], array_column($results, 'stage'));
        $profile->refresh();
        $this->assertSame([1, 5, 100, null], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at,
        ]);
        $this->assertSame(1, UndergroundBattle::query()->count());
        $this->assertSame(1, UndergroundBattleLog::query()->count());
        $this->assertDatabaseHas('underground_intro_progress', [
            'underground_profile_id' => $profile->id,
            'stage' => 'escape_pending',
        ]);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'tutorial')
            ->count());
    }

    public function test_concurrent_contract_and_growth_selection_never_overwrite_the_chosen_path(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $secretary->update(['name' => 'ペリドット', 'named_at' => now()]);
        $introService = app(UndergroundIntroService::class);
        $introService->enter($user, (string) Str::uuid());
        $introService->advance($user, (string) Str::uuid(), 'initial_story_complete');
        $introService->tutorial($user, (string) Str::uuid());
        $introService->advance($user, (string) Str::uuid(), 'escape_complete');
        $introService->enter($user, (string) Str::uuid());
        $introService->advance($user, (string) Str::uuid(), 'shopkeeper_encounter_complete');
        $introService->nameShopkeeper($user, (string) Str::uuid(), '案内係');
        $introService->advance($user, (string) Str::uuid(), 'shop_explanation_complete');

        $contractRequest = (string) Str::uuid();
        $contractResults = $this->runConcurrentOperations($user, $secretary, [
            ['operation' => 'contract', 'request_id' => $contractRequest],
            ['operation' => 'contract', 'request_id' => $contractRequest],
        ]);
        $this->assertSame(['ok', 'ok'], array_column($contractResults, 'status'));
        $this->assertSame(['crystal_selection', 'crystal_selection'], array_column($contractResults, 'stage'));
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'contract')->count());

        $keys = ['martial_red', 'blessing_green'];
        $growthResults = $this->runConcurrentOperations($user, $secretary, [
            ['operation' => 'growth_path', 'request_id' => (string) Str::uuid(), 'growth_path_key' => $keys[0]],
            ['operation' => 'growth_path', 'request_id' => (string) Str::uuid(), 'growth_path_key' => $keys[1]],
        ]);
        $statuses = array_column($growthResults, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $conflict = collect($growthResults)->firstWhere('status', 'conflict');
        $success = collect($growthResults)->firstWhere('status', 'ok');
        $this->assertIsArray($conflict);
        $this->assertIsArray($success);
        $this->assertSame('underground_growth_path_already_selected', $conflict['error_code']);

        $profile->refresh();
        $this->assertContains($profile->growth_path_key, $keys);
        $this->assertSame($profile->growth_path_key, $success['growth_path_key']);
        $this->assertSame('secretary-underground-growth-alpha-v1', $profile->growth_path_identity);
        $this->assertNotNull($profile->growth_path_selected_at);
        $this->assertDatabaseHas('underground_intro_progress', [
            'underground_profile_id' => $profile->id,
            'stage' => 'growth_path_selected',
        ]);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'growth_path')->count());
    }

    public function test_forward_migration_resumes_existing_pr104_profiles_without_reclassifying_the_legacy_branch(): void
    {
        $schema = 'pr106_forward_'.strtolower(Str::random(12));
        $connection = DB::connection($this->primaryConnection);
        $connection->unprepared("CREATE SCHEMA {$schema}");
        $connection->unprepared("SET search_path TO {$schema}");

        try {
            $connection->unprepared(<<<'SQL'
CREATE TABLE underground_profiles (
  id BIGSERIAL PRIMARY KEY,
  combat_level INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE underground_battles (id BIGSERIAL PRIMARY KEY, activity_type VARCHAR(16) NOT NULL);
ALTER TABLE underground_battles
  ADD CONSTRAINT underground_battles_activity_type_check
  CHECK (activity_type IN ('exploration', 'trial', 'tutorial', 'story'));
CREATE TABLE underground_intro_progress (
  id BIGSERIAL PRIMARY KEY,
  underground_profile_id BIGINT NOT NULL,
  stage VARCHAR(32) NOT NULL,
  shopkeeper_name VARCHAR(255),
  special_loss_required BOOLEAN,
  tutorial_battle_id BIGINT,
  scripted_loss_battle_id BIGINT
);
ALTER TABLE underground_intro_progress
  ADD CONSTRAINT underground_intro_progress_stage_check
  CHECK (stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation', 'underground_open'
  )),
  ADD CONSTRAINT underground_intro_progress_naming_check
  CHECK (shopkeeper_name IS NOT NULL AND special_loss_required IS NOT NULL),
  ADD CONSTRAINT underground_intro_progress_special_loss_check
  CHECK (
    (special_loss_required = FALSE AND scripted_loss_battle_id IS NULL)
    OR (special_loss_required = TRUE AND scripted_loss_battle_id IS NOT NULL)
  );
CREATE TABLE underground_intro_requests (
  id BIGSERIAL PRIMARY KEY,
  operation VARCHAR(32) NOT NULL,
  resulting_stage VARCHAR(32) NOT NULL
);
ALTER TABLE underground_intro_requests
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN ('entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss')),
  ADD CONSTRAINT underground_intro_requests_stage_check
  CHECK (resulting_stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation', 'underground_open'
  ));
INSERT INTO underground_profiles (id) VALUES (1), (2);
INSERT INTO underground_battles (id, activity_type) VALUES (1, 'story');
INSERT INTO underground_intro_progress (
  underground_profile_id, stage, shopkeeper_name, special_loss_required, scripted_loss_battle_id
) VALUES
  (1, 'underground_open', 'ダミー', TRUE, 1),
  (2, 'underground_open', '通常案内', FALSE, NULL);
SQL);

            $migration = require database_path(
                'migrations/2026_08_30_000000_add_underground_contract_growth_and_playtest.php',
            );
            $migration->up();

            $rows = $connection->table('underground_intro_progress')
                ->orderBy('underground_profile_id')
                ->get(['stage', 'shopkeeper_name', 'special_loss_required', 'branch_identity']);
            $this->assertSame('shop_explanation', $rows[0]->stage);
            $this->assertSame('ダミー', $rows[0]->shopkeeper_name);
            $this->assertTrue($rows[0]->special_loss_required);
            $this->assertSame('legacy_temporary', $rows[0]->branch_identity);
            $this->assertSame('shop_explanation', $rows[1]->stage);
            $this->assertSame('normal', $rows[1]->branch_identity);
            $profiles = $connection->table('underground_profiles')->orderBy('id')->get();
            foreach ($profiles as $profile) {
                $this->assertNull($profile->underground_contract_completed_at);
                $this->assertNull($profile->growth_path_key);
                $this->assertNull($profile->growth_path_identity);
                $this->assertNull($profile->growth_path_selected_at);
            }
            $connection->table('underground_profiles')->where('id', 1)->update([
                'combat_level' => 3,
                'underground_contract_completed_at' => '2026-08-30 00:00:00+00',
                'growth_path_key' => 'martial_red',
                'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
                'growth_path_selected_at' => '2026-08-30 00:01:00+00',
            ]);
            $connection->table('underground_profiles')->where('id', 2)->update(['combat_level' => 7]);
            $growthMigration = require database_path(
                'migrations/2026_08_30_020000_add_underground_growth_stp_foundation.php',
            );
            $growthMigration->up();
            $reconciled = $connection->table('underground_profiles')->orderBy('id')->get();
            $this->assertSame([10, 0], [
                $reconciled[0]->unspent_stp,
                $reconciled[1]->unspent_stp,
            ]);
            $this->assertSame([0, 0], [
                $reconciled[0]->banked_shard_balance,
                $reconciled[1]->banked_shard_balance,
            ]);
            $this->assertSame(508, $reconciled[0]->current_hp);
            $this->assertNull($reconciled[1]->current_hp);
            foreach ($reconciled as $profile) {
                $this->assertSame(0, $profile->allocated_vitality_stp);
                $this->assertSame(0, $profile->allocated_might_stp);
                $this->assertSame(0, $profile->allocated_finesse_stp);
                $this->assertSame(0, $profile->allocated_spirit_stp);
                $this->assertSame(0, $profile->allocated_agility_stp);
            }
            $this->assertSame(1, $connection->table('pg_constraint')
                ->join('pg_class', 'pg_constraint.conrelid', '=', 'pg_class.oid')
                ->join('pg_namespace', 'pg_class.relnamespace', '=', 'pg_namespace.oid')
                ->where('pg_constraint.conname', 'underground_profiles_stp_entitlement_check')
                ->where('pg_namespace.nspname', $schema)
                ->count());
            $this->assertSame(0, $connection->table('pg_constraint')
                ->join('pg_class', 'pg_constraint.conrelid', '=', 'pg_class.oid')
                ->join('pg_namespace', 'pg_class.relnamespace', '=', 'pg_namespace.oid')
                ->where('pg_constraint.conname', 'underground_profiles_combat_level_max_check')
                ->where('pg_namespace.nspname', $schema)
                ->count());
        } finally {
            $connection->unprepared('SET search_path TO public');
            $connection->unprepared("DROP SCHEMA {$schema} CASCADE");
        }
    }

    /** @return array{User, Secretary, UndergroundProfile} */
    private function undergroundFixture(): array
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['shard_balance' => 100]);

        return [$user, $secretary, $profile->fresh()];
    }

    private function openExploration(User $user, Secretary $secretary): void
    {
        $secretary->update(['name' => '探索秘書', 'named_at' => now()]);
        $intro = app(UndergroundIntroService::class);
        $intro->enter($user, (string) Str::uuid());
        $intro->advance($user, (string) Str::uuid(), 'initial_story_complete');
        $intro->tutorial($user, (string) Str::uuid());
        $intro->advance($user, (string) Str::uuid(), 'escape_complete');
        $intro->enter($user, (string) Str::uuid());
        $intro->advance($user, (string) Str::uuid(), 'shopkeeper_encounter_complete');
        $intro->nameShopkeeper($user, (string) Str::uuid(), '案内係');
        $intro->advance($user, (string) Str::uuid(), 'shop_explanation_complete');
        $intro->contract($user, (string) Str::uuid());
        $intro->selectGrowthPath($user, (string) Str::uuid(), 'martial_red');
        $intro->advance($user, (string) Str::uuid(), 'growth_path_story_complete');
    }

    /** @return list<array<string, mixed>> */
    private function runConcurrentExplore(User $user, Secretary $secretary, string $requestId): array
    {
        $payload = [
            'operation' => 'explore',
            'hunting_ground' => 'shallow_caves',
            'request_id' => $requestId,
        ];

        return $this->runConcurrentOperations($user, $secretary, [$payload, $payload]);
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function runConcurrentOperations(User $user, Secretary $secretary, array $payloads): array
    {
        $this->assertCount(2, $payloads);
        $directory = sys_get_temp_dir().'/underground-runtime-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700, true));
        $goPath = $directory.'/go';
        $primary = DB::connection($this->primaryConnection);
        $workers = [];
        $primary->beginTransaction();
        $parent = $primary->selectOne('SELECT pg_backend_pid() AS pid');
        $this->assertIsObject($parent);
        $parentPid = (int) $parent->pid;
        $primary->table('secretaries')->where('id', $secretary->id)->lockForUpdate()->firstOrFail();
        $primary->table('underground_profiles')
            ->where('secretary_id', $secretary->id)
            ->lockForUpdate()
            ->firstOrFail();

        try {
            foreach ([0, 1] as $index) {
                $workers[] = $this->startWorker(
                    $directory,
                    (string) $index,
                    ['user_id' => $user->id] + $payloads[$index],
                    $goPath,
                );
            }
            $this->waitForFiles([
                $directory.'/ready-0',
                $directory.'/ready-1',
            ]);
            file_put_contents($goPath, 'go', LOCK_EX);
            $this->waitForFiles([
                $directory.'/database-0',
                $directory.'/database-1',
            ]);
            $pids = [
                (int) file_get_contents($directory.'/database-0'),
                (int) file_get_contents($directory.'/database-1'),
            ];
            $this->waitForSecretaryLockWaiters($pids, $parentPid);
            $primary->commit();

            return [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
        } finally {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            foreach ($workers as &$worker) {
                $this->terminateWorker($worker);
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

    /** @param array<string, mixed> $payload
     * @return array{process: resource, pipes: array<int, resource>, database_path: string}
     */
    private function startWorker(
        string $directory,
        string $label,
        array $payload,
        string $goPath,
    ): array {
        $readyPath = $directory.'/ready-'.$label;
        $databasePath = $directory.'/database-'.$label;
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/underground_runtime_concurrency_worker.php'),
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

        return ['process' => $process, 'pipes' => $pipes, 'database_path' => $databasePath];
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 10;
        while (collect($paths)->contains(static fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                $this->fail('Underground runtime worker barrier timed out.');
            }
            usleep(10_000);
        }
    }

    /** @param list<int> $pids */
    private function waitForSecretaryLockWaiters(array $pids, int $parentPid): void
    {
        $deadline = microtime(true) + 10;
        $placeholders = implode(', ', array_fill(0, count($pids), '?'));
        do {
            $rows = DB::connection(self::PROBE_CONNECTION)->select(
                'SELECT pid, wait_event_type, query, '
                    .'CASE WHEN ? = ANY(pg_blocking_pids(pid)) THEN 1 ELSE 0 END AS blocked_by_parent '
                    ."FROM pg_stat_activity WHERE pid IN ({$placeholders})",
                array_merge([$parentPid], $pids),
            );
            $secretaryWaiters = collect($rows)->filter(static function (object $row): bool {
                $query = strtolower((string) $row->query);

                return $row->wait_event_type === 'Lock'
                    && (str_contains($query, 'from "secretaries"')
                        || str_contains($query, 'from secretaries'));
            });
            if (count($rows) === count($pids)
                && $secretaryWaiters->count() === count($pids)
                && $secretaryWaiters->contains(static fn (object $row): bool => (int) $row->blocked_by_parent === 1)) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Underground runtime workers did not overlap on the Secretary row lock.');
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
                $this->fail('Underground runtime worker did not finish within 30 seconds.');
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
