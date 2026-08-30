<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Intro\UndergroundIntroStage;
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

final readonly class UndergroundPlaytestService
{
    public function __construct(
        private UndergroundAlphaV1PlayerCatalog $catalog,
        private AlphaV1CombatModel $combat,
        private UndergroundAlphaV1BattleProjector $projector,
        private UndergroundBattleSeed $battleSeed,
        private UndergroundRuntimeCatalog $runtimeCatalog,
    ) {}

    /** @return array<string, mixed> */
    public function options(User $user): array
    {
        $this->assertAvailable();
        [$profile, $intro] = $this->stateForUser($user);
        $this->assertUnlocked($profile, $intro);

        return $this->catalog->playtestOptions($profile->growth_path_key);
    }

    /** @return array<string, mixed> */
    public function fight(User $user, string $requestId, string $buildKey, string $enemyKey): array
    {
        $this->assertAvailable();
        if (! Str::isUuid($requestId)) {
            throw new UndergroundRuntimeException('underground_request_id_invalid', 'request IDを確認してください。');
        }
        $definition = $this->catalog->playtestDefinition($buildKey, $enemyKey);
        $fingerprint = hash('sha256', json_encode([
            'identity' => $definition['identity'],
            'operation' => 'playtest',
            'build_key' => $buildKey,
            'enemy_key' => $enemyKey,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use (
            $user,
            $requestId,
            $buildKey,
            $enemyKey,
            $definition,
            $fingerprint,
        ): array {
            $secretary = Secretary::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $secretary instanceof Secretary || $secretary->name === null) {
                throw new UndergroundRuntimeException('underground_secretary_missing', '名前のある秘書が必要です。');
            }
            $profile = UndergroundProfile::query()
                ->where('secretary_id', $secretary->id)
                ->lockForUpdate()
                ->first();
            $intro = $profile instanceof UndergroundProfile
                ? UndergroundIntroProgress::query()
                    ->where('underground_profile_id', $profile->id)
                    ->lockForUpdate()
                    ->first()
                : null;
            if (! $profile instanceof UndergroundProfile || ! $intro instanceof UndergroundIntroProgress) {
                throw new UndergroundRuntimeException('underground_playtest_locked', '力試しはまだ解禁されていません。');
            }
            $this->assertUnlocked($profile, $intro);

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
            $existing = UndergroundBattle::query()
                ->where('underground_profile_id', $profile->id)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->with(['log' => fn ($query) => $query->where('expires_at', '>', Carbon::now())])
                ->first();
            if ($existing instanceof UndergroundBattle) {
                if ($existing->activity_type !== UndergroundBattle::ACTIVITY_PLAYTEST
                    || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new UndergroundRuntimeException(
                        'underground_request_conflict',
                        '同じrequest IDが別の戦闘に使用されています。',
                    );
                }

                return $this->projectBattle($existing);
            }

            $before = $this->progressSnapshot($profile);
            $seed = $this->battleSeed->forRequest($profile->id, $requestId, $definition['identity']);
            $startedAt = Carbon::now();
            $result = $this->combat->fight(
                $definition['catalog'],
                $buildKey,
                $enemyKey,
                $definition['tier_key'],
                $seed,
                $definition['max_rounds'],
            );
            $this->assertResult($result, $buildKey, $enemyKey, $definition['max_rounds']);
            $profile->refresh();
            if ($before !== $this->progressSnapshot($profile)) {
                throw new UndergroundRuntimeException(
                    'underground_playtest_reward_contract_failed',
                    '力試しが進捗へ影響したためsettlementを取り消しました。',
                );
            }
            $finishedAt = Carbon::now();
            $projection = $this->projector->project(
                $result,
                $definition['catalog'],
                $secretary->name,
                $definition['enemy_label'],
            );
            $battle = UndergroundBattle::query()->create([
                'underground_profile_id' => $profile->id,
                'request_id' => $requestId,
                'request_fingerprint' => $fingerprint,
                'runtime_identity' => $result->rulesIdentity,
                'activity_type' => UndergroundBattle::ACTIVITY_PLAYTEST,
                'activity_key' => $definition['identity'],
                'encounter_key' => $enemyKey,
                'trial_run_key' => null,
                'trial_battle_index' => null,
                'result' => match ($result->winner) {
                    'player' => UndergroundBattle::RESULT_VICTORY,
                    'enemy' => UndergroundBattle::RESULT_DEFEAT,
                    default => UndergroundBattle::RESULT_WITHDRAWAL,
                },
                'rounds' => $result->rounds,
                'damage_dealt' => $result->damageDealt,
                'damage_received' => $result->damageReceived,
                'healing_done' => $result->effectiveHealing,
                'xp_awarded' => 0,
                'shard_delta' => 0,
                'combat_level_before' => $profile->combat_level,
                'combat_level_after' => $profile->combat_level,
                'combat_xp_before' => $profile->combat_xp,
                'combat_xp_after' => $profile->combat_xp,
                'shard_balance_before' => $profile->shard_balance,
                'shard_balance_after' => $profile->shard_balance,
                'private_seed' => $seed,
                'snapshot' => [
                    'playtest_identity' => $definition['identity'],
                    'build_key' => $buildKey,
                    'enemy_key' => $enemyKey,
                    'player_display_name' => $secretary->name,
                    'build_display_name' => $definition['build_label'],
                    'encounter_display_name' => $definition['enemy_label'],
                    'presentation_log_version' => 1,
                    'summary' => $projection['summary'],
                    'reward_policy' => 'none',
                    'penalty_policy' => 'none',
                ],
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);
            UndergroundBattleLog::query()->create([
                'underground_battle_id' => $battle->id,
                'actions' => $projection['rounds'],
                'expires_at' => $finishedAt->copy()->addHours($this->runtimeCatalog->battleLogRetentionHours()),
            ]);

            return $this->projectBattle($battle->load('log'));
        }, 3);
    }

    private function assertAvailable(): void
    {
        if (config('app.env') === 'production') {
            throw new UndergroundRuntimeException(
                'underground_playtest_locked',
                '力試しはまだ解禁されていません。',
            );
        }
    }

    /** @return array<string, mixed> */
    public function projectBattle(UndergroundBattle $battle, bool $withRounds = true): array
    {
        $snapshot = $battle->snapshot;
        $buildKey = is_string($snapshot['build_key'] ?? null) ? $snapshot['build_key'] : '';
        $enemyKey = is_string($snapshot['enemy_key'] ?? null) ? $snapshot['enemy_key'] : '';
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $log = $this->loadedLog($battle);
        $hasPresentationLog = ($snapshot['presentation_log_version'] ?? null) === 1
            && $log instanceof UndergroundBattleLog;
        $rounds = $withRounds && $hasPresentationLog ? $log->actions : null;
        $buildName = is_string($snapshot['build_display_name'] ?? null)
            ? $snapshot['build_display_name']
            : '力試しビルド';
        $encounterName = is_string($snapshot['encounter_display_name'] ?? null)
            ? $snapshot['encounter_display_name']
            : '力試しの対戦相手';
        $playerDisplayName = is_string($snapshot['player_display_name'] ?? null)
            ? $snapshot['player_display_name']
            : '秘書';

        return [
            'id' => $battle->request_id,
            'context' => 'playtest',
            'player_display_name' => $playerDisplayName,
            'build_key' => $buildKey,
            'enemy_key' => $enemyKey,
            'build_name' => $buildName,
            'encounter_name' => $encounterName,
            'result' => in_array($summary['result'] ?? null, ['victory', 'defeat', 'stalemate'], true)
                ? $summary['result']
                : $battle->result,
            'rounds_count' => $battle->rounds,
            'xp_awarded' => 0,
            'shard_delta' => 0,
            'summary' => $summary,
            'rounds' => $rounds,
            'detail_available' => $withRounds
                ? $hasPresentationLog
                : (bool) ($battle->getAttribute('active_log_exists') ?? false),
            'detail_message' => $withRounds && ! $hasPresentationLog
                ? '詳細ログは保存期間を過ぎました。'
                : null,
            'finished_at' => $battle->finished_at->toAtomString(),
            'rewards' => ['xp' => 0, 'shards' => 0, 'g' => 0, 'drops' => []],
        ];
    }

    private function loadedLog(UndergroundBattle $battle): ?UndergroundBattleLog
    {
        if (! $battle->relationLoaded('log')) {
            return null;
        }
        $log = $battle->getRelation('log');

        return $log instanceof UndergroundBattleLog ? $log : null;
    }

    /** @return array{UndergroundProfile, UndergroundIntroProgress} */
    private function stateForUser(User $user): array
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if (! $secretary instanceof Secretary || $secretary->name === null) {
            throw new UndergroundRuntimeException('underground_secretary_missing', '名前のある秘書が必要です。');
        }
        $profile = UndergroundProfile::query()->where('secretary_id', $secretary->id)->first();
        $intro = $profile instanceof UndergroundProfile ? $profile->introProgress()->first() : null;
        if (! $profile instanceof UndergroundProfile || ! $intro instanceof UndergroundIntroProgress) {
            throw new UndergroundRuntimeException('underground_playtest_locked', '力試しはまだ解禁されていません。');
        }

        return [$profile, $intro];
    }

    private function assertUnlocked(UndergroundProfile $profile, UndergroundIntroProgress $intro): void
    {
        if ($intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            || $profile->underground_contract_completed_at === null
            || $profile->growth_path_key === null
            || $profile->growth_path_identity !== $this->catalog->growthIdentity()
            || $profile->growth_path_selected_at === null) {
            throw new UndergroundRuntimeException('underground_playtest_locked', '力試しはまだ解禁されていません。');
        }
        $this->catalog->growthPath($profile->growth_path_key);
    }

    /** @return array<string, int|string|null> */
    private function progressSnapshot(UndergroundProfile $profile): array
    {
        return [
            'combat_level' => $profile->combat_level,
            'combat_xp' => $profile->combat_xp,
            'shard_balance' => $profile->shard_balance,
            'next_battle_at' => $profile->next_battle_at?->toAtomString(),
            'growth_path_key' => $profile->growth_path_key,
            'growth_path_identity' => $profile->growth_path_identity,
            'growth_path_selected_at' => $profile->growth_path_selected_at?->toAtomString(),
        ];
    }

    private function assertResult(BuildCombatResult $result, string $buildKey, string $enemyKey, int $maxRounds): void
    {
        if ($result->buildKey !== $buildKey || $result->enemyKey !== $enemyKey
            || $result->rounds < 1 || $result->rounds > $maxRounds
            || $result->abnormalState !== []) {
            throw new UndergroundRuntimeException(
                'underground_playtest_combat_contract_failed',
                '力試しの戦闘結果を検証できませんでした。',
            );
        }
    }
}
