<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundIntroService;
use App\Application\Underground\UndergroundProfileService;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundOwnedEquipment;
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
        $this->assertSame(['granted', 'granted'], array_column($results, 'drop_status'));

        $duplicateFlags = array_map(
            static fn (array $result): bool => (bool) $result['duplicate'],
            $results,
        );
        sort($duplicateFlags);
        $this->assertSame([false, true], $duplicateFlags);

        $battle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_EXPLORATION)
            ->sole();
        $drop = UndergroundOwnedEquipment::query()
            ->where('source_battle_id', $battle->id)
            ->sole();
        $persistedProfile = $profile->fresh();
        $this->assertNotNull($persistedProfile);
        $this->assertSame('generated', $drop->instance_kind);
        $this->assertSame($battle->id, $drop->source_battle_id);
        $this->assertSame('granted', $battle->snapshot['drop']['status'] ?? null);
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('source_battle_id', $battle->id)->count());
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

    public function test_equipment_mutations_serialize_duplicate_settlement_last_vault_slot_and_slot_swap(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $this->openExploration($user, $secretary);
        $profile->refresh();
        $profile->update(['shard_balance' => 10_000]);

        $purchase = [
            'operation' => 'equipment_purchase',
            'definition_key' => 'iron_dagger',
        ];
        $purchaseResults = $this->runConcurrentOperations($user, $secretary, [
            ['request_id' => (string) Str::uuid()] + $purchase,
            ['request_id' => (string) Str::uuid()] + $purchase,
        ]);
        $purchaseStatuses = array_column($purchaseResults, 'status');
        sort($purchaseStatuses);
        $this->assertSame(['conflict', 'ok'], $purchaseStatuses);
        $purchaseConflict = collect($purchaseResults)->firstWhere('status', 'conflict');
        $this->assertIsArray($purchaseConflict);
        $this->assertSame('underground_equipment_already_owned', $purchaseConflict['error_code']);
        $this->assertSame(9_880, $profile->fresh()->shard_balance);
        $ironDagger = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')->sole();
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'equipment_purchase')->count());

        $saleRequest = (string) Str::uuid();
        $sale = [
            'operation' => 'equipment_sell',
            'request_id' => $saleRequest,
            'item_id' => $ironDagger->id,
        ];
        $saleResults = $this->runConcurrentOperations($user, $secretary, [$sale, $sale]);
        $this->assertSame(['ok', 'ok'], array_column($saleResults, 'status'));
        $this->assertSame([9_940, 9_940], array_column($saleResults, 'shard_balance'));
        $this->assertDatabaseMissing('underground_owned_equipment', ['id' => $ironDagger->id]);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'equipment_sell')->count());

        $profile->refresh()->update(['shard_balance' => 10_000]);
        $rows = [];
        foreach (range(1, 498) as $offset) {
            $rows[] = [
                'underground_profile_id' => $profile->id,
                'definition_key' => 'bronze_rapier',
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
                'equipped_slot' => null,
                'grant_key' => null,
                'acquired_at' => now()->addSecond($offset),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('underground_owned_equipment')->insert($rows);
        $this->assertSame(499, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)->count());
        $lastSlotResults = $this->runConcurrentOperations($user, $secretary, [[
            'operation' => 'equipment_purchase',
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'steel_dagger',
        ], [
            'operation' => 'equipment_purchase',
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'leather_armor',
        ]]);
        $statuses = array_column($lastSlotResults, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $conflict = collect($lastSlotResults)->firstWhere('status', 'conflict');
        $success = collect($lastSlotResults)->firstWhere('status', 'ok');
        $this->assertIsArray($conflict);
        $this->assertIsArray($success);
        $this->assertSame('underground_vault_full', $conflict['error_code']);
        $price = $success['definition_key'] === 'steel_dagger' ? 360 : 100;
        $this->assertSame(10_000 - $price, $profile->fresh()->shard_balance);
        $this->assertSame(500, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)->count());

        $equipCandidates = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'bronze_rapier')
            ->orderBy('id')->limit(2)->get();
        $equipResults = $this->runConcurrentOperations($user, $secretary, [
            [
                'operation' => 'equipment_equip',
                'request_id' => (string) Str::uuid(),
                'item_id' => $equipCandidates[0]->id,
            ],
            [
                'operation' => 'equipment_equip',
                'request_id' => (string) Str::uuid(),
                'item_id' => $equipCandidates[1]->id,
            ],
        ]);
        $this->assertSame(['ok', 'ok'], array_column($equipResults, 'status'));
        $equippedWeapon = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('equipped_slot', 'weapon')->sole();
        $this->assertContains($equippedWeapon->id, $equipCandidates->pluck('id')->all());
        $this->assertSame(2, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'equipment_equip')->count());
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

    public function test_concurrent_respec_requests_allow_only_one_charge_and_reset(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $this->openExploration($user, $secretary);
        $profile->refresh();
        $profile->update([
            'combat_level' => 2,
            'combat_xp' => 100,
            'shard_balance' => 100,
            'unspent_stp' => 1,
            'allocated_vitality_stp' => 4,
        ]);

        $results = $this->runConcurrentOperations($user, $secretary, [[
            'operation' => 'respec',
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ], [
            'operation' => 'respec',
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ]]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $conflict = collect($results)->firstWhere('status', 'conflict');
        $success = collect($results)->firstWhere('status', 'ok');
        $this->assertIsArray($conflict);
        $this->assertIsArray($success);
        $this->assertSame('underground_respec_cooldown', $conflict['error_code']);
        $this->assertSame('free_black', $success['growth_path_key']);
        $this->assertSame(80, $success['shard_balance']);
        $this->assertNotNull($success['last_completed_at']);

        $profile->refresh();
        $this->assertSame([80, 'free_black', 6, 0], [
            $profile->shard_balance,
            $profile->growth_path_key,
            $profile->unspent_stp,
            array_sum($profile->allocatedStp()),
        ]);
        $this->assertNotNull($profile->last_respec_at);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'respec')
            ->count());
    }

    public function test_concurrent_stp_and_sp_mutations_serialize_without_duplicate_resources(): void
    {
        [$user, $secretary, $profile] = $this->undergroundFixture();
        $this->openExploration($user, $secretary);
        $profile->refresh();
        $profile->update(['combat_level' => 2, 'combat_xp' => 100, 'unspent_stp' => 5]);

        $stpResults = $this->runConcurrentOperations($user, $secretary, [[
            'operation' => 'stp_allocate',
            'request_id' => (string) Str::uuid(),
            'allocations' => ['vitality' => 4],
        ], [
            'operation' => 'stp_allocate',
            'request_id' => (string) Str::uuid(),
            'allocations' => ['might' => 4],
        ]]);
        $stpStatuses = array_column($stpResults, 'status');
        sort($stpStatuses);
        $this->assertSame(['conflict', 'ok'], $stpStatuses);
        $profile->refresh();
        $this->assertSame(1, $profile->unspent_stp);
        $this->assertSame(4, $profile->allocated_vitality_stp + $profile->allocated_might_stp);

        $skillResults = $this->runConcurrentOperations($user, $secretary, [[
            'operation' => 'skill_acquire',
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_holy_bolt',
        ], [
            'operation' => 'skill_acquire',
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_holy_bolt',
        ]]);
        $skillStatuses = array_column($skillResults, 'status');
        sort($skillStatuses);
        $this->assertSame(['conflict', 'ok'], $skillStatuses);
        $profile->refresh();
        $this->assertSame(15, $profile->skill_points_unspent);
        $this->assertSame(1, $profile->skillAllocations()->where('node_key', 'miracle_holy_bolt')->sole()->rank);
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
            'hunting_ground_key' => 'shallow_caves',
            'force_victory_drop' => true,
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
