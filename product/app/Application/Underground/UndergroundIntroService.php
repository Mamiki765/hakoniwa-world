<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatEngine;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use App\Domain\Underground\Intro\UndergroundIntroStage;
use App\Domain\Underground\Progression\UndergroundCombatProgression;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UndergroundIntroService
{
    public function __construct(
        private UndergroundIntroCatalog $catalog,
        private UndergroundRuntimeCatalog $runtimeCatalog,
        private UndergroundCombatEngine $combat,
        private UndergroundBattleLogProjector $battleLogProjector,
        private UndergroundCombatProgression $progression,
    ) {}

    /** @return array<string, mixed> */
    public function state(User $user): array
    {
        $secretary = $this->secretaryForUser($user);
        $profile = UndergroundProfile::query()
            ->where('secretary_id', $secretary->id)
            ->with(['introProgress.tutorialBattle.log', 'introProgress.scriptedLossBattle.log'])
            ->first();

        return $this->projectState($secretary, $profile, $profile?->introProgress);
    }

    /** @return array<string, mixed>|null */
    public function secretarySummary(User $user): ?array
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if (! $secretary instanceof Secretary || $secretary->name === null) {
            return null;
        }
        $profile = UndergroundProfile::query()
            ->where('secretary_id', $secretary->id)
            ->with('introProgress')
            ->first();
        $intro = $profile instanceof UndergroundProfile ? $profile->introProgress : null;

        return [
            'available' => true,
            'stage' => $intro instanceof UndergroundIntroProgress
                ? $intro->stage
                : UndergroundIntroStage::NOT_STARTED,
            'combat_level' => $profile?->combat_level,
            'combat_xp' => $profile?->combat_xp,
            'next_level_xp' => $profile instanceof UndergroundProfile
                ? $this->nextLevelXp($profile)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function enter(User $user, string $requestId): array
    {
        return $this->mutate($user, $requestId, 'entry', [], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ): void {
            $intro->stage = match ($intro->stage) {
                UndergroundIntroStage::NOT_STARTED => UndergroundIntroStage::INITIAL_DESCENT,
                UndergroundIntroStage::RETURNED_AFTER_TUTORIAL => UndergroundIntroStage::SHOPKEEPER_ENCOUNTER,
                default => throw new UndergroundRuntimeException(
                    'underground_intro_stage_conflict',
                    '現在の進行状態では地下への入口を使用できません。',
                ),
            };
            $intro->save();
        });
    }

    /** @return array<string, mixed> */
    public function advance(User $user, string $requestId, string $action): array
    {
        $transitions = [
            'initial_story_complete' => [UndergroundIntroStage::INITIAL_DESCENT, UndergroundIntroStage::TUTORIAL_READY],
            'escape_complete' => [UndergroundIntroStage::ESCAPE_PENDING, UndergroundIntroStage::RETURNED_AFTER_TUTORIAL],
            'shopkeeper_encounter_complete' => [
                UndergroundIntroStage::SHOPKEEPER_ENCOUNTER,
                UndergroundIntroStage::SHOPKEEPER_NAMING,
            ],
            'special_loss_aftermath_complete' => [
                UndergroundIntroStage::SPECIAL_LOSS_COMPLETE,
                UndergroundIntroStage::SHOP_EXPLANATION,
            ],
            'shop_explanation_complete' => [
                UndergroundIntroStage::SHOP_EXPLANATION,
                UndergroundIntroStage::UNDERGROUND_OPEN,
            ],
        ];
        $transition = $transitions[$action] ?? null;
        if (! is_array($transition)) {
            throw new UndergroundRuntimeException('underground_intro_transition_invalid', '進行操作を確認してください。');
        }

        return $this->mutate($user, $requestId, 'advance', ['action' => $action], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($transition): void {
            if ($intro->stage !== $transition[0]) {
                throw new UndergroundRuntimeException(
                    'underground_intro_stage_conflict',
                    '現在の進行状態ではこの操作を行えません。',
                );
            }
            $intro->stage = $transition[1];
            $intro->save();
        });
    }

    /** @return array<string, mixed> */
    public function tutorial(User $user, string $requestId): array
    {
        return $this->mutate($user, $requestId, 'tutorial', [], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($requestId): UndergroundBattle {
            if ($intro->stage !== UndergroundIntroStage::TUTORIAL_READY || $intro->tutorial_battle_id !== null) {
                throw new UndergroundRuntimeException(
                    'underground_tutorial_already_settled',
                    'Tutorial戦闘はすでに完了しているか、まだ開始できません。',
                );
            }
            if ($profile->combat_level !== 1 || $profile->combat_xp !== 0) {
                throw new UndergroundRuntimeException(
                    'underground_tutorial_profile_invalid',
                    'Tutorial開始前の戦闘進捗を確認できません。',
                );
            }

            $battle = $this->settleStoryBattle($profile, $requestId, 'tutorial');
            $intro->tutorial_battle_id = $battle->id;
            $intro->stage = UndergroundIntroStage::ESCAPE_PENDING;
            $intro->save();

            return $battle;
        });
    }

    /** @return array<string, mixed> */
    public function nameShopkeeper(User $user, string $requestId, string $submittedName): array
    {
        $name = $this->catalog->normalizeShopkeeperName($submittedName);

        return $this->mutate($user, $requestId, 'shopkeeper_name', ['name' => $name], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($name): void {
            if ($intro->stage !== UndergroundIntroStage::SHOPKEEPER_NAMING
                || $intro->shopkeeper_name !== null
                || $intro->special_loss_required !== null) {
                throw new UndergroundRuntimeException(
                    'underground_shopkeeper_already_named',
                    'ショップ店員の名前はすでに決定されています。',
                );
            }
            $special = $this->catalog->isSpecialName($name);
            $intro->shopkeeper_name = $name;
            $intro->special_loss_required = $special;
            $intro->stage = $special
                ? UndergroundIntroStage::SPECIAL_LOSS_PENDING
                : UndergroundIntroStage::SHOP_EXPLANATION;
            $intro->save();
        });
    }

    /** @return array<string, mixed> */
    public function scriptedLoss(User $user, string $requestId): array
    {
        return $this->mutate($user, $requestId, 'scripted_loss', [], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($requestId): UndergroundBattle {
            if ($intro->stage !== UndergroundIntroStage::SPECIAL_LOSS_PENDING
                || $intro->special_loss_required !== true
                || $intro->scripted_loss_battle_id !== null) {
                throw new UndergroundRuntimeException(
                    'underground_scripted_loss_unavailable',
                    'このstory戦闘は開始できません。',
                );
            }

            $battle = $this->settleStoryBattle($profile, $requestId, 'scripted_loss');
            $intro->scripted_loss_battle_id = $battle->id;
            $intro->stage = UndergroundIntroStage::SPECIAL_LOSS_COMPLETE;
            $intro->save();

            return $battle;
        });
    }

    /** @return array<string, mixed> */
    public function main(User $user): array
    {
        $state = $this->state($user);
        if (($state['stage'] ?? null) !== UndergroundIntroStage::UNDERGROUND_OPEN) {
            throw new UndergroundRuntimeException(
                'underground_main_locked',
                '地下メイン画面はまだ解禁されていません。',
            );
        }

        return $state;
    }

    /** @return list<array<string, mixed>> */
    public function battles(User $user): array
    {
        $secretary = $this->secretaryForUser($user);
        $profile = UndergroundProfile::query()->where('secretary_id', $secretary->id)->first();
        if (! $profile instanceof UndergroundProfile) {
            return [];
        }

        return UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->whereIn('activity_type', [UndergroundBattle::ACTIVITY_TUTORIAL, UndergroundBattle::ACTIVITY_STORY])
            ->with('log')
            ->orderByDesc('finished_at')
            ->get()
            ->map(fn (UndergroundBattle $battle): array => $this->projectBattle($battle, false))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function battle(User $user, string $requestId): array
    {
        if (! Str::isUuid($requestId)) {
            throw new UndergroundRuntimeException('underground_request_id_invalid', 'request IDを確認してください。');
        }
        $secretary = $this->secretaryForUser($user);
        $profile = UndergroundProfile::query()->where('secretary_id', $secretary->id)->first();
        $battle = $profile instanceof UndergroundProfile
            ? UndergroundBattle::query()
                ->where('underground_profile_id', $profile->id)
                ->where('request_id', $requestId)
                ->whereIn('activity_type', [UndergroundBattle::ACTIVITY_TUTORIAL, UndergroundBattle::ACTIVITY_STORY])
                ->with('log')
                ->first()
            : null;
        if (! $battle instanceof UndergroundBattle) {
            throw new UndergroundRuntimeException('underground_battle_not_found', '戦闘履歴が見つかりません。');
        }

        return $this->projectBattle($battle, true);
    }

    /**
     * @param  array<string, scalar|null>  $payload
     * @param  callable(Secretary, UndergroundProfile, UndergroundIntroProgress):(UndergroundBattle|void)  $operation
     * @return array<string, mixed>
     */
    private function mutate(
        User $user,
        string $requestId,
        string $operationName,
        array $payload,
        callable $operation,
    ): array {
        if (! Str::isUuid($requestId)) {
            throw new UndergroundRuntimeException('underground_request_id_invalid', 'request IDを確認してください。');
        }
        $fingerprint = $this->fingerprint($operationName, $payload);

        return DB::transaction(function () use (
            $user,
            $requestId,
            $operationName,
            $fingerprint,
            $operation,
        ): array {
            [$secretary, $profile, $intro] = $this->lockedState($user);
            $previous = UndergroundIntroRequest::query()
                ->where('underground_profile_id', $profile->id)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($previous instanceof UndergroundIntroRequest) {
                if (! hash_equals($previous->request_fingerprint, $fingerprint)) {
                    throw new UndergroundRuntimeException(
                        'underground_request_conflict',
                        '同じrequest IDが別の操作に使用されています。',
                    );
                }

                return $this->projectState($secretary, $profile->refresh(), $intro->refresh());
            }
            $existingBattle = UndergroundBattle::query()
                ->where('underground_profile_id', $profile->id)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($existingBattle instanceof UndergroundBattle) {
                throw new UndergroundRuntimeException(
                    'underground_request_conflict',
                    '同じrequest IDが別の戦闘に使用されています。',
                );
            }

            $battle = $operation($secretary, $profile, $intro);
            UndergroundIntroRequest::query()->create([
                'underground_profile_id' => $profile->id,
                'request_id' => $requestId,
                'request_fingerprint' => $fingerprint,
                'operation' => $operationName,
                'resulting_stage' => $intro->stage,
                'underground_battle_id' => $battle instanceof UndergroundBattle ? $battle->id : null,
            ]);

            return $this->projectState(
                $secretary,
                $profile->refresh(),
                $intro->refresh()->load(['tutorialBattle.log', 'scriptedLossBattle.log']),
            );
        }, 3);
    }

    /** @return array{Secretary, UndergroundProfile, UndergroundIntroProgress} */
    private function lockedState(User $user): array
    {
        $secretary = Secretary::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if (! $secretary instanceof Secretary || $secretary->name === null) {
            throw new UndergroundRuntimeException('underground_secretary_missing', '名前のある秘書が必要です。');
        }
        UndergroundProfile::query()->firstOrCreate(['secretary_id' => $secretary->id]);
        $profile = UndergroundProfile::query()
            ->where('secretary_id', $secretary->id)
            ->lockForUpdate()
            ->firstOrFail();
        UndergroundIntroProgress::query()->firstOrCreate(['underground_profile_id' => $profile->id]);
        $intro = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$secretary, $profile, $intro];
    }

    private function secretaryForUser(User $user): Secretary
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if (! $secretary instanceof Secretary || $secretary->name === null) {
            throw new UndergroundRuntimeException('underground_secretary_missing', '名前のある秘書が必要です。');
        }

        return $secretary;
    }

    private function settleStoryBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $battleKey,
    ): UndergroundBattle {
        $definition = $this->catalog->battle($battleKey);
        $before = [
            'level' => $profile->combat_level,
            'xp' => $profile->combat_xp,
            'shards' => $profile->shard_balance,
            'next_battle_at' => $profile->next_battle_at?->toAtomString(),
        ];
        $startedAt = Carbon::now();
        $result = $this->combat->fightSnapshots(
            $definition['actor'],
            $definition['loadout'],
            $definition['enemy'],
            UndergroundCombatRules::AI_PRESET,
            $definition['seed'],
            $definition['max_rounds'],
        );
        $this->assertStoryResult($result, $definition);
        $finishedAt = Carbon::now();
        if ($battleKey === 'tutorial') {
            $profile->combat_xp += $definition['xp_reward'];
            $curve = $this->runtimeCatalog->xpCurve();
            $profile->combat_level = $this->progression->levelAfterXp(
                $profile->combat_level,
                $profile->combat_xp,
                $curve['first_level_cost'],
                $curve['cost_increment_per_level'],
            );
            if ($profile->combat_level !== 1 || $profile->combat_xp !== 5) {
                throw new UndergroundRuntimeException(
                    'underground_tutorial_reward_invalid',
                    'Tutorial報酬を検証できなかったためsettlementを取り消しました。',
                );
            }
            $profile->save();
        }
        $after = [
            'level' => $profile->combat_level,
            'xp' => $profile->combat_xp,
            'shards' => $profile->shard_balance,
            'next_battle_at' => $profile->next_battle_at?->toAtomString(),
        ];
        if ($battleKey === 'scripted_loss' && $before !== $after) {
            throw new UndergroundRuntimeException(
                'underground_scripted_loss_penalty_invalid',
                'story戦闘が進捗へ影響したためsettlementを取り消しました。',
            );
        }
        $resultType = $result->winner === 'player'
            ? UndergroundBattle::RESULT_VICTORY
            : UndergroundBattle::RESULT_DEFEAT;
        $fingerprint = $this->fingerprint($battleKey === 'tutorial' ? 'tutorial' : 'scripted_loss', []);
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => $fingerprint,
            'runtime_identity' => $this->catalog->identity(),
            'activity_type' => $definition['activity_type'],
            'activity_key' => $definition['activity_key'],
            'encounter_key' => $definition['encounter_key'],
            'trial_run_key' => null,
            'trial_battle_index' => null,
            'result' => $resultType,
            'rounds' => $result->rounds,
            'damage_dealt' => $result->damageDealt,
            'damage_received' => $result->damageReceived,
            'healing_done' => $result->healingDone,
            'xp_awarded' => $battleKey === 'tutorial' ? $definition['xp_reward'] : 0,
            'shard_delta' => 0,
            'combat_level_before' => $before['level'],
            'combat_level_after' => $after['level'],
            'combat_xp_before' => $before['xp'],
            'combat_xp_after' => $after['xp'],
            'shard_balance_before' => $before['shards'],
            'shard_balance_after' => $after['shards'],
            'private_seed' => $definition['seed'],
            'snapshot' => [
                'story_identity' => $this->catalog->identity(),
                'combat_rules_identity' => $result->rulesIdentity,
                'actor' => $definition['actor'],
                'loadout' => $definition['loadout'],
                'enemy' => $definition['enemy'],
                'encounter_display_name' => $definition['display_name'],
                'max_rounds' => $definition['max_rounds'],
                'penalty_policy' => 'none',
            ],
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $this->battleLogProjector->project($result),
            'expires_at' => $finishedAt->copy()->addHours($this->runtimeCatalog->battleLogRetentionHours()),
        ]);

        return $battle->load('log');
    }

    /** @param array<string, mixed> $definition */
    private function assertStoryResult(CombatResult $result, array $definition): void
    {
        if ($result->rulesIdentity !== UndergroundCombatRules::IDENTITY
            || $result->actorKey !== ($definition['actor']['key'] ?? null)
            || $result->enemyKey !== ($definition['enemy']['key'] ?? null)
            || $result->seed !== $definition['seed']
            || $result->winner !== $definition['expected_winner']
            || $result->rounds < 1
            || $result->rounds > $definition['max_rounds']
            || $result->rounds >= 100
            || $result->abnormalState !== []) {
            throw new UndergroundRuntimeException(
                'underground_story_combat_contract_failed',
                'story戦闘がcontract外の結果になったためsettlementを取り消しました。',
            );
        }
    }

    /** @return array<string, mixed> */
    private function projectState(
        Secretary $secretary,
        ?UndergroundProfile $profile,
        ?UndergroundIntroProgress $intro,
    ): array {
        $stage = $intro instanceof UndergroundIntroProgress
            ? $intro->stage
            : UndergroundIntroStage::NOT_STARTED;
        $battle = match ($stage) {
            UndergroundIntroStage::ESCAPE_PENDING => $intro?->tutorialBattle,
            UndergroundIntroStage::SPECIAL_LOSS_COMPLETE => $intro?->scriptedLossBattle,
            default => null,
        };

        return [
            'stage' => $stage,
            'secretary_name' => $secretary->name,
            'combat_level' => $profile instanceof UndergroundProfile ? $profile->combat_level : 1,
            'combat_xp' => $profile instanceof UndergroundProfile ? $profile->combat_xp : 0,
            'next_level_xp' => $profile instanceof UndergroundProfile ? $this->nextLevelXp($profile) : 100,
            'shard_balance' => $profile instanceof UndergroundProfile ? $profile->shard_balance : 0,
            'shopkeeper_name' => $intro?->shopkeeper_name,
            'battle' => $battle instanceof UndergroundBattle ? $this->projectBattle($battle, true) : null,
        ];
    }

    private function nextLevelXp(UndergroundProfile $profile): int
    {
        $curve = $this->runtimeCatalog->xpCurve();

        return $this->progression->totalXpRequiredForLevel(
            $profile->combat_level + 1,
            $curve['first_level_cost'],
            $curve['cost_increment_per_level'],
        );
    }

    /** @return array<string, mixed> */
    private function projectBattle(UndergroundBattle $battle, bool $withActions): array
    {
        $snapshot = $battle->snapshot;
        $displayName = $snapshot['encounter_display_name'] ?? null;

        return [
            'id' => $battle->request_id,
            'context' => $battle->activity_type === UndergroundBattle::ACTIVITY_TUTORIAL
                ? 'tutorial'
                : 'scripted_loss',
            'encounter_name' => is_string($displayName) ? $displayName : '（ダミー）',
            'result' => $battle->result,
            'rounds' => $battle->rounds,
            'xp_awarded' => $battle->xp_awarded,
            'shard_delta' => $battle->shard_delta,
            'finished_at' => $battle->finished_at->toAtomString(),
            'detail_available' => $battle->log instanceof UndergroundBattleLog,
            'actions' => $withActions && $battle->log instanceof UndergroundBattleLog
                ? $battle->log->actions
                : null,
        ];
    }

    /** @param array<string, scalar|null> $payload */
    private function fingerprint(string $operation, array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode([
            'story_identity' => $this->catalog->identity(),
            'operation' => $operation,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
