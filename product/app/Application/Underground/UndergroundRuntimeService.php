<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\UndergroundRandom;
use App\Domain\Underground\Intro\UndergroundIntroStage;
use App\Domain\Underground\Progression\UndergroundCombatProgression;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
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
    private const FIRST_CLEAR_STORY_TITLE = '『王女が逃げた、王女を探せ』';

    private const FIRST_CHALLENGE_INTRO = <<<'STORY'
　崩れかけた石壁の向こうに広がっていた不思議な空間。
　土と岩に埋もれたそこは、明らかに人の手で造られた古い石造りの遺跡であった。
　入り口からは生暖かい風が吹いている……そこが魔物の巣窟であることは、明らかであった。
STORY;

    private const FIRST_CLEAR_STORY_BODY = <<<'STORY'
　ワイバーンの肉体が自らの魔力に耐え切れず、内から光を放ちながら崩壊していくその瞬間。
　秘書の中で何かが強く脈打った。ドクン、ドクンと、全身の細胞が歓喜に震え、肉体の輪郭が歪んでいく幻覚が見える。
　膝をつき、堕としてしまった武器を拾い上げたのは、あの案内人であった。
「大丈夫ですか？」
　秘書をゆっくりと立たせ、武器を返した案内人はふぅと大きなため息をついた。
「いくら狭くて飛び辛い有利な環境とはいえワイバーンまで倒すとは……無茶苦茶というか素晴らしいというべきなのか——」
　次の瞬間、頭上から重く大きな地響きが鳴り、辺りの空気が一変する。
「やはりですね。封印の地と呼ばれる……この辺りの魔物を倒しきったから、バリアが下がりました。つまりはもう封印している必要がなくなったということです」
　どういうことかと問えば、彼女は金色の瞳を細めて答えた。
「あなたがたの島の設備を置ける空間ができたということですよ。大したスペースはありませんが元は封印された地です。あらゆる災害から守られた聖地となるでしょう。ところで……あなたがあのネズ公を倒したときに手に入れた輝石、持ってますか？」
　秘書は輝石を取り出して、案内人に見せる。
「よろしい。とてもよろしい」
「あなたはこれからもっと深くの封印の地を解放していくのでしょう。私がそれの使い方を手解きしてあげましょう♪」

　そういうと、案内人は自分の輝石を取り出してみせた。
　それは桃色の、妖しく輝く楕円形の宝石であった。

