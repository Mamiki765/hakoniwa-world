<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\AtomicUndergroundCombat;
use App\Application\Underground\AtomicUndergroundExplorationCombat;
use App\Application\Underground\CanonicalUndergroundCombat;
use App\Application\Underground\CanonicalUndergroundExplorationCombat;
use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Application\Underground\UndergroundRuntimeService;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkillAllocation;
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
        $profile = $this->unlockExploration($secretary);
        [$runtime, , $combat] = $this->runtimeWithOutcomes(['player', 'player', 'player']);

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
        $this->assertSame(3, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_TRIAL)->count());
        $this->assertSame(3, UndergroundBattleLog::query()
            ->whereHas('battle', fn ($query) => $query
                ->where('activity_type', UndergroundBattle::ACTIVITY_TRIAL))->count());
        $this->assertSame([100, 100, 100], array_column($combat->calls, 'max_rounds'));
        $this->assertSame('damage', $first->log?->actions[0]['actions'][0]['type']);
        $this->assertTrue($first->log?->expires_at->equalTo($first->finished_at->addHour()) ?? false);
        $this->assertSame(100, $third->snapshot['max_rounds']);
        $this->assertSame(40, $third->snapshot['encounter']['xp_reward']);
        $this->assertSame(100, $third->snapshot['xp_curve']['first_level_cost']);
        $this->assertTrue($profile->next_battle_at?->equalTo($third->finished_at->addSeconds(10)) ?? false);
    }

    public function test_exploration_duplicate_request_replays_once_before_cooldown_without_trial_contamination(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        $this->unlockExploration($secretary);
        [$runtime, , $combat] = $this->runtimeWithOutcomes(['player']);
        $requestId = (string) Str::uuid();

        $first = $runtime->explore($user, $requestId);
        $duplicate = $runtime->explore($user, $requestId);
        $profile = UndergroundProfile::query()->sole();

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['battle']->id, $duplicate['battle']->id);
        $this->assertSame(1, count($combat->calls));
        $this->assertSame($first['battle']->combat_xp_after, $profile->combat_xp);
        $this->assertSame($first['battle']->shard_balance_after, $profile->shard_balance);
        $this->assertRuntimeError(
            'underground_battle_cooldown',
            fn () => $runtime->explore($user, (string) Str::uuid()),
        );
        $this->assertSame(1, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_EXPLORATION)->count());
        $this->assertSame(0, UndergroundTrialProgress::query()->count());
    }

    public function test_exploration_bonus_rewards_settle_multi_level_growth_stp_withdrawal_and_defeat_atomically(): void
    {
        Carbon::setTestNow('2026-08-29 10:30:00+09:00');
        $crystalBug = config('underground-alpha-v1.exploration.encounters.crystal_bug');
        $this->assertIsArray($crystalBug);
        $crystalBug['weight'] = 10_000;
        config(['underground-alpha-v1.exploration.encounters' => ['crystal_bug' => $crystalBug]]);
        [$user, $secretary] = $this->secretaryUser();
        $profile = $this->unlockExploration($secretary);
        $profile->update([
            'shard_balance' => 101,
            'banked_shard_balance' => 5000,
            'current_hp' => 300,
        ]);
        [$runtime, , $combat] = $this->runtimeWithOutcomes([
            ['winner' => 'player', 'remaining_hp' => 250, 'final_mp' => 123],
            ['winner' => 'stalemate', 'remaining_hp' => 200, 'final_mp' => 456],
            ['winner' => 'enemy', 'remaining_hp' => 0, 'final_mp' => 789],
            ['winner' => 'player', 'remaining_hp' => 250, 'final_mp' => 123],
            ['winner' => 'player', 'remaining_hp' => 225, 'final_mp' => 456],
        ]);

        $victory = $runtime->explore($user, (string) Str::uuid())['battle'];
        $this->assertSame([
            UndergroundBattle::RESULT_VICTORY, 1150, 0, 1, 6, 25, 25,
        ], [
            $victory->result,
            $victory->xp_awarded,
            $victory->shard_delta,
            $victory->combat_level_before,
            $victory->combat_level_after,
            $victory->snapshot['stp_awarded'],
            $victory->snapshot['unspent_stp_after'],
        ]);
        $this->assertEquals(
            ['vitality' => 18, 'might' => 34, 'finesse' => 30, 'spirit' => 8, 'agility' => 10],
            $victory->snapshot['progression_stats'],
        );
        $this->assertEquals(
            ['vitality' => 23, 'might' => 44, 'finesse' => 35, 'spirit' => 13, 'agility' => 10],
            app(UndergroundAlphaV1PlayerCatalog::class)->currentStats(
                'martial_red',
                $profile->refresh()->combat_level,
                $profile->allocatedStp(),
            ),
        );
        $this->assertSame([300, 250, 10000, 123], [
            $victory->snapshot['current_hp_before'],
            $victory->snapshot['current_hp_after'],
            $victory->snapshot['battle_start_mp'],
            $victory->snapshot['summary']['final_mp'],
        ]);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $withdrawal = $runtime->explore($user, (string) Str::uuid())['battle'];
        $this->assertSame([
            UndergroundBattle::RESULT_WITHDRAWAL, 287, 0, 6, 7, 5, 30,
        ], [
            $withdrawal->result,
            $withdrawal->xp_awarded,
            $withdrawal->shard_delta,
            $withdrawal->combat_level_before,
            $withdrawal->combat_level_after,
            $withdrawal->snapshot['stp_awarded'],
            $withdrawal->snapshot['unspent_stp_after'],
        ]);
        $this->assertSame([250, 200, 10000, 456], [
            $withdrawal->snapshot['current_hp_before'],
            $withdrawal->snapshot['current_hp_after'],
            $withdrawal->snapshot['battle_start_mp'],
            $withdrawal->snapshot['summary']['final_mp'],
        ]);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $defeat = $runtime->explore($user, (string) Str::uuid())['battle'];
        $this->assertSame([
            UndergroundBattle::RESULT_DEFEAT, 0, -51, 7, 7, 0, 30,
        ], [
            $defeat->result,
            $defeat->xp_awarded,
            $defeat->shard_delta,
            $defeat->combat_level_before,
            $defeat->combat_level_after,
            $defeat->snapshot['stp_awarded'],
            $defeat->snapshot['unspent_stp_after'],
        ]);
        $this->assertSame([200, 540, 10000, 789], [
            $defeat->snapshot['current_hp_before'],
            $defeat->snapshot['current_hp_after'],
            $defeat->snapshot['battle_start_mp'],
            $defeat->snapshot['summary']['final_mp'],
        ]);
        $this->assertSame([1437, 50, 5000, 540, 30], [
            $profile->refresh()->combat_xp,
            $profile->shard_balance,
            $profile->banked_shard_balance,
            $profile->current_hp,
            $profile->unspent_stp,
        ]);
        $this->assertSame([300, 250, 200], array_column($combat->calls, 'current_hp'));

        $level100MaxHp = app(UndergroundAlphaV1PlayerCatalog::class)->currentMaxHp(
            'martial_red',
            100,
            $profile->allocatedStp(),
        );
        $profile->update([
            'combat_level' => 100,
            'combat_xp' => 256_350,
            'unspent_stp' => 495,
            'current_hp' => $level100MaxHp,
        ]);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $levelUp = $runtime->explore($user, (string) Str::uuid())['battle'];
        $this->assertSame([100, 101, 257_500, 5, 500, $level100MaxHp, 250], [
            $levelUp->combat_level_before,
            $levelUp->combat_level_after,
            $levelUp->combat_xp_after,
            $levelUp->snapshot['stp_awarded'],
            $levelUp->snapshot['unspent_stp_after'],
            $levelUp->snapshot['current_hp_before'],
            $levelUp->snapshot['current_hp_after'],
        ]);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $aboveLevel100 = $runtime->explore($user, (string) Str::uuid())['battle'];
        $this->assertSame([101, 101, 258_650, 500, 250, 225], [
            $aboveLevel100->combat_level_before,
            $aboveLevel100->combat_level_after,
            $aboveLevel100->combat_xp_after,
            $aboveLevel100->snapshot['unspent_stp_after'],
            $aboveLevel100->snapshot['current_hp_before'],
            $aboveLevel100->snapshot['current_hp_after'],
        ]);
        foreach ($combat->calls as $call) {
            $this->assertArrayNotHasKey('current_mp', $call['player_snapshot']);
        }
    }

    public function test_trial_progress_resumes_and_explicit_withdrawal_restarts_at_battle_one(): void
    {
        Carbon::setTestNow('2026-08-29 11:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        $this->unlockExploration($secretary);
        [$runtime] = $this->runtimeWithOutcomes(['player']);
        $run = $runtime->startTrial($user, 'trial_01');

        $runtime->fightTrial($user, $run->run_key, (string) Str::uuid());
        $resumed = app(UndergroundRuntimeService::class)->activeTrial($user);

        $this->assertSame($run->run_key, $resumed?->run_key);
        $this->assertSame('secretary-underground-trial-01-v1', $resumed?->trial_content_identity);
        $this->assertSame(2, $resumed?->next_battle_index);
        $withdrawn = $runtime->withdrawTrial($user, $run->run_key);
        $this->assertSame(UndergroundTrialRun::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertSame(1, $withdrawn->next_battle_index);
        $this->assertSame($withdrawn->id, $runtime->withdrawTrial($user, $run->run_key)->id);
        $this->assertNull(UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at);
        $this->assertSame([20, 20], [
            UndergroundProfile::query()->sole()->skill_points_total,
            UndergroundProfile::query()->sole()->skill_points_unspent,
        ]);

        $restarted = $runtime->startTrial($user, 'trial_01');
        $this->assertNotSame($run->run_key, $restarted->run_key);
        $this->assertSame(1, $restarted->next_battle_index);
    }

    public function test_active_trial_resets_only_for_its_own_content_identity_without_permanent_loss(): void
    {
        Carbon::setTestNow('2026-08-29 11:30:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        $this->unlockExploration($secretary);
        [$runtime] = $this->runtimeWithOutcomes(['player']);
        $run = $runtime->startTrial($user, 'trial_01');
        $profile = UndergroundProfile::query()->sole();
        $cooldownAt = Carbon::now()->subMinute();
        $profile->update([
            'combat_xp' => 77,
            'shard_balance' => 101,
            'unlocked_area_layers' => 3,
            'next_battle_at' => $cooldownAt,
        ]);
        $firstClearedAt = Carbon::now()->subDay();
        UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->update([
            'first_cleared_at' => $firstClearedAt,
        ]);
        $run->update(['next_battle_index' => 6]);

        $sameContent = $runtime->activeTrial($user);
        $this->assertSame([$run->run_key, 'secretary-underground-trial-01-v1', 6], [
            $sameContent?->run_key,
            $sameContent?->trial_content_identity,
            $sameContent?->next_battle_index,
        ]);

        config(['hakoniwa.application_version' => '3.0.0-alpha.2']);
        $applicationOnly = $runtime->activeTrial($user);
        $this->assertSame([$run->run_key, 'secretary-underground-trial-01-v1', 6], [
            $applicationOnly?->run_key,
            $applicationOnly?->trial_content_identity,
            $applicationOnly?->next_battle_index,
        ]);

        config(['underground-runtime.runtime_identity' => 'secretary-underground-runtime-alpha-v1']);
        $runtimeOnly = $runtime->activeTrial($user);
        $this->assertSame([$run->run_key, 'secretary-underground-trial-01-v1', 6], [
            $runtimeOnly?->run_key,
            $runtimeOnly?->trial_content_identity,
            $runtimeOnly?->next_battle_index,
        ]);

        config(['underground-runtime.trials.trial_01.content_identity' => 'trial-01-v2']);
        $projected = $runtime->projectTrialState($profile->refresh());
        $reset = $run->refresh();
        $this->assertSame([$run->run_key, 'trial-01-v2', 1, UndergroundTrialRun::STATUS_ACTIVE], [
            $projected['active_run']['run_key'],
            $reset->trial_content_identity,
            $projected['active_run']['next_battle_index'],
            $reset->status,
        ]);
        $this->assertSame([1, 77, 101, 3, $cooldownAt->toAtomString()], [
            $profile->refresh()->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->unlocked_area_layers,
            $profile->next_battle_at?->toAtomString(),
        ]);
        $this->assertTrue($firstClearedAt->equalTo(
            UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at,
        ));
        $this->assertSame(1, UndergroundTrialProgress::query()->count());

        $continued = $runtime->startTrial($user, 'trial_01');
        $this->assertSame([$run->run_key, 'trial-01-v2', 1], [
            $continued->run_key,
            $continued->trial_content_identity,
            $continued->next_battle_index,
        ]);
        $battle = $runtime->fightTrial($user, $run->run_key, (string) Str::uuid())['battle'];
        $this->assertSame('trial_rat_vanguard', $battle->encounter_key);
        $this->assertSame(2, $run->refresh()->next_battle_index);
        $this->assertSame('trial-01-v2', $run->trial_content_identity);
    }

    public function test_defeat_halves_odd_shards_and_ends_trial_with_progress_reset(): void
    {
        Carbon::setTestNow('2026-08-29 12:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        [$runtime] = $this->runtimeWithOutcomes(['enemy']);
        $profile = $this->unlockExploration($secretary);
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
        $this->assertNull(UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at);
        $this->assertNull($runtime->projectTrialBattle($battle)['first_clear_story']);
        $this->assertSame([20, 20], [$profile->skill_points_total, $profile->skill_points_unspent]);
        $this->assertSame(1, $runtime->startTrial($user, 'trial_01')->next_battle_index);
    }

    public function test_round_100_stalemate_awards_quarter_xp_without_shards_and_resets_only_trial_run(): void
    {
        Carbon::setTestNow('2026-08-29 13:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        [$runtime] = $this->runtimeWithOutcomes(['stalemate', 'stalemate']);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['combat_xp' => 25, 'shard_balance' => 101]);
        $this->unlockExploration($secretary, $profile);

        $exploration = $runtime->explore($user, (string) Str::uuid())['battle'];
        $explorationXp = intdiv($exploration->snapshot['encounter']['xp_reward'], 4);
        $this->assertSame([UndergroundBattle::RESULT_WITHDRAWAL, 100, $explorationXp, 0], [
            $exploration->result, $exploration->rounds, $exploration->xp_awarded, $exploration->shard_delta,
        ]);
        $this->assertSame([25 + $explorationXp, 101], [
            $profile->refresh()->combat_xp, $profile->shard_balance,
        ]);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $run = $runtime->startTrial($user, 'trial_01');
        $run->update(['next_battle_index' => 8]);

        $battle = $runtime->fightTrial($user, $run->run_key, (string) Str::uuid())['battle'];
        $trialXp = intdiv($battle->snapshot['encounter']['xp_reward'], 4);

        $this->assertSame([UndergroundBattle::RESULT_WITHDRAWAL, 100, $trialXp, 0], [
            $battle->result, $battle->rounds, $battle->xp_awarded, $battle->shard_delta,
        ]);
        $this->assertSame([25 + $explorationXp + $trialXp, 101], [
            $profile->refresh()->combat_xp, $profile->shard_balance,
        ]);
        $this->assertSame(UndergroundTrialRun::STATUS_WITHDRAWN, $run->refresh()->status);
        $this->assertSame(1, $run->next_battle_index);
        $this->assertNull($runtime->activeTrial($user));
        $this->assertNotNull($profile->next_battle_at);
        $this->assertNull(UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at);
        $this->assertNull($runtime->projectTrialBattle($battle)['first_clear_story']);
        $this->assertSame([20, 20], [$profile->skill_points_total, $profile->skill_points_unspent]);
        $this->assertSame(1, $runtime->startTrial($user, 'trial_01')->next_battle_index);
    }

    public function test_trial_first_clear_rewards_exactly_once_and_repeat_remains_available(): void
    {
        Carbon::setTestNow('2026-08-29 14:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser();
        $profile = $this->unlockExploration($secretary);
        $profile->update(['skill_points_total' => 20, 'skill_points_unspent' => 1]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'martial_precision_cut',
            'rank' => 1,
            'active_slot' => 1,
        ]);
        $outcomes = array_fill(0, 13, 'player');
        $outcomes[9] = ['winner' => 'player', 'rounds' => 20];
        $outcomes[10] = ['winner' => 'player', 'rounds' => 20];
        [$runtime, , $combat] = $this->runtimeWithOutcomes($outcomes);
        $run = $runtime->startTrial($user, 'trial_01');
        $mutationRequestId = (string) Str::uuid();
        UndergroundIntroRequest::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $mutationRequestId,
            'request_fingerprint' => hash('sha256', 'inn-rest'),
            'operation' => 'inn_rest',
            'resulting_stage' => 'underground_open',
        ]);
        $this->assertRuntimeError(
            'underground_request_conflict',
            fn () => $runtime->fightTrial($user, $run->run_key, $mutationRequestId),
        );
        $this->assertSame(1, $run->refresh()->next_battle_index);
        $this->assertSame(0, count($combat->calls));
        $bossRequestId = null;
        $firstProjection = null;
        $firstClearProjection = null;
        for ($battleIndex = 1; $battleIndex <= 10; $battleIndex++) {
            if ($battleIndex > 1) {
                Carbon::setTestNow(Carbon::now()->addSeconds(10));
            }
            $requestId = (string) Str::uuid();
            if ($battleIndex === 10) {
                config(['underground-runtime.combat.battle_log_retention_hours' => 2]);
                try {
                    $runtime->fightTrial($user, $run->run_key, $requestId);
                    $this->fail('Trial settlement should roll back when its result cannot be persisted.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Underground battle log retention must be exactly one hour.', $exception->getMessage());
                } finally {
                    config(['underground-runtime.combat.battle_log_retention_hours' => 1]);
                }
                $this->assertSame([430, 136, 20, 1], [
                    $profile->refresh()->combat_xp,
                    $profile->shard_balance,
                    $profile->skill_points_total,
                    $profile->skill_points_unspent,
                ]);
                $this->assertNull(UndergroundTrialProgress::query()
                    ->where('trial_key', 'trial_01')->sole()->first_cleared_at);
                $this->assertSame([UndergroundTrialRun::STATUS_ACTIVE, 10], [
                    $run->refresh()->status,
                    $run->next_battle_index,
                ]);
                $this->assertDatabaseMissing('underground_battles', ['request_id' => $requestId]);
            }
            $result = $runtime->fightTrial($user, $run->run_key, $requestId);
            if ($battleIndex === 1) {
                $firstProjection = $runtime->projectTrialBattle($result['battle']);
            }
            if ($battleIndex === 10) {
                $bossRequestId = $requestId;
                $firstClearProjection = $runtime->projectTrialBattle($result['battle']);
            }
        }

        $profile->refresh();
        $firstClear = UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at;
        $this->assertSame([800, 205, 60, 41, 0], [
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->skill_points_total,
            $profile->skill_points_unspent,
            $profile->unlocked_area_layers,
        ]);
        $this->assertNotNull($firstClear);
        $this->assertSame(1, UndergroundSkillAllocation::query()->count());
        $this->assertSame(800, (int) UndergroundBattle::query()
            ->where('trial_run_key', $run->run_key)->sum('xp_awarded'));
        $this->assertSame(205, (int) UndergroundBattle::query()
            ->where('trial_run_key', $run->run_key)->sum('shard_delta'));
        $this->assertSame(
            "　崩れかけた石壁の向こうに広がっていた不思議な空間。\n"
            ."　土と岩に埋もれたそこは、明らかに人の手で造られた古い石造りの遺跡であった。\n"
            .'　入り口からは生暖かい風が吹いている……そこが魔物の巣窟であることは、明らかであった。',
            $firstProjection['challenge_intro'],
        );
        $this->assertNull($firstProjection['first_clear_story']);
        $this->assertSame('『王女が逃げた、王女を探せ』', $firstClearProjection['first_clear_story']['title']);
        $this->assertSame([
            "{$secretary->name}は一つ目の封印の地を制覇した。",
            'SPを40入手した。',
        ], $firstClearProjection['first_clear_story']['system_messages']);
        $this->assertStringStartsWith('　ワイバーンの肉体が自らの魔力に耐え切れず', $firstClearProjection['first_clear_story']['body']);
        $this->assertStringEndsWith('「ただし、あなたがその力に溺れないという決意を見せてくれたらの話ですけれど、ね？」', $firstClearProjection['first_clear_story']['body']);
        $roundTwenty = collect($firstClearProjection['rounds'])->firstWhere('round', 20);
        $this->assertIsArray($roundTwenty);
        $this->assertSame('warning', $roundTwenty['actions'][0]['type']);
        $this->assertSame('洞窟が崩れそうだ……', $roundTwenty['actions'][0]['label']);

        $this->assertIsString($bossRequestId);
        $duplicate = $runtime->fightTrial($user, $run->run_key, $bossRequestId);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame([800, 205, 60, 41], [
            $profile->refresh()->combat_xp,
            $profile->shard_balance,
            $profile->skill_points_total,
            $profile->skill_points_unspent,
        ]);
        $this->assertSame(11, count($combat->calls));

        $repeat = $runtime->startTrial($user, 'trial_01');
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $repeatFirst = $runtime->fightTrial($user, $repeat->run_key, (string) Str::uuid())['battle'];
        $repeat->update(['next_battle_index' => 10]);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $repeatClear = $runtime->fightTrial($user, $repeat->run_key, (string) Str::uuid())['battle'];

        $this->assertSame([1210, 286, 60, 41, 0], [
            $profile->refresh()->combat_xp,
            $profile->shard_balance,
            $profile->skill_points_total,
            $profile->skill_points_unspent,
            $profile->unlocked_area_layers,
        ]);
        $this->assertTrue($firstClear?->equalTo(
            UndergroundTrialProgress::query()->where('trial_key', 'trial_01')->sole()->first_cleared_at,
        ) ?? false);
        $this->assertNull($runtime->projectTrialBattle($repeatFirst)['challenge_intro']);
        $this->assertNull($runtime->projectTrialBattle($repeatClear)['first_clear_story']);
        $this->assertSame(0, UndergroundTrialProgress::query()->where('trial_key', 'trial_02')->count());
        $this->assertSame(13, count($combat->calls));
    }

    public function test_expired_log_pruning_preserves_history_idempotency_and_user_ownership(): void
    {
        Carbon::setTestNow('2026-08-29 15:00:00+09:00');
        [$owner, $ownerSecretary] = $this->secretaryUser();
        [$other, $otherSecretary] = $this->secretaryUser();
        $this->unlockExploration($ownerSecretary);
        $this->unlockExploration($otherSecretary);
        [$runtime, , $combat] = $this->runtimeWithOutcomes(['player', 'player']);
        $requestId = (string) Str::uuid();
        $first = $runtime->explore($owner, $requestId)['battle'];

        $this->assertSame($first->id, $runtime->recentBattles($owner)->first()->id);
        $this->assertFalse($runtime->recentBattles($other)->contains('id', $first->id));
        $this->assertTrue(
            $first->log()->sole()->expires_at->equalTo($first->finished_at->addHour()),
        );
        Carbon::setTestNow(Carbon::now()->addHour());
        $future = $runtime->explore($other, (string) Str::uuid())['battle'];
        Carbon::setTestNow($first->finished_at->addHour());
        $expiredDuplicate = $runtime->explore($owner, $requestId);
        $expiredProjection = $runtime->projectExplorationBattle($expiredDuplicate['battle']);
        $this->assertTrue($expiredDuplicate['duplicate']);
        $this->assertSame($first->id, $expiredDuplicate['battle']->id);
        $this->assertNull($expiredDuplicate['battle']->log);
        $this->assertFalse($expiredProjection['detail_available']);
        $this->assertNull($expiredProjection['rounds']);
        $this->assertSame('詳細ログは保存期間を過ぎました。', $expiredProjection['detail_message']);
        $this->assertDatabaseHas('underground_battle_logs', ['underground_battle_id' => $first->id]);
        $this->artisan('underground:prune-battle-logs')
            ->expectsOutput('Pruned 1 expired Underground battle log(s).')
            ->assertSuccessful();
        $this->assertDatabaseMissing('underground_battle_logs', ['underground_battle_id' => $first->id]);
        $this->assertDatabaseHas('underground_battle_logs', ['underground_battle_id' => $future->id]);
        $this->assertDatabaseHas('underground_battles', ['id' => $first->id, 'request_id' => $requestId]);
        $retained = $runtime->recentBattles($owner)->firstWhere('id', $first->id);
        $this->assertInstanceOf(UndergroundBattle::class, $retained);
        $this->assertSame($first->id, $retained->id);
        $this->assertNull($retained->log);
        $summary = $first->refresh();
        $this->assertSame([7, 3, 0], [
            $summary->damage_dealt,
            $summary->damage_received,
            $summary->healing_done,
        ]);

        $duplicate = $runtime->explore($owner, $requestId);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first->id, $duplicate['battle']->id);
        $this->assertNull($duplicate['battle']->log);
        $this->assertSame(2, count($combat->calls));

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
        [$user, $secretary] = $this->secretaryUser();
        $this->unlockExploration($secretary);

        $this->assertInstanceOf(CanonicalUndergroundCombat::class, app(AtomicUndergroundCombat::class));
        $this->assertInstanceOf(
            CanonicalUndergroundExplorationCombat::class,
            app(AtomicUndergroundExplorationCombat::class),
        );
        $battle = app(UndergroundRuntimeService::class)
            ->explore($user, (string) Str::uuid())['battle'];

        $this->assertContains($battle->result, [
            UndergroundBattle::RESULT_VICTORY,
            UndergroundBattle::RESULT_DEFEAT,
            UndergroundBattle::RESULT_WITHDRAWAL,
        ]);
        $this->assertGreaterThanOrEqual(1, $battle->rounds);
        $this->assertLessThanOrEqual(100, $battle->rounds);
        $this->assertNotEmpty($battle->log?->actions);
        $this->assertSame(AlphaV1CombatRules::IDENTITY, $battle->snapshot['combat_rules_identity']);
    }

    /** @return array{User, Secretary} */
    private function secretaryUser(): array
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create([
            'user_id' => $user->id,
            'name' => 'Runtime secretary',
            'named_at' => Carbon::now(),
        ]);

        return [$user, $secretary];
    }

    /** @param list<'player'|'enemy'|'stalemate'|array{winner: 'player'|'enemy'|'stalemate', remaining_hp?: int, final_mp?: int, rounds?: int}> $outcomes
     * @return array{UndergroundRuntimeService, ScriptedUndergroundCombat, ScriptedUndergroundExplorationCombat}
     */
    private function runtimeWithOutcomes(array $outcomes): array
    {
        $combatOutcomes = array_map(
            static fn (string|array $outcome): string => is_array($outcome) ? $outcome['winner'] : $outcome,
            $outcomes,
        );
        $combat = new ScriptedUndergroundCombat($combatOutcomes);
        $explorationCombat = new ScriptedUndergroundExplorationCombat($outcomes);
        $this->app->instance(AtomicUndergroundCombat::class, $combat);
        $this->app->instance(AtomicUndergroundExplorationCombat::class, $explorationCombat);

        return [app(UndergroundRuntimeService::class), $combat, $explorationCombat];
    }

    private function unlockExploration(
        Secretary $secretary,
        ?UndergroundProfile $profile = null,
        string $growthPathKey = 'martial_red',
    ): UndergroundProfile {
        $profile ??= app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile = $profile->refresh();
        $contractAt = Carbon::now()->subMinute();
        $profile->update([
            'underground_contract_completed_at' => $contractAt,
            'growth_path_key' => $growthPathKey,
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'unspent_stp' => ($profile->combat_level - 1) * ($growthPathKey === 'free_black' ? 6 : 5),
        ]);
        $tutorial = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'secretary-underground-intro-alpha-v2',
            'activity_type' => UndergroundBattle::ACTIVITY_TUTORIAL,
            'activity_key' => 'tutorial',
            'encounter_key' => 'giant_rat',
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'xp_awarded' => 0,
            'shard_delta' => 0,
            'combat_level_before' => $profile->combat_level,
            'combat_level_after' => $profile->combat_level,
            'combat_xp_before' => $profile->combat_xp,
            'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $profile->shard_balance,
            'shard_balance_after' => $profile->shard_balance,
            'private_seed' => 1,
            'snapshot' => [],
            'started_at' => Carbon::now()->subHour(),
            'finished_at' => Carbon::now()->subHour(),
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $tutorial->id,
        ]);

        return $profile->refresh();
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

