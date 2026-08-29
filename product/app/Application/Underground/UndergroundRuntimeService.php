<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use App\Domain\Underground\Combat\UndergroundRandom;
use App\Domain\Underground\Progression\UndergroundCombatProgression;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\UndergroundTrialRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UndergroundRuntimeService
{
    public function __construct(
        private UndergroundRuntimeCatalog $catalog,
        private AtomicUndergroundCombat $combat,
        private UndergroundCombatRules $combatRules,
        private UndergroundCombatProgression $progression,
        private UndergroundBattleSeed $battleSeed,
    ) {}

    /** @return array{battle: UndergroundBattle, duplicate: bool} */
    public function explore(User $user, string $huntingGroundKey, string $requestId): array
    {
        $this->assertRequestId($requestId);
        $ground = $this->catalog->huntingGround($huntingGroundKey);
        $fingerprint = $this->fingerprint([
            'activity_type' => 'exploration',
            'activity_key' => $huntingGroundKey,
        ]);

        return DB::transaction(function () use (
            $user,
            $huntingGroundKey,
            $requestId,
            $ground,
            $fingerprint,
        ): array {
            $profile = $this->lockedProfileForUser($user);
            $duplicate = $this->duplicateBattle($profile, $requestId, $fingerprint);
            if ($duplicate instanceof UndergroundBattle) {
                return ['battle' => $duplicate, 'duplicate' => true];
            }
            if ($this->lockedActiveTrialRun($profile) instanceof UndergroundTrialRun) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '試練を継続するか、明示的に帰還してから通常探索を行ってください。',
                );
            }
            if ($profile->combat_level < $ground['minimum_combat_level']) {
                throw new UndergroundRuntimeException(
                    'underground_hunting_ground_locked',
                    'この狩場へ入るための戦闘レベルが不足しています。',
                );
            }
            $this->assertCooldownElapsed($profile);
            $seed = $this->battleSeed->forRequest($profile->id, $requestId, $this->catalog->runtimeIdentity());
            $random = new UndergroundRandom($seed);
            $encounterIndex = $random->integer(
                'runtime:encounter:'.$huntingGroundKey,
                0,
                count($ground['encounters']) - 1,
            );
            $encounterKey = $ground['encounters'][$encounterIndex];

            return [
                'battle' => $this->resolveAndSettleBattle(
                    $profile,
                    $requestId,
                    $fingerprint,
                    'exploration',
                    $huntingGroundKey,
                    $encounterKey,
                    $seed,
                    null,
                    null,
                    false,
                ),
                'duplicate' => false,
            ];
        }, 3);
    }

    public function startTrial(User $user, string $trialKey): UndergroundTrialRun
    {
        $this->catalog->trial($trialKey);

        return DB::transaction(function () use ($user, $trialKey): UndergroundTrialRun {
            $profile = $this->lockedProfileForUser($user);
            $progress = UndergroundTrialProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->where('trial_key', $trialKey)
                ->lockForUpdate()
                ->first();
            if (! $progress instanceof UndergroundTrialProgress) {
                throw new UndergroundRuntimeException(
                    'underground_trial_locked',
                    'この試練はまだ解禁されていません。',
                );
            }

            $run = UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if ($run instanceof UndergroundTrialRun && $run->status === UndergroundTrialRun::STATUS_ACTIVE) {
                if ($run->trial_key !== $trialKey) {
                    throw new UndergroundRuntimeException(
                        'underground_trial_active',
                        '別の試練が進行中です。継続するか明示的に帰還してください。',
                    );
                }

                return $run;
            }

            $now = Carbon::now();
            if (! $run instanceof UndergroundTrialRun) {
                $run = new UndergroundTrialRun;
                $run->underground_profile_id = $profile->id;
            }
            $run->run_key = (string) Str::uuid();
            $run->trial_key = $trialKey;
            $run->next_battle_index = 1;
            $run->status = UndergroundTrialRun::STATUS_ACTIVE;
            $run->started_at = $now;
            $run->ended_at = null;
            $run->save();

            return $run->refresh();
        }, 3);
    }

    /** @return array{battle: UndergroundBattle, duplicate: bool} */
    public function fightTrial(User $user, string $runKey, string $requestId): array
    {
        $this->assertRequestId($runKey);
        $this->assertRequestId($requestId);
        $fingerprint = $this->fingerprint([
            'activity_type' => 'trial',
            'run_key' => $runKey,
        ]);

        return DB::transaction(function () use ($user, $runKey, $requestId, $fingerprint): array {
            $profile = $this->lockedProfileForUser($user);
            $duplicate = $this->duplicateBattle($profile, $requestId, $fingerprint);
            if ($duplicate instanceof UndergroundBattle) {
                return ['battle' => $duplicate, 'duplicate' => true];
            }

            $run = UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if (! $run instanceof UndergroundTrialRun
                || $run->status !== UndergroundTrialRun::STATUS_ACTIVE
                || $run->run_key !== $runKey) {
                throw new UndergroundRuntimeException(
                    'underground_trial_run_stale',
                    '試練の進行状態が更新されています。',
                );
            }
            $this->assertCooldownElapsed($profile);
            $trial = $this->catalog->trial($run->trial_key);
            $battleIndex = $run->next_battle_index;
            $encounterKey = $trial['encounters'][$battleIndex - 1] ?? null;
            if (! is_string($encounterKey)) {
                throw new UndergroundRuntimeException(
                    'underground_trial_progress_invalid',
                    '試練の進行状態を解決できません。',
                );
            }
            $seed = $this->battleSeed->forRequest($profile->id, $requestId, $this->catalog->runtimeIdentity());

            return [
                'battle' => $this->resolveAndSettleBattle(
                    $profile,
                    $requestId,
                    $fingerprint,
                    'trial',
                    $run->trial_key,
                    $encounterKey,
                    $seed,
                    $run,
                    $battleIndex,
                    $battleIndex === count($trial['encounters']),
                ),
                'duplicate' => false,
            ];
        }, 3);
    }

    public function withdrawTrial(User $user, string $runKey): UndergroundTrialRun
    {
        $this->assertRequestId($runKey);

        return DB::transaction(function () use ($user, $runKey): UndergroundTrialRun {
            $profile = $this->lockedProfileForUser($user);
            $run = UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if (! $run instanceof UndergroundTrialRun || $run->run_key !== $runKey) {
                throw new UndergroundRuntimeException(
                    'underground_trial_run_stale',
                    '試練の進行状態が更新されています。',
                );
            }
            if ($run->status === UndergroundTrialRun::STATUS_WITHDRAWN) {
                return $run;
            }
            if ($run->status !== UndergroundTrialRun::STATUS_ACTIVE) {
                throw new UndergroundRuntimeException(
                    'underground_trial_run_finished',
                    'この試練runはすでに終了しています。',
                );
            }

            $run->status = UndergroundTrialRun::STATUS_WITHDRAWN;
            $run->next_battle_index = 1;
            $run->ended_at = Carbon::now();
            $run->save();

            return $run->refresh();
        }, 3);
    }

    public function activeTrial(User $user): ?UndergroundTrialRun
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if (! $secretary instanceof Secretary) {
            return null;
        }
        $profile = UndergroundProfile::query()->where('secretary_id', $secretary->id)->first();
        if (! $profile instanceof UndergroundProfile) {
            return null;
        }

        return UndergroundTrialRun::query()
            ->where('underground_profile_id', $profile->id)
            ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
            ->first();
    }

    /** @return Collection<int, UndergroundBattle> */
    public function recentBattles(User $user, int $limit = 20): Collection
    {
        if ($limit < 1 || $limit > 100) {
            throw new UndergroundRuntimeException(
                'underground_history_limit_invalid',
                '戦闘履歴の取得件数を確認してください。',
            );
        }
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        $profile = $secretary instanceof Secretary
            ? UndergroundProfile::query()->where('secretary_id', $secretary->id)->first()
            : null;
        if (! $profile instanceof UndergroundProfile) {
            return new Collection;
        }

        return UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->with('log')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function pruneExpiredBattleLogs(): int
    {
        return UndergroundBattleLog::query()->where('expires_at', '<=', Carbon::now())->delete();
    }

    private function resolveAndSettleBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
        string $activityType,
        string $activityKey,
        string $encounterKey,
        int $seed,
        ?UndergroundTrialRun $trialRun,
        ?int $trialBattleIndex,
        bool $isTrialBoss,
    ): UndergroundBattle {
        $encounter = $this->catalog->encounter($encounterKey);
        if ($encounter['type'] !== 'combat') {
            throw new UndergroundRuntimeException(
                'underground_encounter_not_supported',
                'このencounter typeは現在のruntimeでは解決できません。',
            );
        }
        $actorKey = $this->catalog->actorKey();
        $loadout = $this->catalog->loadout();
        $aiPreset = $this->catalog->aiPreset();
        $maxRounds = $this->catalog->maxRounds();
        $actor = $this->combatRules->actor($actorKey);
        $enemy = $this->combatRules->enemy($encounter['enemy_key']);
        $startedAt = Carbon::now();
        $result = $this->combat->fight(
            $actorKey,
            $loadout,
            $encounter['enemy_key'],
            $aiPreset,
            $seed,
            $maxRounds,
        );
        $this->assertCombatResult(
            $result,
            $actorKey,
            $encounter['enemy_key'],
            $seed,
            $maxRounds,
        );
        if ($result->abnormalState !== []) {
            throw new UndergroundRuntimeException(
                'underground_combat_abnormal',
                '戦闘結果が不正な状態になったためsettlementを取り消しました。',
            );
        }
        $finishedAt = Carbon::now();
        $resultType = match ($result->winner) {
            'player' => UndergroundBattle::RESULT_VICTORY,
            'enemy' => UndergroundBattle::RESULT_DEFEAT,
            'stalemate' => UndergroundBattle::RESULT_WITHDRAWAL,
        };
        $levelBefore = $profile->combat_level;
        $xpBefore = $profile->combat_xp;
        $shardsBefore = $profile->shard_balance;
        $xpAwarded = 0;
        $shardDelta = 0;

        if ($resultType === UndergroundBattle::RESULT_VICTORY) {
            $xpAwarded = $encounter['xp'];
            $shardDelta = $encounter['shards'];
            $profile->combat_xp += $xpAwarded;
            $profile->shard_balance += $shardDelta;
            $curve = $this->catalog->xpCurve();
            $profile->combat_level = $this->progression->levelAfterXp(
                $profile->combat_level,
                $profile->combat_xp,
                $curve['first_level_cost'],
                $curve['cost_increment_per_level'],
            );
        } elseif ($resultType === UndergroundBattle::RESULT_DEFEAT) {
            $profile->shard_balance = intdiv($profile->shard_balance, 2);
            $shardDelta = $profile->shard_balance - $shardsBefore;
        }

        if ($trialRun instanceof UndergroundTrialRun) {
            $this->settleTrial($profile, $trialRun, $resultType, $isTrialBoss, $finishedAt);
        }
        $profile->next_battle_at = $finishedAt->copy()->addSeconds($this->catalog->cooldownSeconds());
        $profile->save();

        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => $fingerprint,
            'runtime_identity' => $this->catalog->runtimeIdentity(),
            'activity_type' => $activityType,
            'activity_key' => $activityKey,
            'encounter_key' => $encounterKey,
            'trial_run_key' => $trialRun?->run_key,
            'trial_battle_index' => $trialBattleIndex,
            'result' => $resultType,
            'rounds' => $result->rounds,
            'xp_awarded' => $xpAwarded,
            'shard_delta' => $shardDelta,
            'combat_level_before' => $levelBefore,
            'combat_level_after' => $profile->combat_level,
            'combat_xp_before' => $xpBefore,
            'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $shardsBefore,
            'shard_balance_after' => $profile->shard_balance,
            'private_seed' => $seed,
            'snapshot' => [
                'runtime_identity' => $this->catalog->runtimeIdentity(),
                'combat_rules_identity' => $result->rulesIdentity,
                'actor' => $actor,
                'loadout' => $loadout,
                'ai_preset' => $aiPreset,
                'combat_level' => $levelBefore,
                'enemy' => $enemy,
                'encounter' => [
                    'key' => $encounterKey,
                    'type' => $encounter['type'],
                    'xp_reward' => $encounter['xp'],
                    'shard_reward' => $encounter['shards'],
                ],
                'xp_curve' => $this->catalog->xpCurve(),
                'max_rounds' => $maxRounds,
                'is_trial_boss' => $isTrialBoss,
            ],
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $this->playerFacingActionLog($result),
            'expires_at' => $finishedAt->copy()->addHours($this->catalog->battleLogRetentionHours()),
        ]);

        return $battle->load('log');
    }

    private function settleTrial(
        UndergroundProfile $profile,
        UndergroundTrialRun $run,
        string $resultType,
        bool $isTrialBoss,
        Carbon $finishedAt,
    ): void {
        if ($resultType === UndergroundBattle::RESULT_WITHDRAWAL) {
            return;
        }
        if ($resultType === UndergroundBattle::RESULT_DEFEAT) {
            $run->status = UndergroundTrialRun::STATUS_DEFEATED;
            $run->next_battle_index = 1;
            $run->ended_at = $finishedAt;
            $run->save();

            return;
        }
        if (! $isTrialBoss) {
            $run->next_battle_index++;
            $run->save();

            return;
        }

        $progress = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', $run->trial_key)
            ->lockForUpdate()
            ->firstOrFail();
        if ($progress->first_cleared_at === null) {
            $progress->first_cleared_at = $finishedAt;
            $progress->save();
            $profile->unlocked_area_layers++;
            $nextTrialKey = $this->catalog->nextTrialKey($run->trial_key);
            if ($nextTrialKey !== null) {
                UndergroundTrialProgress::query()->firstOrCreate(
                    [
                        'underground_profile_id' => $profile->id,
                        'trial_key' => $nextTrialKey,
                    ],
                    ['unlocked_at' => $finishedAt],
                );
            }
        }
        $run->status = UndergroundTrialRun::STATUS_CLEARED;
        $run->next_battle_index = 1;
        $run->ended_at = $finishedAt;
        $run->save();
    }

    private function lockedProfileForUser(User $user): UndergroundProfile
    {
        $secretary = Secretary::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if (! $secretary instanceof Secretary) {
            throw new UndergroundRuntimeException(
                'underground_secretary_missing',
                '秘書がまだ作成されていません。',
            );
        }
        UndergroundProfile::query()->firstOrCreate(['secretary_id' => $secretary->id]);
        $profile = UndergroundProfile::query()
            ->where('secretary_id', $secretary->id)
            ->lockForUpdate()
            ->firstOrFail();
        UndergroundTrialProgress::query()->firstOrCreate(
            [
                'underground_profile_id' => $profile->id,
                'trial_key' => $this->catalog->firstTrialKey(),
            ],
            ['unlocked_at' => Carbon::now()],
        );

        return $profile;
    }

    private function lockedActiveTrialRun(UndergroundProfile $profile): ?UndergroundTrialRun
    {
        return UndergroundTrialRun::query()
            ->where('underground_profile_id', $profile->id)
            ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
    }

    private function duplicateBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
    ): ?UndergroundBattle {
        $battle = UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if (! $battle instanceof UndergroundBattle) {
            return null;
        }
        if (! hash_equals($battle->request_fingerprint, $fingerprint)) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の戦闘に使用されています。',
            );
        }

        return $battle->load('log');
    }

    private function assertCooldownElapsed(UndergroundProfile $profile): void
    {
        if ($profile->next_battle_at !== null && $profile->next_battle_at->isAfter(Carbon::now())) {
            throw new UndergroundRuntimeException(
                'underground_battle_cooldown',
                '次の戦闘を開始できる時刻まで待ってください。',
            );
        }
    }

    private function assertRequestId(string $requestId): void
    {
        if (! Str::isUuid($requestId)) {
            throw new UndergroundRuntimeException(
                'underground_request_id_invalid',
                'request IDを確認してください。',
            );
        }
    }

    private function assertCombatResult(
        CombatResult $result,
        string $actorKey,
        string $enemyKey,
        int $seed,
        int $maxRounds,
    ): void {
        if ($result->rulesIdentity !== UndergroundCombatRules::IDENTITY
            || $result->actorKey !== $actorKey
            || $result->enemyKey !== $enemyKey
            || $result->seed !== $seed
            || $result->rounds < 1
            || $result->rounds > $maxRounds) {
            throw new UndergroundRuntimeException(
                'underground_combat_result_invalid',
                '戦闘結果を検証できなかったためsettlementを取り消しました。',
            );
        }
    }

    /** @param array<string, string> $intent */
    private function fingerprint(array $intent): string
    {
        ksort($intent);

        return hash('sha256', json_encode([
            'runtime_identity' => $this->catalog->runtimeIdentity(),
            'intent' => $intent,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array<string, int|string|bool>> */
    private function playerFacingActionLog(CombatResult $result): array
    {
        return array_map(static function (array $row): array {
            $amount = (int) ($row['amount'] ?? 0);

            return [
                'round' => (int) ($row['round'] ?? 0),
                'side' => (string) ($row['side'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'effect' => $amount < 0 ? 'recovery' : ($amount > 0 ? 'damage' : 'none'),
                'amount' => abs($amount),
                'guarded' => (bool) ($row['guarded'] ?? false),
                'player_hp' => (int) ($row['player_hp'] ?? 0),
                'enemy_hp' => (int) ($row['enemy_hp'] ?? 0),
                'player_resource' => (int) ($row['player_resource'] ?? 0),
            ];
        }, $result->actionLog);
    }
}
