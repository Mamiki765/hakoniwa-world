<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\AtomicUndergroundCombat;
use App\Application\Underground\CanonicalUndergroundCombat;
use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Application\Underground\UndergroundRuntimeService;
use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\UndergroundTrialRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class UndergroundRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_atomic_victories_progress_level_xp_shards_and_compact_history(): void
    {
        Carbon::setTestNow('2026-08-29 09:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        [$runtime, $combat] = $this->runtimeWithOutcomes(['player', 'player', 'player']);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary)->refresh();

        $this->assertSame([1, 0, 0], [$profile->combat_level, $profile->combat_xp, $profile->shard_balance]);
        $run = $runtime->startTrial($user, 'trial_01');
        $first = $runtime->fightTrial($user, $run->run_key, (string) Str::uuid())['battle'];
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $runtime->fightTrial($user, $run->run_key, (string) Str::uuid());
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $third = $runtime->fightTrial($user, $run->run_key, (string) Str::uuid())['battle'];

        $profile = $profile->refresh();
        $this->assertSame([2, 125, 38], [$profile->combat_level, $profile->combat_xp, $profile->shard_balance]);
        $this->assertSame(4, $run->refresh()->next_battle_index);
        $this->assertSame(3, UndergroundBattle::query()->count());
        $this->assertSame(3, UndergroundBattleLog::query()->count());
        $this->assertSame([100, 100, 100], array_column($combat->calls, 'max_rounds'));
        $this->assertSame('damage', $first->log?->actions[0]['effect']);
        $this->assertArrayNotHasKey('reason', $first->log?->actions[0] ?? []);
        $this->assertTrue($first->log?->expires_at->equalTo($first->finished_at->addHours(1000)) ?? false);
        $this->assertSame(100, $third->snapshot['max_rounds']);
        $this->assertSame(112, $third->snapshot['actor']['attack']);
        $this->assertSame(40, $third->snapshot['encounter']['xp_reward']);
        $this->assertSame(100, $third->snapshot['xp_curve']['first_level_cost']);
        $this->assertTrue($profile->next_battle_at?->equalTo($third->finished_at->addSeconds(10)) ?? false);
    }

    public function test_duplicate_request_replays_once_before_cooldown_and_rejects_conflicting_intent(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00+09:00');
        [$user] = $this->secretaryUser();
        [$runtime, $combat] = $this->runtimeWithOutcomes(['player']);
        $requestId = (string) Str::uuid();

        $first = $runtime->explore($user, 'shallow_caves', $requestId);
        $duplicate = $runtime->explore($user, 'shallow_caves', $requestId);
        $profile = UndergroundProfile::query()->sole();

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['battle']->id, $duplicate['battle']->id);
        $this->assertSame(1, count($combat->calls));
        $this->assertSame($first['battle']->combat_xp_after, $profile->combat_xp);
        $this->assertSame($first['battle']->shard_balance_after, $profile->shard_balance);
        $this->assertRuntimeError(
            'underground_request_conflict',
            fn () => $runtime->explore($user, 'lower_galleries', $requestId),
        );
        $this->assertRuntimeError(
            'underground_battle_cooldown',
            fn () => $runtime->explore($user, 'shallow_caves', (string) Str::uuid()),
        );
        $this->assertSame(1, UndergroundBattle::query()->count());
    }

    public function test_trial_progress_resumes_and_explicit_withdrawal_restarts_at_battle_one(): void
    {
        Carbon::setTestNow('2026-08-29 11:00:00+09:00');
        [$user] = $this->secretaryUser();
        [$runtime] = $this->runtimeWithOutcomes(['player']);
        $run = $runtime->startTrial($user, 'trial_01');

        $runtime->fightTrial($user, $run->run_key, (string) Str::uuid());
        $resumed = app(UndergroundRuntimeService::class)->activeTrial($user);

        $this->assertSame($run->run_key, $resumed?->run_key);
        $this->assertSame(2, $resumed?->next_battle_index);
        $withdrawn = $runtime->withdrawTrial($user, $run->run_key);
        $this->assertSame(UndergroundTrialRun::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertSame(1, $withdrawn->next_battle_index);
        $this->assertSame($withdrawn->id, $runtime->withdrawTrial($user, $run->run_key)->id);

        $restarted = $runtime->startTrial($user, 'trial_01');
        $this->assertNotSame($run->run_key, $restarted->run_key);
        $this->assertSame(1, $restarted->next_battle_index);
    }

    public function test_defeat_halves_odd_shards_and_ends_trial_with_progress_reset(): void
    {
        Carbon::setTestNow('2026-08-29 12:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        [$runtime] = $this->runtimeWithOutcomes(['enemy']);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['combat_xp' => 25, 'shard_balance' => 101]);
        $run = $runtime->startTrial($user, 'trial_01');
        $run->update(['next_battle_index' => 8]);

        $battle = $runtime->fightTrial($user, $run->run_key, (string) Str::uuid())['battle'];

        $this->assertSame(UndergroundBattle::RESULT_DEFEAT, $battle->result);
        $this->assertSame(-51, $battle->shard_delta);
        $this->assertSame([25, 50], [$profile->refresh()->combat_xp, $profile->shard_balance]);
        $this->assertSame(UndergroundTrialRun::STATUS_DEFEATED, $run->refresh()->status);
        $this->assertSame(1, $run->next_battle_index);
        $this->assertNull($runtime->activeTrial($user));
        $this->assertSame(1, $runtime->startTrial($user, 'trial_01')->next_battle_index);
    }

    public function test_round_100_stalemate_is_no_loss_withdrawal_and_preserves_trial_progress(): void
    {
        Carbon::setTestNow('2026-08-29 13:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        [$runtime] = $this->runtimeWithOutcomes(['stalemate']);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['combat_xp' => 25, 'shard_balance' => 101]);
        $run = $runtime->startTrial($user, 'trial_01');
        $run->update(['next_battle_index' => 8]);

        $battle = $runtime->fightTrial($user, $run->run_key, (string) Str::uuid())['battle'];

        $this->assertSame([UndergroundBattle::RESULT_WITHDRAWAL, 100, 0], [
            $battle->result, $battle->rounds, $battle->shard_delta,
        ]);
        $this->assertSame([25, 101], [$profile->refresh()->combat_xp, $profile->shard_balance]);
        $this->assertSame(UndergroundTrialRun::STATUS_ACTIVE, $run->refresh()->status);
        $this->assertSame(8, $run->next_battle_index);
        $this->assertNotNull($profile->next_battle_at);
    }

    public function test_trial_first_clear_unlocks_one_layer_and_next_trial_once(): void
    {
        Carbon::setTestNow('2026-08-29 14:00:00+09:00');
        [$user] = $this->secretaryUser();
        [$runtime] = $this->runtimeWithOutcomes(['player', 'player']);
        $run = $runtime->startTrial($user, 'trial_01');
        $run->update(['next_battle_index' => 10]);

        $runtime->fightTrial($user, $run->run_key, (string) Str::uuid());
        $profile = UndergroundProfile::query()->sole();
        $firstClear = UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at;
        $this->assertSame([1, 4], [$profile->unlocked_area_layers, $profile->facilitySlotCapacity()]);
        $this->assertDatabaseHas('underground_trial_progress', [
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_02',
        ]);

        $repeat = $runtime->startTrial($user, 'trial_01');
        $repeat->update(['next_battle_index' => 10]);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $runtime->fightTrial($user, $repeat->run_key, (string) Str::uuid());

        $this->assertSame(1, $profile->refresh()->unlocked_area_layers);
        $this->assertTrue($firstClear?->equalTo(
            UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at,
        ) ?? false);
        $this->assertSame(1, UndergroundTrialProgress::query()->where('trial_key', 'trial_02')->count());
        $this->assertSame('trial_02', $runtime->startTrial($user, 'trial_02')->trial_key);
    }

    public function test_expired_log_pruning_preserves_history_idempotency_and_user_ownership(): void
    {
        Carbon::setTestNow('2026-08-29 15:00:00+09:00');
        [$owner] = $this->secretaryUser();
        [$other] = $this->secretaryUser();
        [$runtime, $combat] = $this->runtimeWithOutcomes(['player']);
        $requestId = (string) Str::uuid();
        $first = $runtime->explore($owner, 'shallow_caves', $requestId)['battle'];

        $this->assertSame($first->id, $runtime->recentBattles($owner)->sole()->id);
        $this->assertTrue($runtime->recentBattles($other)->isEmpty());
        Carbon::setTestNow($first->finished_at->addHours(1000));
        $this->assertSame(1, $runtime->pruneExpiredBattleLogs());
        $this->assertDatabaseMissing('underground_battle_logs', ['underground_battle_id' => $first->id]);
        $this->assertDatabaseHas('underground_battles', ['id' => $first->id, 'request_id' => $requestId]);
        $summary = $first->refresh();
        $this->assertSame([7, 3, 0], [
            $summary->damage_dealt,
            $summary->damage_received,
            $summary->healing_done,
        ]);

        $duplicate = $runtime->explore($owner, 'shallow_caves', $requestId);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first->id, $duplicate['battle']->id);
        $this->assertNull($duplicate['battle']->log);
        $this->assertSame(1, count($combat->calls));

        $run = $runtime->startTrial($owner, 'trial_01');
        $this->assertRuntimeError(
            'underground_trial_run_stale',
            fn () => $runtime->fightTrial($other, $run->run_key, (string) Str::uuid()),
        );
        $this->assertSame($run->run_key, $runtime->activeTrial($owner)?->run_key);
    }

    public function test_default_runtime_adapter_executes_the_canonical_pure_combat_core(): void
    {
        Carbon::setTestNow('2026-08-29 16:00:00+09:00');
        [$user] = $this->secretaryUser();

        $this->assertInstanceOf(CanonicalUndergroundCombat::class, app(AtomicUndergroundCombat::class));
        $battle = app(UndergroundRuntimeService::class)
            ->explore($user, 'shallow_caves', (string) Str::uuid())['battle'];

        $this->assertContains($battle->result, [
            UndergroundBattle::RESULT_VICTORY,
            UndergroundBattle::RESULT_DEFEAT,
            UndergroundBattle::RESULT_WITHDRAWAL,
        ]);
        $this->assertGreaterThanOrEqual(1, $battle->rounds);
        $this->assertLessThanOrEqual(100, $battle->rounds);
        $this->assertNotEmpty($battle->log?->actions);
        $this->assertSame(UndergroundCombatRules::IDENTITY, $battle->snapshot['combat_rules_identity']);
    }

    /** @return array{User, Secretary} */
    private function secretaryUser(): array
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id]);

        return [$user, $secretary];
    }

    /** @param list<'player'|'enemy'|'stalemate'> $outcomes
     * @return array{UndergroundRuntimeService, ScriptedUndergroundCombat}
     */
    private function runtimeWithOutcomes(array $outcomes): array
    {
        $combat = new ScriptedUndergroundCombat($outcomes);
        $this->app->instance(AtomicUndergroundCombat::class, $combat);

        return [app(UndergroundRuntimeService::class), $combat];
    }

    /** @param callable(): mixed $operation */
    private function assertRuntimeError(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected Underground runtime error [{$code}].");
        } catch (UndergroundRuntimeException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}