「ただし、あなたがその力に溺れないという決意を見せてくれたらの話ですけれど、ね？」
STORY;

    public function __construct(
        private UndergroundRuntimeCatalog $catalog,
        private AtomicUndergroundExplorationCombat $explorationCombat,
        private UndergroundCombatProgression $progression,
        private UndergroundBattleSeed $battleSeed,
        private UndergroundAlphaV1PlayerCatalog $alphaV1Catalog,
        private UndergroundAlphaV1BattleProjector $alphaV1Projector,
        private UndergroundStarterEquipmentService $starterEquipment,
        private UndergroundEquipmentLoadoutResolver $equipmentLoadout,
    ) {}

    /** @return array{battle: UndergroundBattle, duplicate: bool} */
    public function explore(User $user, string $requestId): array
    {
        $this->assertRequestId($requestId);
        $fingerprint = $this->fingerprint([
            'activity_type' => 'exploration',
            'activity_key' => $this->alphaV1Catalog->explorationHuntingGroundKey(),
            'exploration_identity' => $this->alphaV1Catalog->explorationIdentity(),
        ]);

        return DB::transaction(function () use (
            $user,
            $requestId,
            $fingerprint,
        ): array {
            $profile = $this->lockedProfileForUser($user);
            $this->assertExplorationUnlocked($profile);
            $this->assertRequestNotUsedByIntro($profile, $requestId);
            $duplicate = $this->duplicateBattle($profile, $requestId, $fingerprint);
            if ($duplicate instanceof UndergroundBattle) {
                return ['battle' => $duplicate, 'duplicate' => true];
            }
            if ($this->lockedActiveTrialRun($profile) instanceof UndergroundTrialRun) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '封印の地を継続するか、明示的に帰還してから通常探索を行ってください。',
                );
            }
            $this->assertCooldownElapsed($profile);
            $seed = $this->battleSeed->forRequest(
                $profile->id,
                $requestId,
                $this->alphaV1Catalog->explorationIdentity(),
            );
            $random = new UndergroundRandom($seed);
            $encounterKey = $this->alphaV1Catalog->weightedExplorationEncounter(
                $random->integer(
                    'runtime:encounter:'.$this->alphaV1Catalog->explorationHuntingGroundKey(),
                    1,
                    10_000,
                ),
            );

            return [
                'battle' => $this->resolveAndSettleExplorationBattle(
                    $profile,
                    $requestId,
                    $fingerprint,
                    $encounterKey,
                    $seed,
                ),
                'duplicate' => false,
            ];
        }, 3);
    }

    public function startTrial(User $user, string $trialKey): UndergroundTrialRun
    {
        $trial = $this->catalog->trial($trialKey);

        return DB::transaction(function () use ($user, $trialKey, $trial): UndergroundTrialRun {
            $profile = $this->lockedProfileForUser($user);
            $this->assertExplorationUnlocked($profile);
            UndergroundTrialProgress::query()->firstOrCreate(
                [
                    'underground_profile_id' => $profile->id,
                    'trial_key' => $this->catalog->firstTrialKey(),
                ],
                ['unlocked_at' => Carbon::now()],
            );
            $progress = UndergroundTrialProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->where('trial_key', $trialKey)
                ->lockForUpdate()
                ->first();
            if (! $progress instanceof UndergroundTrialProgress) {
                throw new UndergroundRuntimeException(
                    'underground_trial_locked',
                    'この封印の地はまだ解禁されていません。',
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
                        '別の封印の地が進行中です。継続するか明示的に帰還してください。',
                    );
                }

                return $this->reconcileActiveTrialContent($run, $trial['content_identity']);
            }

            $now = Carbon::now();
            if (! $run instanceof UndergroundTrialRun) {
                $run = new UndergroundTrialRun;
                $run->underground_profile_id = $profile->id;
            }
            $run->run_key = (string) Str::uuid();
            $run->trial_key = $trialKey;
            $run->trial_content_identity = $trial['content_identity'];
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
            $this->assertRequestNotUsedByIntro($profile, $requestId);
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
                    '封印の地の進行状態が更新されています。',
                );
            }
            $trial = $this->catalog->trial($run->trial_key);
            $run = $this->reconcileActiveTrialContent($run, $trial['content_identity']);
            $this->assertCooldownElapsed($profile);
            $battleIndex = $run->next_battle_index;
            $encounterKey = $trial['encounters'][$battleIndex - 1] ?? null;
            if (! is_string($encounterKey)) {
                throw new UndergroundRuntimeException(
                    'underground_trial_progress_invalid',
                    '封印の地の進行状態を解決できません。',
                );
            }
            $seed = $this->battleSeed->forRequest($profile->id, $requestId, $trial['content_identity']);

            return [
                'battle' => $this->resolveAndSettleTrialBattle(
                    $profile,
                    $requestId,
                    $fingerprint,
                    $trial,
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
                    '封印の地の進行状態が更新されています。',
                );
            }
            if ($run->status === UndergroundTrialRun::STATUS_WITHDRAWN) {
                return $run;
            }
            if ($run->status !== UndergroundTrialRun::STATUS_ACTIVE) {
                throw new UndergroundRuntimeException(
                    'underground_trial_run_finished',
                    'この封印の地の挑戦はすでに終了しています。',
                );
            }
            $trial = $this->catalog->trial($run->trial_key);
            $run = $this->reconcileActiveTrialContent($run, $trial['content_identity']);

            $run->status = UndergroundTrialRun::STATUS_WITHDRAWN;
            $run->next_battle_index = 1;
            $run->ended_at = Carbon::now();
            $run->save();

            return $run->refresh();
        }, 3);
    }

    public function activeTrial(User $user): ?UndergroundTrialRun
    {
        return DB::transaction(function () use ($user): ?UndergroundTrialRun {
            $secretary = Secretary::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $secretary instanceof Secretary) {
                return null;
            }
            $profile = UndergroundProfile::query()
                ->where('secretary_id', $secretary->id)
                ->lockForUpdate()
                ->first();
            if (! $profile instanceof UndergroundProfile) {
                return null;
            }

            return $this->lockedActiveTrialRun($profile);
        }, 3);
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
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function pruneExpiredBattleLogs(): int
    {
        return UndergroundBattleLog::query()->where('expires_at', '<=', Carbon::now())->delete();
    }

    /** @return array<string, mixed> */
    public function projectExplorationBattle(UndergroundBattle $battle, bool $withRounds = true): array
    {
        return $this->projectAlphaV1Battle($battle, UndergroundBattle::ACTIVITY_EXPLORATION, $withRounds);
    }

    /** @return array<string, mixed> */
    public function projectTrialBattle(UndergroundBattle $battle, bool $withRounds = true): array
    {
        return $this->projectAlphaV1Battle($battle, UndergroundBattle::ACTIVITY_TRIAL, $withRounds);
    }

    /** @return array<string, mixed> */
    public function projectTrialRun(UndergroundTrialRun $run): array
    {
        $trial = $this->catalog->trial($run->trial_key);

        return [
            'key' => $run->trial_key,
            'label' => $trial['label'],
            'run_key' => $run->run_key,
            'status' => $run->status,
            'next_battle_index' => $run->next_battle_index,
            'total_battles' => count($trial['encounters']),
        ];
    }

    /** @return array<string, mixed> */
    public function projectTrialState(UndergroundProfile $profile): array
    {
        $trialKey = $this->catalog->firstTrialKey();
        $trial = $this->catalog->trial($trialKey);
        $progress = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', $trialKey)
            ->first();
        $run = UndergroundTrialRun::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', $trialKey)
            ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
            ->first();

        return [
            'key' => $trialKey,
            'label' => $trial['label'],
            'total_battles' => count($trial['encounters']),
            'first_cleared' => $progress?->first_cleared_at !== null,
            'active_run' => $run instanceof UndergroundTrialRun ? $this->projectTrialRun($run) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function projectAlphaV1Battle(
        UndergroundBattle $battle,
        string $context,
        bool $withRounds,
    ): array {
        $snapshot = $battle->snapshot;
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $log = $battle->relationLoaded('log') && $battle->getRelation('log') instanceof UndergroundBattleLog
            ? $battle->getRelation('log')
            : null;
        $hasPresentationLog = ($snapshot['presentation_log_version'] ?? null) === 1
            && $log instanceof UndergroundBattleLog;

        return [
            'id' => $battle->request_id,
            'context' => $context,
            'player_display_name' => is_string($snapshot['player_display_name'] ?? null)
                ? $snapshot['player_display_name']
                : '秘書',
            'encounter_name' => is_string($snapshot['encounter_display_name'] ?? null)
                ? $snapshot['encounter_display_name']
                : '地下の敵',
            'enemy_key' => $battle->encounter_key,
            'result' => $battle->result,
            'rounds_count' => $battle->rounds,
            'xp_awarded' => $battle->xp_awarded,
            'shard_delta' => $battle->shard_delta,
            'combat_level_before' => $battle->combat_level_before,
            'combat_level_after' => $battle->combat_level_after,
            'combat_xp_before' => $battle->combat_xp_before,
            'combat_xp_after' => $battle->combat_xp_after,
            'stp_awarded' => (int) ($snapshot['stp_awarded'] ?? 0),
            'unspent_stp_after' => (int) ($snapshot['unspent_stp_after'] ?? 0),
            'current_hp_before' => (int) ($snapshot['current_hp_before'] ?? 0),
            'current_hp_after' => (int) ($snapshot['current_hp_after'] ?? 0),
            'max_hp_after' => (int) ($snapshot['max_hp_after'] ?? 0),
            'summary' => $summary,
            'rounds' => $withRounds && $hasPresentationLog ? $log->actions : null,
            'detail_available' => $withRounds
                ? $hasPresentationLog
                : (bool) ($battle->getAttribute('active_log_exists') ?? false),
            'detail_message' => $withRounds && ! $hasPresentationLog
                ? '詳細ログは保存期間を過ぎました。'
                : null,
            'finished_at' => $battle->finished_at->toAtomString(),
            'rewards' => ['xp' => $battle->xp_awarded, 'shards' => $battle->shard_delta],
            'trial_key' => $context === UndergroundBattle::ACTIVITY_TRIAL ? $battle->activity_key : null,
            'trial_run_key' => $context === UndergroundBattle::ACTIVITY_TRIAL ? $battle->trial_run_key : null,
            'trial_battle_index' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? $battle->trial_battle_index
                : null,
            'trial_total_battles' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? (int) ($snapshot['trial_total_battles'] ?? 0)
                : null,
            'trial_status' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? ($snapshot['trial_status'] ?? null)
                : null,
            'trial_next_battle_index' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? ($snapshot['trial_next_battle_index'] ?? null)
                : null,
            'first_clear_story' => $context === UndergroundBattle::ACTIVITY_TRIAL
                && is_array($snapshot['first_clear_story'] ?? null)
                    ? $snapshot['first_clear_story']
                    : null,
            'challenge_intro' => $context === UndergroundBattle::ACTIVITY_TRIAL
                && is_string($snapshot['challenge_intro'] ?? null)
                    ? $snapshot['challenge_intro']
                    : null,
        ];
    }

    private function resolveAndSettleExplorationBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
        string $encounterKey,
        int $seed,
    ): UndergroundBattle {
        $encounter = $this->alphaV1Catalog->explorationEncounter($encounterKey);
        $secretary = $profile->secretary;
        if (! is_string($secretary->name) || $secretary->name === '') {
            throw new UndergroundRuntimeException(
                'underground_secretary_missing',
                '名前のある秘書が必要です。',
            );
        }
        if (! is_string($profile->growth_path_key)) {
            throw new UndergroundRuntimeException(
                'underground_exploration_locked',
                '周囲の探索はまだ解禁されていません。',
            );
        }
        $equipment = $this->equipmentLoadout->combatLoadout($profile);
        $maxHpBefore = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $currentHpBefore = min($profile->current_hp ?? $maxHpBefore, $maxHpBefore);
        $definition = $this->alphaV1Catalog->explorationCombatDefinition(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
            $secretary->name,
            $currentHpBefore,
            $profile->skillAllocationMap(),
        );
        $growthPath = $this->alphaV1Catalog->growthPath($profile->growth_path_key);
        $maxRounds = $this->alphaV1Catalog->explorationMaxRounds();
        $startedAt = Carbon::now();
        $result = $this->explorationCombat->fight(
            $definition['catalog'],
            $definition['player_snapshot'],
            $encounterKey,
            $seed,
            $maxRounds,
            (int) $growthPath['natural_recovery'],
        );
        $this->assertExplorationCombatResult($result, $encounterKey, $seed, $maxRounds, $maxHpBefore);
        $finishedAt = Carbon::now();
        $resultType = match ($result->winner) {
            'player' => UndergroundBattle::RESULT_VICTORY,
            'enemy' => UndergroundBattle::RESULT_DEFEAT,
            default => UndergroundBattle::RESULT_WITHDRAWAL,
        };
        $levelBefore = $profile->combat_level;
        $xpBefore = $profile->combat_xp;
        $shardsBefore = $profile->shard_balance;
        $unspentStpBefore = $profile->unspent_stp;
        $xpAwarded = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $encounter['xp'],
            UndergroundBattle::RESULT_WITHDRAWAL => intdiv($encounter['xp'], 4),
            default => 0,
        };
        $shardDelta = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $encounter['shards'],
            UndergroundBattle::RESULT_DEFEAT => intdiv($profile->shard_balance, 2) - $profile->shard_balance,
            default => 0,
        };
        $profile->combat_xp += $xpAwarded;
        $profile->shard_balance += $shardDelta;
        $curve = $this->catalog->xpCurve();
        $profile->combat_level = $this->progression->levelAfterXp(
            $profile->combat_level,
            $profile->combat_xp,
            $curve['first_level_cost'],
            $curve['cost_increment_per_level'],
        );
        $stpAwarded = $this->settleLevelStp($profile, $levelBefore);
        $maxHpAfter = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $profile->current_hp = $resultType === UndergroundBattle::RESULT_DEFEAT
            ? $maxHpAfter
            : min($result->playerRemainingHp, $maxHpAfter);
        $profile->next_battle_at = $finishedAt->copy()->addSeconds($this->catalog->cooldownSeconds());
        $profile->save();

        $projection = $this->alphaV1Projector->project(
            $result,
            $definition['catalog'],
            $secretary->name,
            $encounter['label'],
        );
        $projection['summary']['result'] = $resultType;
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => $fingerprint,
            'runtime_identity' => $this->alphaV1Catalog->explorationIdentity(),
            'activity_type' => UndergroundBattle::ACTIVITY_EXPLORATION,
            'activity_key' => $this->alphaV1Catalog->explorationHuntingGroundKey(),
            'encounter_key' => $encounterKey,
            'trial_run_key' => null,
            'trial_battle_index' => null,
            'result' => $resultType,
            'rounds' => $result->rounds,
            'damage_dealt' => $result->damageDealt,
            'damage_received' => $result->damageReceived,
            'healing_done' => $result->effectiveHealing,
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
                'exploration_identity' => $this->alphaV1Catalog->explorationIdentity(),
                'combat_rules_identity' => $result->rulesIdentity,
                'player_display_name' => $secretary->name,
                'encounter_display_name' => $encounter['label'],
                'presentation_log_version' => 1,
                'summary' => $projection['summary'],
                'growth_path_key' => $profile->growth_path_key,
                'growth_path_identity' => $profile->growth_path_identity,
                'progression_stats' => $definition['progression_stats'],
                'combat_stats' => $definition['combat_stats'],
                'allocated_stp' => $profile->allocatedStp(),
                'equipment' => $definition['equipment'],
                'skill_tree_identity' => $profile->skill_tree_identity,
                'targeting_contract_identity' => $this->alphaV1Catalog->targetingIdentity(),
                'acquired_skill_nodes' => $definition['acquired_nodes'],
                'equipped_active_skills' => $definition['active_skills'],
                'effective_passive_modifiers' => $definition['passive_modifiers'],
                'encounter' => [
                    'key' => $encounterKey,
                    'weight_bps' => $encounter['weight'],
                    'xp_reward' => $encounter['xp'],
                    'shard_reward' => $encounter['shards'],
                ],
                'xp_curve' => $curve,
                'max_rounds' => $maxRounds,
                'battle_start_mp' => AlphaV1CombatRules::MAX_MP,
                'unspent_stp_before' => $unspentStpBefore,
                'unspent_stp_after' => $profile->unspent_stp,
                'stp_awarded' => $stpAwarded,
                'current_hp_before' => $currentHpBefore,
                'max_hp_before' => $maxHpBefore,
                'current_hp_after' => $profile->current_hp,
                'max_hp_after' => $maxHpAfter,
                'banked_shard_balance' => $profile->banked_shard_balance,
            ],
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $projection['rounds'],
            'expires_at' => $finishedAt->copy()->addHours($this->catalog->battleLogRetentionHours()),
        ]);

        return $battle->load('log');
    }

    /**
     * @param array{
     *   label: string,
     *   content_identity: string,
     *   interbattle_heal_bps: int,
     *   first_clear_skill_points: int,
     *   encounters: list<string>,
     *   rewards: list<array{xp: int, shards: int}>
     * } $trial
     */
    private function resolveAndSettleTrialBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
        array $trial,
        string $encounterKey,
        int $seed,
        UndergroundTrialRun $trialRun,
        int $trialBattleIndex,
        bool $isTrialBoss,
    ): UndergroundBattle {
        $secretary = $profile->secretary;
        if (! is_string($secretary->name) || $secretary->name === '') {
            throw new UndergroundRuntimeException(
                'underground_secretary_missing',
                '名前のある秘書が必要です。',
            );
        }
        if (! is_string($profile->growth_path_key)) {
            throw new UndergroundRuntimeException(
                'underground_trial_locked',
                '地下に眠る古代遺跡はまだ解禁されていません。',
            );
        }
        $reward = $trial['rewards'][$trialBattleIndex - 1] ?? null;
        if (! is_array($reward)) {
            throw new UndergroundRuntimeException(
                'underground_trial_progress_invalid',
                '封印の地の報酬を解決できません。',
            );
        }
        $equipment = $this->equipmentLoadout->combatLoadout($profile);
        $maxHpBefore = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $currentHpBefore = min($profile->current_hp ?? $maxHpBefore, $maxHpBefore);
        $definition = $this->alphaV1Catalog->trialOneCombatDefinition(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
            $secretary->name,
            $currentHpBefore,
            $profile->skillAllocationMap(),
        );
        $enemy = $definition['catalog']->enemy($encounterKey);
        $encounterLabel = $enemy['label'] ?? null;
        if (! is_string($encounterLabel) || $encounterLabel === '') {
            throw new UndergroundRuntimeException(
                'underground_trial_progress_invalid',
                '封印の地の対戦相手を解決できません。',
            );
        }
        $growthPath = $this->alphaV1Catalog->growthPath($profile->growth_path_key);
        $maxRounds = $this->catalog->maxRounds();
        $startedAt = Carbon::now();
        $result = $this->explorationCombat->fight(
            $definition['catalog'],
            $definition['player_snapshot'],
            $encounterKey,
            $seed,
            $maxRounds,
            (int) $growthPath['natural_recovery'],
        );
        $this->assertExplorationCombatResult($result, $encounterKey, $seed, $maxRounds, $maxHpBefore);
        $finishedAt = Carbon::now();
        $resultType = match ($result->winner) {
            'player' => UndergroundBattle::RESULT_VICTORY,
            'enemy' => UndergroundBattle::RESULT_DEFEAT,
            default => UndergroundBattle::RESULT_WITHDRAWAL,
        };
        $levelBefore = $profile->combat_level;
        $xpBefore = $profile->combat_xp;
        $shardsBefore = $profile->shard_balance;
        $unspentStpBefore = $profile->unspent_stp;
        $xpAwarded = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $reward['xp'],
            UndergroundBattle::RESULT_WITHDRAWAL => intdiv($reward['xp'], 4),
            default => 0,
        };
        $shardDelta = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $reward['shards'],
            UndergroundBattle::RESULT_DEFEAT => intdiv($profile->shard_balance, 2) - $profile->shard_balance,
            default => 0,
        };
        $profile->combat_xp += $xpAwarded;
        $profile->shard_balance += $shardDelta;
        $curve = $this->catalog->xpCurve();
        $profile->combat_level = $this->progression->levelAfterXp(
            $profile->combat_level,
            $profile->combat_xp,
            $curve['first_level_cost'],
            $curve['cost_increment_per_level'],
        );
        $stpAwarded = $this->settleLevelStp($profile, $levelBefore);
        $maxHpAfter = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $profile->current_hp = match ($resultType) {
            UndergroundBattle::RESULT_DEFEAT => $maxHpAfter,
            UndergroundBattle::RESULT_VICTORY => $isTrialBoss
                ? min($result->playerRemainingHp, $maxHpAfter)
                : min(
                    $maxHpAfter,
                    $result->playerRemainingHp + intdiv(
                        $maxHpAfter * $trial['interbattle_heal_bps'],
                        10_000,
                    ),
                ),
            default => min($result->playerRemainingHp, $maxHpAfter),
        };
        $firstClear = $this->settleTrial($profile, $trialRun, $resultType, $isTrialBoss, $finishedAt);
        $profile->next_battle_at = $finishedAt->copy()->addSeconds($this->catalog->cooldownSeconds());
        $profile->save();

        $projection = $this->alphaV1Projector->project(
            $result,
            $definition['catalog'],
            $secretary->name,
            $encounterLabel,
        );
        $projection['summary']['result'] = $resultType;
        $firstChallenge = $trialBattleIndex === 1
            && ! UndergroundBattle::query()
                ->where('underground_profile_id', $profile->id)
                ->where('activity_type', UndergroundBattle::ACTIVITY_TRIAL)
                ->where('activity_key', $trialRun->trial_key)
                ->exists();
        $firstClearStory = $firstClear ? [
            'title' => self::FIRST_CLEAR_STORY_TITLE,
            'body' => self::FIRST_CLEAR_STORY_BODY,
            'system_messages' => [
                "{$secretary->name}は一つ目の封印の地を制覇した。",
                'SPを40入手した。',
            ],
        ] : null;
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => $fingerprint,
            'runtime_identity' => $trial['content_identity'],
            'activity_type' => UndergroundBattle::ACTIVITY_TRIAL,
            'activity_key' => $trialRun->trial_key,
            'encounter_key' => $encounterKey,
            'trial_run_key' => $trialRun->run_key,
            'trial_battle_index' => $trialBattleIndex,
            'result' => $resultType,
            'rounds' => $result->rounds,
            'damage_dealt' => $result->damageDealt,
            'damage_received' => $result->damageReceived,
            'healing_done' => $result->effectiveHealing,
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
                'trial_content_identity' => $trial['content_identity'],
                'combat_rules_identity' => $result->rulesIdentity,
                'player_display_name' => $secretary->name,
                'encounter_display_name' => $encounterLabel,
                'presentation_log_version' => 1,
                'summary' => $projection['summary'],
                'growth_path_key' => $profile->growth_path_key,
                'growth_path_identity' => $profile->growth_path_identity,
                'progression_stats' => $definition['progression_stats'],
                'combat_stats' => $definition['combat_stats'],
                'allocated_stp' => $profile->allocatedStp(),
                'equipment' => $definition['equipment'],
                'skill_tree_identity' => $profile->skill_tree_identity,
                'targeting_contract_identity' => $this->alphaV1Catalog->targetingIdentity(),
                'acquired_skill_nodes' => $definition['acquired_nodes'],
                'equipped_active_skills' => $definition['active_skills'],
                'effective_passive_modifiers' => $definition['passive_modifiers'],
                'encounter' => [
                    'key' => $encounterKey,
                    'xp_reward' => $reward['xp'],
                    'shard_reward' => $reward['shards'],
                ],
                'xp_curve' => $curve,
                'max_rounds' => $maxRounds,
                'battle_start_mp' => AlphaV1CombatRules::MAX_MP,
                'unspent_stp_before' => $unspentStpBefore,
                'unspent_stp_after' => $profile->unspent_stp,
                'stp_awarded' => $stpAwarded,
                'current_hp_before' => $currentHpBefore,
                'max_hp_before' => $maxHpBefore,
                'current_hp_after' => $profile->current_hp,
                'max_hp_after' => $maxHpAfter,
                'banked_shard_balance' => $profile->banked_shard_balance,
                'trial_total_battles' => count($trial['encounters']),
                'trial_status' => $trialRun->status,
                'trial_next_battle_index' => $trialRun->next_battle_index,
                'challenge_intro' => $firstChallenge ? self::FIRST_CHALLENGE_INTRO : null,
                'first_clear_story' => $firstClearStory,
            ],
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $projection['rounds'],
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
    ): bool {
        if ($resultType === UndergroundBattle::RESULT_WITHDRAWAL) {
            $run->status = UndergroundTrialRun::STATUS_WITHDRAWN;
            $run->next_battle_index = 1;
            $run->ended_at = $finishedAt;
            $run->save();

            return false;
        }
        if ($resultType === UndergroundBattle::RESULT_DEFEAT) {
            $run->status = UndergroundTrialRun::STATUS_DEFEATED;
            $run->next_battle_index = 1;
            $run->ended_at = $finishedAt;
            $run->save();

            return false;
        }
        if (! $isTrialBoss) {
            $run->next_battle_index++;
            $run->save();

            return false;
        }

        $progress = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', $run->trial_key)
            ->lockForUpdate()
            ->firstOrFail();
        if ($progress->first_cleared_at === null) {
            $progress->first_cleared_at = $finishedAt;
            $progress->save();
            $reward = $this->catalog->trial($run->trial_key)['first_clear_skill_points'];
            $profile->skill_points_total += $reward;
            $profile->skill_points_unspent += $reward;
        }
        $run->status = UndergroundTrialRun::STATUS_CLEARED;
        $run->next_battle_index = 1;
        $run->ended_at = $finishedAt;
        $run->save();

        return $progress->wasChanged('first_cleared_at');
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
        $profile->setRelation('secretary', $secretary);
        if ($profile->growth_path_key !== null) {
            $this->starterEquipment->reconcile($profile);
        }

        return $profile;
    }

    private function lockedActiveTrialRun(UndergroundProfile $profile): ?UndergroundTrialRun
    {
        $run = UndergroundTrialRun::query()
            ->where('underground_profile_id', $profile->id)
            ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
        if (! $run instanceof UndergroundTrialRun) {
            return null;
        }

        $trial = $this->catalog->trial($run->trial_key);

        return $this->reconcileActiveTrialContent($run, $trial['content_identity']);
    }

    private function reconcileActiveTrialContent(
        UndergroundTrialRun $run,
        string $currentContentIdentity,
    ): UndergroundTrialRun {
        if ($run->trial_content_identity === $currentContentIdentity) {
            return $run;
        }

        $run->trial_content_identity = $currentContentIdentity;
        $run->next_battle_index = 1;
        $run->status = UndergroundTrialRun::STATUS_ACTIVE;
        $run->started_at = Carbon::now();
        $run->ended_at = null;
        $run->save();

        return $run;
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

        return $battle->load([
            'log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
        ]);
    }

    private function assertExplorationUnlocked(UndergroundProfile $profile): void
    {
        $intro = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->lockForUpdate()
            ->first();
        if (! $intro instanceof UndergroundIntroProgress
            || $intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            || $profile->underground_contract_completed_at === null
            || $profile->growth_path_key === null
            || $profile->growth_path_identity !== $this->alphaV1Catalog->growthIdentity()
            || $profile->growth_path_selected_at === null
            || $profile->skill_tree_identity !== $this->alphaV1Catalog->skillTreeIdentity()
            || $profile->skill_points_total < $this->alphaV1Catalog->initialSkillPoints()
            || $profile->skill_points_unspent > $profile->skill_points_total
            || $profile->combat_level < 1) {
            throw new UndergroundRuntimeException(
                'underground_exploration_locked',
                '周囲の探索はまだ解禁されていません。',
            );
        }
        $this->alphaV1Catalog->growthPath($profile->growth_path_key);
    }

    private function assertRequestNotUsedByIntro(UndergroundProfile $profile, string $requestId): void
    {
        if (UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->exists()) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の操作に使用されています。',
            );
        }
    }

    private function settleLevelStp(UndergroundProfile $profile, int $levelBefore): int
    {
        if ($profile->combat_level <= $levelBefore || $profile->growth_path_key === null) {
            return 0;
        }
        $path = $this->alphaV1Catalog->growthPath($profile->growth_path_key);
        $awarded = ($profile->combat_level - $levelBefore) * (int) $path['unspent_stp_per_level'];
        $profile->unspent_stp += $awarded;

        return $awarded;
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

    private function assertExplorationCombatResult(
        BuildCombatResult $result,
        string $enemyKey,
        int $seed,
        int $maxRounds,
        int $maxPlayerHp,
    ): void {
        if ($result->rulesIdentity !== AlphaV1CombatRules::IDENTITY
            || $result->buildKey !== 'secretary_runtime'
            || $result->enemyKey !== $enemyKey
            || $result->seed !== $seed
            || $result->rounds < 1
            || $result->rounds > $maxRounds
            || ($result->winner === 'enemy' && $result->playerRemainingHp !== 0)
            || ($result->winner !== 'enemy'
                && ($result->playerRemainingHp < 1 || $result->playerRemainingHp > $maxPlayerHp))
            || $result->abnormalState !== []) {
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
}