final class ScriptedUndergroundExplorationCombat implements AtomicUndergroundExplorationCombat
{
    /** @var list<array{enemy_key: string, seed: int, max_rounds: int, current_hp: int, player_snapshot: array<string, mixed>}> */
    public array $calls = [];

    /** @param list<'player'|'enemy'|'stalemate'|array{winner: 'player'|'enemy'|'stalemate', remaining_hp?: int, final_mp?: int, rounds?: int}> $outcomes */
    public function __construct(private array $outcomes) {}

    public function fight(
        AlphaV1BuildCatalog $catalog,
        array $playerSnapshot,
        string $enemyKey,
        int $seed,
        int $maxRounds,
        int $naturalRecovery,
    ): BuildCombatResult {
        $configured = array_shift($this->outcomes);
        if (! is_string($configured) && ! is_array($configured)) {
            throw new RuntimeException('Scripted Underground exploration outcome was not configured.');
        }
        $winner = is_array($configured) ? $configured['winner'] : $configured;
        $remainingHp = is_array($configured)
            ? ($configured['remaining_hp'] ?? ($winner === 'enemy' ? 0 : 100))
            : ($winner === 'enemy' ? 0 : 100);
        $finalMp = is_array($configured) ? ($configured['final_mp'] ?? AlphaV1CombatRules::MAX_MP) : AlphaV1CombatRules::MAX_MP;
        $rounds = is_array($configured)
            ? ($configured['rounds'] ?? ($winner === 'stalemate' ? $maxRounds : 3))
            : ($winner === 'stalemate' ? $maxRounds : 3);
        $this->calls[] = [
            'enemy_key' => $enemyKey,
            'seed' => $seed,
            'max_rounds' => $maxRounds,
            'current_hp' => (int) $playerSnapshot['current_hp'],
            'player_snapshot' => $playerSnapshot,
        ];

        return new BuildCombatResult(
            rulesIdentity: AlphaV1CombatRules::IDENTITY,
            generatorIdentity: AlphaV1CombatRules::GENERATOR_IDENTITY,
            seed: $seed,
            buildKey: 'secretary_runtime',
            enemyKey: $enemyKey,
            tierKey: 'runtime',
            winner: $winner,
            rounds: $rounds,
            playerRemainingHp: $remainingHp,
            enemyRemainingHp: $winner === 'player' ? 0 : 100,
            damageDealt: 7,
            damageReceived: 3,
            effectiveHealing: 0,
            damagePrevented: 0,
            mpSpent: 0,
            mpNaturalRecovery: $naturalRecovery,
            mpSkillRecovery: 0,
            mpOverflow: 0,
            mpExhaustionRound: null,
            skillUnavailableDueToMp: 0,
            emergencyHealOpportunities: 0,
            emergencyHealAvailable: 0,
            emergencyHealBlockedByMp: 0,
            crystalCycleRecovery: 0,
            finalMp: $finalMp,
            actionUsage: ['normal_attack' => 1],
            statusUptime: [],
            finalRoleStacks: ['fighting_spirit' => 0, 'grace' => 0],
            mpHistory: [],
            abnormalState: [],
            actionLog: [[
                'round' => $rounds,
                'kind' => 'effect',
                'side' => 'player',
                'target_side' => 'enemy',
                'action' => 'normal_attack',
                'amount' => 7,
                'effect_type' => 'damage',
            ]],
            generatedEquipment: [$playerSnapshot['equipment']],
        );
    }
}