final class ScriptedUndergroundCombat implements AtomicUndergroundCombat
{
    /** @var list<array{actor_key: string, enemy_key: string, seed: int, max_rounds: int}> */
    public array $calls = [];

    /** @param list<'player'|'enemy'|'stalemate'> $outcomes */
    public function __construct(private array $outcomes) {}

    public function fight(
        string $actorKey,
        array $skillKeys,
        string $enemyKey,
        string $aiPreset,
        int $seed,
        int $maxRounds,
    ): CombatResult {
        $winner = array_shift($this->outcomes);
        if (! is_string($winner)) {
            throw new RuntimeException('Scripted Underground combat outcome was not configured.');
        }
        $rounds = $winner === 'stalemate' ? $maxRounds : 3;
        $this->calls[] = [
            'actor_key' => $actorKey,
            'enemy_key' => $enemyKey,
            'seed' => $seed,
            'max_rounds' => $maxRounds,
        ];

        return new CombatResult(
            rulesIdentity: UndergroundCombatRules::IDENTITY,
            seed: $seed,
            actorKey: $actorKey,
            enemyKey: $enemyKey,
            winner: $winner,
            rounds: $rounds,
            playerRemainingHp: $winner === 'enemy' ? 0 : 100,
            enemyRemainingHp: $winner === 'player' ? 0 : 100,
            damageDealt: 7,
            damageReceived: 3,
            healingDone: 0,
            skillUsage: [],
            normalAttackUsage: 1,
            defendUsage: 0,
            aiFallbackUsage: 0,
            resourceOverflow: 0,
            finalResource: 0,
            resourceHistory: [],
            abnormalState: [],
            actionLog: [[
                'round' => $rounds,
                'side' => 'player',
                'action' => 'normal_attack',
                'reason' => 'scripted',
                'amount' => 7,
                'guarded' => false,
                'player_hp' => $winner === 'enemy' ? 0 : 100,
                'enemy_hp' => $winner === 'player' ? 0 : 100,
                'player_resource' => 0,
                'enemy_telegraphing' => false,
            ]],
        );
    }
}
