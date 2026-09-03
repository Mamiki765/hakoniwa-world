<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\PriorityCombatAiConfiguration;
use App\Domain\Underground\Combat\UndergroundAwakening;
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
use App\Models\UndergroundSkillAllocation;
use App\Models\UndergroundTrialProgress;
use App\Models\UndergroundTrialRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class UndergroundIntroService
{
    private const RESPEC_COST_PER_LEVEL = 10;

    private const RESPEC_COOLDOWN_HOURS = 24;

    public function __construct(
        private UndergroundIntroCatalog $catalog,
        private UndergroundRuntimeCatalog $runtimeCatalog,
        private UndergroundCombatEngine $combat,
        private UndergroundBattleLogProjector $battleLogProjector,
        private UndergroundCombatProgression $progression,
        private UndergroundAlphaV1PlayerCatalog $alphaV1Catalog,
        private AlphaV1CombatModel $alphaV1Combat,
        private UndergroundAlphaV1BattleProjector $alphaV1Projector,
        private UndergroundPlaytestService $playtest,
        private UndergroundRuntimeService $runtime,
        private UndergroundStarterEquipmentService $starterEquipment,
        private UndergroundEquipmentLoadoutResolver $equipmentLoadout,
        private UndergroundAwakening $awakening,
        private PriorityCombatAiConfiguration $aiConfiguration,
    ) {}

    /** @return array<string, mixed> */
    public function state(User $user): array
    {
        $secretary = $this->secretaryForUser($user);
        $profile = UndergroundProfile::query()
            ->where('secretary_id', $secretary->id)
            ->with([
                'introProgress.tutorialBattle.log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
                'introProgress.scriptedLossBattle.log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
            ])
            ->first();

        if ($profile instanceof UndergroundProfile && $profile->growth_path_key !== null) {
            DB::transaction(function () use ($secretary, $profile): void {
                Secretary::query()->whereKey($secretary->id)->lockForUpdate()->firstOrFail();
                $locked = UndergroundProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                $this->starterEquipment->reconcile($locked);
            }, 3);
            $profile = UndergroundProfile::query()
                ->whereKey($profile->id)
                ->with([
                    'introProgress.tutorialBattle.log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
                    'introProgress.scriptedLossBattle.log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
                ])
                ->first();
        }

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
                UndergroundIntroStage::CONTRACT_READY,
            ],
            'growth_path_story_complete' => [
                UndergroundIntroStage::GROWTH_PATH_SELECTED,
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

            $battle = $this->settleStoryBattle($profile, $requestId, 'tutorial', $secretary->name);
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
            $branchIdentity = $this->catalog->branchIdentity($name);
            $special = $branchIdentity === 'true_name';
            $intro->shopkeeper_name = $name;
            $intro->special_loss_required = $special;
            $intro->branch_identity = $branchIdentity;
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

            $battle = $intro->branch_identity === 'true_name'
                ? $this->settleTrueNameStoryBattle($profile, $requestId, $secretary->name)
                : $this->settleStoryBattle($profile, $requestId, 'scripted_loss', $secretary->name);
            $intro->scripted_loss_battle_id = $battle->id;
            $intro->stage = UndergroundIntroStage::SPECIAL_LOSS_COMPLETE;
            $intro->save();

            return $battle;
        });
    }

    /** @return array<string, mixed> */
    public function contract(User $user, string $requestId): array
    {
        return $this->mutate($user, $requestId, 'contract', [], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ): void {
            if ($intro->stage !== UndergroundIntroStage::CONTRACT_READY
                || $profile->underground_contract_completed_at !== null) {
                throw new UndergroundRuntimeException(
                    'underground_contract_already_completed',
                    '契約はすでに完了しているか、まだ締結できません。',
                );
            }
            $profile->underground_contract_completed_at = Carbon::now();
            $profile->save();
            $intro->stage = UndergroundIntroStage::CRYSTAL_SELECTION;
            $intro->save();
        });
    }

    /** @return array<string, mixed> */
    public function selectGrowthPath(User $user, string $requestId, string $growthPathKey): array
    {
        $path = $this->alphaV1Catalog->growthPath($growthPathKey);

        return $this->mutate($user, $requestId, 'growth_path', ['growth_path_key' => $growthPathKey], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($growthPathKey, $path): void {
            if ($intro->stage !== UndergroundIntroStage::CRYSTAL_SELECTION
                || $profile->underground_contract_completed_at === null
                || $profile->growth_path_key !== null
                || $profile->growth_path_identity !== null
                || $profile->growth_path_selected_at !== null) {
                throw new UndergroundRuntimeException(
                    'underground_growth_path_already_selected',
                    '輝石はすでに選択されているか、まだ選択できません。',
                );
            }
            $selectedAt = Carbon::now();
            $profile->growth_path_key = $growthPathKey;
            $profile->growth_path_identity = $path['identity'];
            $profile->growth_path_selected_at = $selectedAt;
            $profile->unspent_stp = $this->alphaV1Catalog->stpEntitlement(
                $growthPathKey,
                $profile->combat_level,
            );
            $profile->current_hp = $this->alphaV1Catalog->currentMaxHp(
                $growthPathKey,
                $profile->combat_level,
                $profile->allocatedStp(),
            );
            $profile->skill_points_total = $this->alphaV1Catalog->initialSkillPoints();
            $profile->skill_points_unspent = $this->alphaV1Catalog->initialSkillPoints();
            $profile->skill_tree_identity = $this->alphaV1Catalog->skillTreeIdentity();
            $profile->save();
            $this->starterEquipment->reconcile($profile);
            $intro->stage = UndergroundIntroStage::GROWTH_PATH_SELECTED;
            $intro->save();
        });
    }

    /** @return array<string, mixed> */
    public function respec(User $user, string $requestId, string $growthPathKey): array
    {
        $path = $this->alphaV1Catalog->growthPath($growthPathKey);

        return $this->mutate($user, $requestId, 'respec', ['growth_path_key' => $growthPathKey], function (
            Secretary $_secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($growthPathKey, $path): void {
            $this->assertSkillTreeUnlocked($profile, $intro);
            $activeTrial = UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            if ($activeTrial instanceof UndergroundTrialRun) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '封印の地から帰還してから再振りしてください。',
                );
            }

            $now = Carbon::now();
            $nextAvailableAt = $profile->last_respec_at?->addHours(self::RESPEC_COOLDOWN_HOURS);
            if ($nextAvailableAt !== null && $now->isBefore($nextAvailableAt)) {
                throw new UndergroundRuntimeException(
                    'underground_respec_cooldown',
                    '再振りは24時間に1回だけ行えます。',
                );
            }

            $cost = $this->respecCost($profile);
            if ($profile->shard_balance < $cost) {
                throw new UndergroundRuntimeException(
                    'underground_respec_insufficient_carried_shards',
                    '手持ちの輝石のかけらが不足しています。',
                );
            }

            $equipment = $this->equipmentLoadout->combatLoadout($profile);
            $currentHp = $profile->current_hp ?? $this->alphaV1Catalog->currentMaxHp(
                (string) $profile->growth_path_key,
                $profile->combat_level,
                $profile->allocatedStp(),
                $equipment,
            );

            $profile->shard_balance -= $cost;
            $profile->growth_path_key = $growthPathKey;
            $profile->growth_path_identity = $path['identity'];
            $profile->growth_path_selected_at = $now;
            $profile->unspent_stp = $this->alphaV1Catalog->stpEntitlement(
                $growthPathKey,
                $profile->combat_level,
            );
            foreach (AlphaV1CombatRules::STATS as $stat) {
                $profile->{'allocated_'.$stat.'_stp'} = 0;
            }
            $profile->skill_points_unspent = $profile->skill_points_total;
            $profile->last_respec_at = $now;
            $newMaxHp = $this->alphaV1Catalog->currentMaxHp(
                $growthPathKey,
                $profile->combat_level,
                $profile->allocatedStp(),
                $equipment,
            );
            $profile->current_hp = min($currentHp, $newMaxHp);
            $profile->save();

            UndergroundSkillAllocation::query()
                ->where('underground_profile_id', $profile->id)
                ->delete();
        });
    }

    /** @return array<string, mixed> */
    public function restAtInn(User $user, string $requestId): array
    {
        return $this->mutate($user, $requestId, 'inn_rest', [], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ): void {
            $this->assertShopUnlocked($profile, $intro);
            if (UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
                ->lockForUpdate()
                ->exists()) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '封印の地へ挑戦中は宿で休めません。帰還後に利用してください。',
                );
            }
            $cost = $this->alphaV1Catalog->innCost();
            if ($profile->shard_balance < $cost) {
                throw new UndergroundRuntimeException(
                    'underground_inn_insufficient_carried_shards',
                    '宿代に必要な手持ちの輝石の欠片がありません。',
                );
            }

            $profile->shard_balance -= $cost;
            $profile->current_hp = $this->alphaV1Catalog->currentMaxHp(
                (string) $profile->growth_path_key,
                $profile->combat_level,
                $profile->allocatedStp(),
                $this->equipmentLoadout->combatLoadout($profile),
            );
            $profile->save();
        });
    }

    /** @return array<string, mixed> */
    public function bankTransfer(
        User $user,
        string $requestId,
        string $action,
        ?int $requestedAmount,
    ): array {
        if (! in_array($action, ['deposit', 'withdraw', 'deposit_all', 'withdraw_all'], true)) {
            throw new UndergroundRuntimeException('underground_bank_action_invalid', '銀行操作を確認してください。');
        }
        $isAll = str_ends_with($action, '_all');
        if ($isAll && $requestedAmount !== null) {
            throw new UndergroundRuntimeException('underground_bank_amount_invalid', '全額操作に金額は指定できません。');
        }
        if (! $isAll && ($requestedAmount === null
            || $requestedAmount <= 0
            || $requestedAmount % $this->alphaV1Catalog->bankTransferUnit() !== 0)) {
            throw new UndergroundRuntimeException(
                'underground_bank_amount_invalid',
                '通常の預け入れ・引き出しは1000G単位で指定してください。',
            );
        }

        return $this->mutate(
            $user,
            $requestId,
            'bank_transfer',
            ['action' => $action, 'amount' => $requestedAmount],
            function (
                Secretary $secretary,
                UndergroundProfile $profile,
                UndergroundIntroProgress $intro,
            ) use ($action, $requestedAmount): void {
                $this->assertShopUnlocked($profile, $intro);
                $deposit = str_starts_with($action, 'deposit');
                $amount = match ($action) {
                    'deposit_all' => $profile->shard_balance,
                    'withdraw_all' => $profile->banked_shard_balance,
                    default => $requestedAmount,
                };
                if (! is_int($amount) || $amount < 0) {
                    throw new UndergroundRuntimeException('underground_bank_amount_invalid', '銀行操作額を確認してください。');
                }

                $source = $deposit ? $profile->shard_balance : $profile->banked_shard_balance;
                $target = $deposit ? $profile->banked_shard_balance : $profile->shard_balance;
                if ($source < $amount) {
                    throw new UndergroundRuntimeException(
                        $deposit
                            ? 'underground_bank_insufficient_carried_shards'
                            : 'underground_bank_insufficient_banked_shards',
                        $deposit ? '手持ち残高が不足しています。' : '預金残高が不足しています。',
                    );
                }
                if ($amount > PHP_INT_MAX - $target) {
                    throw new UndergroundRuntimeException('underground_bank_balance_overflow', '移動先残高の上限を超えます。');
                }

                if ($deposit) {
                    $profile->shard_balance -= $amount;
                    $profile->banked_shard_balance += $amount;
                } else {
                    $profile->banked_shard_balance -= $amount;
                    $profile->shard_balance += $amount;
                }
                $profile->save();
            },
        );
    }

    /**
     * @param  array<string, mixed>  $requestedAllocations
     * @return array<string, mixed>
     */
    public function allocateStp(User $user, string $requestId, array $requestedAllocations): array
    {
        $allocations = [];
        foreach (AlphaV1CombatRules::STATS as $stat) {
            if (! array_key_exists($stat, $requestedAllocations)) {
                continue;
            }
            $value = $requestedAllocations[$stat];
            if (! is_int($value) || $value < 1) {
                throw new UndergroundRuntimeException('underground_stp_allocation_invalid', 'STP配分を確認してください。');
            }
            $allocations[$stat] = $value;
        }
        if ($allocations === [] || count($allocations) !== count($requestedAllocations)) {
            throw new UndergroundRuntimeException('underground_stp_allocation_invalid', 'STP配分を確認してください。');
        }

        return $this->mutate($user, $requestId, 'stp_allocate', ['allocations' => $allocations], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($allocations): void {
            $this->assertGrowthUnlocked($profile, $intro);
            $total = array_sum($allocations);
            if ($total > $profile->unspent_stp) {
                throw new UndergroundRuntimeException('underground_stp_insufficient', '未使用STPが不足しています。');
            }
            $equipment = $this->equipmentLoadout->combatLoadout($profile);
            $currentHp = $profile->current_hp ?? $this->alphaV1Catalog->currentMaxHp(
                (string) $profile->growth_path_key,
                $profile->combat_level,
                $profile->allocatedStp(),
                $equipment,
            );
            foreach ($allocations as $stat => $value) {
                $column = 'allocated_'.$stat.'_stp';
                if ($profile->{$column} > 2_147_483_647 - $value) {
                    throw new UndergroundRuntimeException('underground_stp_allocation_overflow', 'STP配分が上限を超えます。');
                }
                $profile->{$column} += $value;
            }
            $profile->unspent_stp -= $total;
            $newMaxHp = $this->alphaV1Catalog->currentMaxHp(
                (string) $profile->growth_path_key,
                $profile->combat_level,
                $profile->allocatedStp(),
                $equipment,
            );
            $profile->current_hp = min($currentHp, $newMaxHp);
            $profile->save();
        });
    }

    /** @return array<string, mixed> */
    public function acquireSkillNode(User $user, string $requestId, string $nodeKey): array
    {
        try {
            $entry = $this->alphaV1Catalog->laboratoryCatalog()->node($nodeKey);
        } catch (InvalidArgumentException) {
            throw new UndergroundRuntimeException('underground_skill_node_unknown', 'Skill Tree nodeを確認してください。');
        }

        return $this->mutate($user, $requestId, 'skill_acquire', ['node_key' => $nodeKey], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($nodeKey, $entry): void {
            $this->assertSkillTreeUnlocked($profile, $intro);
            $catalog = $this->alphaV1Catalog->laboratoryCatalog();
            $node = $entry['node'];
            $allocation = UndergroundSkillAllocation::query()
                ->where('underground_profile_id', $profile->id)
                ->where('node_key', $nodeKey)
                ->lockForUpdate()
                ->first();
            $currentRank = $allocation instanceof UndergroundSkillAllocation ? $allocation->rank : 0;
            $maxRank = $node['max_rank'] ?? null;
            $cost = $node['point_cost_per_rank'] ?? null;
            $required = $node['invested_points_required'] ?? null;
            if (! is_int($maxRank) || ! is_int($cost) || ! is_int($required)) {
                throw new UndergroundRuntimeException('underground_skill_node_invalid', 'Skill Tree nodeを解決できません。');
            }
            if ($currentRank >= $maxRank) {
                throw new UndergroundRuntimeException('underground_skill_max_rank', 'このnodeは最大rankです。');
            }
            $allocationMap = $profile->skillAllocationMap();
            $prerequisite = $node['prerequisite'] ?? null;
            if (is_string($prerequisite) && ! isset($allocationMap[$prerequisite])) {
                throw new UndergroundRuntimeException('underground_skill_prerequisite', '前提skillが未取得です。');
            }
            if ($this->alphaV1Catalog->investedBelowGate(
                $catalog,
                $allocationMap,
                $entry['tree'],
                $required,
            ) < $required) {
                throw new UndergroundRuntimeException('underground_skill_investment_gate', '同じSkill Treeへの投資SPが不足しています。');
            }
            if ($profile->skill_points_unspent < $cost) {
                throw new UndergroundRuntimeException('underground_skill_points_insufficient', '未使用SPが不足しています。');
            }

            $profile->skill_points_unspent -= $cost;
            $profile->save();
            if ($allocation instanceof UndergroundSkillAllocation) {
                $allocation->rank++;
                $allocation->save();
            } else {
                UndergroundSkillAllocation::query()->create([
                    'underground_profile_id' => $profile->id,
                    'node_key' => $nodeKey,
                    'rank' => 1,
                    'active_slot' => null,
                ]);
            }
        });
    }

    /**
     * @param  array<int, mixed>  $slots
     * @return array<string, mixed>
     */
    public function updateActiveLoadout(User $user, string $requestId, array $slots): array
    {
        if (count($slots) !== AlphaV1CombatRules::ACTIVE_SKILL_LIMIT || ! array_is_list($slots)) {
            throw new UndergroundRuntimeException('underground_active_loadout_invalid', 'active skill slotを確認してください。');
        }
        $selected = array_values(array_filter($slots, 'is_string'));
        if (count($selected) !== count(array_unique($selected))) {
            throw new UndergroundRuntimeException('underground_active_loadout_duplicate', '同じskillを複数slotへ設定できません。');
        }

        return $this->mutate($user, $requestId, 'active_loadout', ['slots' => $slots], function (
            Secretary $secretary,
            UndergroundProfile $profile,
            UndergroundIntroProgress $intro,
        ) use ($slots): void {
            $this->assertSkillTreeUnlocked($profile, $intro);
            $catalog = $this->alphaV1Catalog->laboratoryCatalog();
            $rows = UndergroundSkillAllocation::query()
                ->where('underground_profile_id', $profile->id)
                ->lockForUpdate()
                ->get();
            $bySkill = [];
            foreach ($rows as $allocation) {
                $entry = $catalog->node($allocation->node_key);
                $node = $entry['node'];
                if (($node['type'] ?? null) === 'active' && is_string($node['skill_key'] ?? null)) {
                    $bySkill[$node['skill_key']] = $allocation;
                }
            }
            foreach ($slots as $skillKey) {
                if ($skillKey !== null && (! is_string($skillKey) || ! isset($bySkill[$skillKey]))) {
                    throw new UndergroundRuntimeException(
                        'underground_active_skill_unacquired',
                        '取得済みのactive skillだけをslotへ設定できます。',
                    );
                }
            }

            UndergroundSkillAllocation::query()
                ->where('underground_profile_id', $profile->id)
                ->whereNotNull('active_slot')
                ->update(['active_slot' => null, 'updated_at' => Carbon::now()]);
            foreach ($slots as $index => $skillKey) {
                if (is_string($skillKey)) {
                    UndergroundSkillAllocation::query()
                        ->whereKey($bySkill[$skillKey]->getKey())
                        ->update(['active_slot' => $index + 1]);
                }
            }
        });
    }

    /**
     * @param  array<mixed>|null  $rules
     * @return array<string, mixed>
     */
    public function updateAiConfiguration(User $user, string $requestId, ?array $rules): array
    {
        $catalog = $this->alphaV1Catalog->laboratoryCatalog();
        try {
            $normalized = $rules === null
                ? null
                : $this->aiConfiguration->normalizeRules($rules, $catalog);
        } catch (InvalidArgumentException) {
            throw new UndergroundRuntimeException(
                'underground_ai_rules_invalid',
                'AI ruleの条件、action、または移動先を確認してください。',
            );
        }

        return $this->mutate(
            $user,
            $requestId,
            'ai_configuration',
            ['rules' => $normalized],
            function (
                Secretary $secretary,
                UndergroundProfile $profile,
                UndergroundIntroProgress $intro,
            ) use ($normalized): void {
                $this->assertSkillTreeUnlocked($profile, $intro);
                $profile->custom_ai_rules = $normalized;
                $profile->save();
            },
        );
    }

    /** @return array<string, mixed> */
    public function updateAwakeningMessage(User $user, string $requestId, ?string $message): array
    {
        try {
            $normalized = $this->awakening->normalizeMessage($message);
        } catch (InvalidArgumentException) {
            throw new UndergroundRuntimeException(
                'underground_awakening_message_invalid',
                '覚醒演出文は改行なしの100文字以内で入力してください。',
            );
        }

        return $this->mutate(
            $user,
            $requestId,
            'awakening_message',
            ['message' => $normalized],
            function (
                Secretary $secretary,
                UndergroundProfile $profile,
                UndergroundIntroProgress $intro,
            ) use ($normalized): void {
                $this->assertGrowthUnlocked($profile, $intro);
                $unlocked = UndergroundTrialProgress::query()
                    ->where('underground_profile_id', $profile->id)
                    ->where('trial_key', $this->runtimeCatalog->firstTrialKey())
                    ->whereNotNull('first_cleared_at')
                    ->lockForUpdate()
                    ->exists();
                if (! $unlocked) {
                    throw new UndergroundRuntimeException(
                        'underground_awakening_locked',
                        '覚醒演出文は一つ目の封印の地を初回制覇すると設定できます。',
                    );
                }
                $profile->awakening_message = $normalized;
                $profile->save();
            },
        );
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
            ->whereIn('activity_type', [
                UndergroundBattle::ACTIVITY_TUTORIAL,
                UndergroundBattle::ACTIVITY_STORY,
                UndergroundBattle::ACTIVITY_PLAYTEST,
                UndergroundBattle::ACTIVITY_EXPLORATION,
                UndergroundBattle::ACTIVITY_TRIAL,
            ])
            ->withExists([
                'log as active_log_exists' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
            ])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(20)
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
                ->whereIn('activity_type', [
                    UndergroundBattle::ACTIVITY_TUTORIAL,
                    UndergroundBattle::ACTIVITY_STORY,
                    UndergroundBattle::ACTIVITY_PLAYTEST,
                    UndergroundBattle::ACTIVITY_EXPLORATION,
                    UndergroundBattle::ACTIVITY_TRIAL,
                ])
                ->with(['log' => fn ($query) => $query->where('expires_at', '>', Carbon::now())])
                ->first()
            : null;
        if (! $battle instanceof UndergroundBattle) {
            throw new UndergroundRuntimeException('underground_battle_not_found', '戦闘履歴が見つかりません。');
        }

        return $this->projectBattle($battle, true);
    }

    /**
     * @param  array<string, mixed>  $payload
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

                return $this->projectState(
                    $secretary,
                    $profile->refresh(),
                    $this->loadActiveStoryBattles($intro->refresh()),
                );
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
                $this->loadActiveStoryBattles($intro->refresh()),
            );
        }, 3);
    }

    private function loadActiveStoryBattles(UndergroundIntroProgress $intro): UndergroundIntroProgress
    {
        return $intro->load([
            'tutorialBattle.log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
            'scriptedLossBattle.log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
        ]);
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
        if ($profile->growth_path_key !== null) {
            $this->starterEquipment->reconcile($profile);
        }

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

    private function assertShopUnlocked(
        UndergroundProfile $profile,
        UndergroundIntroProgress $intro,
    ): void {
        if ($intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            || $profile->underground_contract_completed_at === null
            || ! is_string($profile->growth_path_key)) {
            throw new UndergroundRuntimeException(
                'underground_shop_locked',
                '宿と銀行は地下の案内が完了すると利用できます。',
            );
        }
    }

    private function assertGrowthUnlocked(
        UndergroundProfile $profile,
        UndergroundIntroProgress $intro,
    ): void {
        if ($intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            || $profile->underground_contract_completed_at === null
            || ! is_string($profile->growth_path_key)
            || $profile->growth_path_identity !== $this->alphaV1Catalog->growthIdentity()) {
            throw new UndergroundRuntimeException(
                'underground_progression_locked',
                '成長機能は地下の案内と成長方針の選択後に利用できます。',
            );
        }
    }

    private function assertSkillTreeUnlocked(
        UndergroundProfile $profile,
        UndergroundIntroProgress $intro,
    ): void {
        $this->assertGrowthUnlocked($profile, $intro);
        if ($profile->skill_tree_identity !== $this->alphaV1Catalog->skillTreeIdentity()
            || $profile->skill_points_total < $this->alphaV1Catalog->initialSkillPoints()
            || $profile->skill_points_unspent > $profile->skill_points_total) {
            throw new UndergroundRuntimeException(
                'underground_skill_tree_identity_mismatch',
                '保存済みSkill Tree identityをcurrent contentとして解釈できません。',
            );
        }
    }

    private function settleStoryBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $battleKey,
        string $playerDisplayName,
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
                'player_display_name' => $playerDisplayName,
                'presentation_log_version' => 1,
                'summary' => [
                    'result' => $battleKey === 'tutorial' ? 'victory' : 'defeat',
                    'rounds' => $result->rounds,
                    'player_remaining_hp' => $result->playerRemainingHp,
                    'enemy_remaining_hp' => $result->enemyRemainingHp,
                    'damage_dealt' => $result->damageDealt,
                    'damage_received' => $result->damageReceived,
                    'effective_healing' => $result->healingDone,
                ],
                'max_rounds' => $definition['max_rounds'],
                'penalty_policy' => 'none',
            ],
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $this->battleLogProjector->project(
                $result,
                $playerDisplayName,
                $definition['display_name'],
            ),
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

    private function settleTrueNameStoryBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $playerDisplayName,
    ): UndergroundBattle {
        $definition = $this->alphaV1Catalog->trueNameStoryBattle();
        $before = [
            'level' => $profile->combat_level,
            'xp' => $profile->combat_xp,
            'shards' => $profile->shard_balance,
            'next_battle_at' => $profile->next_battle_at?->toAtomString(),
            'growth_path_key' => $profile->growth_path_key,
        ];
        $startedAt = Carbon::now();
        $result = $this->alphaV1Combat->fight(
            $definition['catalog'],
            $definition['build_key'],
            $definition['enemy_key'],
            $definition['tier_key'],
            $definition['seed'],
            $definition['max_rounds'],
            null,
            [],
            $definition['enemy_scale_bps'],
        );
        if ($result->rulesIdentity !== AlphaV1CombatRules::IDENTITY
            || $result->winner !== $definition['expected_winner']
            || $result->rounds < 1
            || $result->rounds > $definition['max_rounds']
            || $result->abnormalState !== []) {
            throw new UndergroundRuntimeException(
                'underground_story_combat_contract_failed',
                'story戦闘がcontract外の結果になったためsettlementを取り消しました。',
            );
        }
        $profile->refresh();
        $after = [
            'level' => $profile->combat_level,
            'xp' => $profile->combat_xp,
            'shards' => $profile->shard_balance,
            'next_battle_at' => $profile->next_battle_at?->toAtomString(),
            'growth_path_key' => $profile->growth_path_key,
        ];
        if ($before !== $after) {
            throw new UndergroundRuntimeException(
                'underground_scripted_loss_penalty_invalid',
                'story戦闘が進捗へ影響したためsettlementを取り消しました。',
            );
        }
        $finishedAt = Carbon::now();
        $projection = $this->alphaV1Projector->project(
            $result,
            $definition['catalog'],
            $playerDisplayName,
            'リカ',
        );
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => $this->fingerprint('scripted_loss', []),
            'runtime_identity' => $result->rulesIdentity,
            'activity_type' => UndergroundBattle::ACTIVITY_STORY,
            'activity_key' => 'guide_true_name_loss_alpha_v1',
            'encounter_key' => 'guide_story_battle',
            'trial_run_key' => null,
            'trial_battle_index' => null,
            'result' => UndergroundBattle::RESULT_DEFEAT,
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
            'private_seed' => $definition['seed'],
            'snapshot' => [
                'story_identity' => $this->catalog->identity(),
                'combat_rules_identity' => $result->rulesIdentity,
                'ai' => $definition['ai'],
                'encounter_display_name' => 'リカ',
                'player_display_name' => $playerDisplayName,
                'presentation_log_version' => 1,
                'enemy_combat_level_equivalent' => $definition['combat_level_equivalent'],
                'enemy_scale_bps' => $definition['enemy_scale_bps'],
                'summary' => $projection['summary'],
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

        return $battle->load('log');
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
        $growthPath = null;
        $currentStats = null;
        $combatStats = null;
        $equipment = null;
        $equipmentSummary = null;
        $maxHp = null;
        $currentHp = null;
        $statusBreakdown = null;
        $skillAllocations = [];
        $skillBuild = null;
        $skillTrees = null;
        $aiState = null;
        $activeSlots = array_fill(0, AlphaV1CombatRules::ACTIVE_SKILL_LIMIT, null);
        if ($profile instanceof UndergroundProfile && $profile->growth_path_key !== null) {
            $growthPath = $this->alphaV1Catalog->growthPath($profile->growth_path_key);
            $baselineStats = $growthPath['stats'];
            $currentStats = $this->alphaV1Catalog->currentStats(
                $profile->growth_path_key,
                $profile->combat_level,
                $profile->allocatedStp(),
            );
            $equipment = $this->equipmentLoadout->combatLoadout($profile);
            $equipmentSummary = $this->equipmentLoadout->summary($profile);
            $combatStats = $this->alphaV1Catalog->combatStats($currentStats, $equipment);
            $growthPath['stats'] = $currentStats;
            $maxHp = $this->alphaV1Catalog->maxHp($combatStats, $equipment);
            $currentHp = min($profile->current_hp ?? $maxHp, $maxHp);
            $growthPath['max_hp'] = $maxHp;
            $statusBreakdown = [];
            foreach (AlphaV1CombatRules::STATS as $stat) {
                $statusBreakdown[$stat] = [
                    'baseline' => $baselineStats[$stat],
                    'natural_growth' => $growthPath['natural_growth'][$stat] * ($profile->combat_level - 1),
                    'allocated_stp' => $profile->allocatedStp()[$stat],
                    'equipment' => $equipment['stats'][$stat],
                    'final' => $combatStats[$stat],
                ];
            }
            $skillAllocations = $profile->skillAllocationMap();
            if ($profile->skill_tree_identity === $this->alphaV1Catalog->skillTreeIdentity()) {
                $skillBuild = $this->alphaV1Catalog->playerSkillBuild($skillAllocations);
                $weaponStyle = $equipment['weapon_style'] ?? null;
                if (! is_string($weaponStyle) || $weaponStyle === '') {
                    throw new RuntimeException('Underground player weapon style is invalid.');
                }
                $aiSkillBuild = $this->alphaV1Catalog->playerSkillBuild($skillAllocations, $weaponStyle);
                $skillTrees = $this->alphaV1Catalog->skillTrees(
                    $skillAllocations,
                    $profile->skill_points_unspent,
                );
                $catalog = $this->alphaV1Catalog->laboratoryCatalog();
                $defaultAiRules = $this->aiConfiguration->defaultRules($aiSkillBuild['ai_rules'], $catalog);
                try {
                    $effectiveAiRules = $profile->custom_ai_rules === null
                        ? $defaultAiRules
                        : $this->aiConfiguration->normalizeRules($profile->custom_ai_rules, $catalog);
                } catch (InvalidArgumentException $exception) {
                    throw new RuntimeException('Persisted Underground AI rules are invalid.', previous: $exception);
                }
                $aiState = [
                    'schema_version' => PriorityCombatAiConfiguration::SCHEMA_VERSION,
                    'max_rules' => AlphaV1CombatRules::AI_RULE_LIMIT,
                    'max_conditions_per_rule' => PriorityCombatAiConfiguration::MAX_CONDITIONS_PER_RULE,
                    'is_custom' => $profile->custom_ai_rules !== null,
                    'rules' => $effectiveAiRules,
                    'default_rules' => $defaultAiRules,
                    'hash' => $this->aiConfiguration->hash($effectiveAiRules),
                    'catalog' => $this->aiConfiguration->editorCatalog($catalog),
                ];
                foreach ($skillAllocations as $nodeKey => $allocation) {
                    if ($allocation['active_slot'] === null) {
                        continue;
                    }
                    $node = $catalog->node($nodeKey)['node'];
                    $skillKey = $node['skill_key'] ?? null;
                    if (! is_string($skillKey)) {
                        throw new RuntimeException('Underground active skill allocation is invalid.');
                    }
                    $skill = $catalog->skill($skillKey);
                    $label = $skill['label'] ?? null;
                    if (! is_string($label) || $label === '') {
                        throw new RuntimeException('Underground active skill label is invalid.');
                    }
                    $activeSlots[$allocation['active_slot'] - 1] = [
                        'key' => $skillKey,
                        'label' => $label,
                        'summary' => is_string($node['summary'] ?? null) ? $node['summary'] : '',
                        'mp_cost' => $skill['mp_cost'],
                        'cooldown' => $skill['cooldown'],
                        'required_weapon_styles' => $skill['required_weapon_styles'],
                    ];
                }
            }
        }

        $trialState = $stage === UndergroundIntroStage::UNDERGROUND_OPEN
            && $profile instanceof UndergroundProfile
            && $profile->growth_path_key !== null
                ? $this->runtime->projectTrialState($profile)
                : null;
        $awakeningState = $trialState !== null
                ? $this->runtime->projectAwakeningState(
                    $profile,
                    ($trialState['first_cleared'] ?? false) === true,
                )
                : null;
        $huntingGroundState = $stage === UndergroundIntroStage::UNDERGROUND_OPEN
            && $profile instanceof UndergroundProfile
            && $profile->growth_path_key !== null
                ? $this->runtime->projectHuntingGroundState($profile)
                : null;
        $respecState = $stage === UndergroundIntroStage::UNDERGROUND_OPEN
            && $profile instanceof UndergroundProfile
            && $profile->growth_path_key !== null
                ? [
                    'cost' => $this->respecCost($profile),
                    'last_completed_at' => $profile->last_respec_at?->toAtomString(),
                    'next_available_at' => $profile->last_respec_at
                        ?->addHours(self::RESPEC_COOLDOWN_HOURS)
                        ->toAtomString(),
                    'growth_paths' => $this->alphaV1Catalog->growthPaths(),
                ]
                : null;

        return [
            'stage' => $stage,
            'secretary_name' => $secretary->name,
            'combat_level' => $profile instanceof UndergroundProfile ? $profile->combat_level : 1,
            'combat_xp' => $profile instanceof UndergroundProfile ? $profile->combat_xp : 0,
            'next_level_xp' => $profile instanceof UndergroundProfile ? $this->nextLevelXp($profile) : 100,
            'next_level_requirement' => $profile instanceof UndergroundProfile
                ? $this->nextLevelRequirement($profile)
                : 100,
            'xp_to_next_level' => $profile instanceof UndergroundProfile
                ? max(0, $this->nextLevelXp($profile) - $profile->combat_xp)
                : 100,
            'shard_balance' => $profile instanceof UndergroundProfile ? $profile->shard_balance : 0,
            'banked_shard_balance' => $profile instanceof UndergroundProfile
                ? $profile->banked_shard_balance
                : 0,
            'next_battle_at' => $profile?->next_battle_at?->toAtomString(),
            'current_hp' => $currentHp,
            'unspent_stp' => $profile instanceof UndergroundProfile ? $profile->unspent_stp : 0,
            'allocated_stp' => $profile instanceof UndergroundProfile
                ? $profile->allocatedStp()
                : ['vitality' => 0, 'might' => 0, 'finesse' => 0, 'spirit' => 0, 'agility' => 0],
            'current_stats' => $currentStats,
            'combat_stats' => $combatStats,
            'status_breakdown' => $statusBreakdown,
            'equipment' => $equipment,
            'equipment_summary' => $equipmentSummary,
            'skill_points_total' => $profile instanceof UndergroundProfile ? $profile->skill_points_total : 0,
            'skill_points_unspent' => $profile instanceof UndergroundProfile ? $profile->skill_points_unspent : 0,
            'skill_points_spent' => $profile instanceof UndergroundProfile
                ? $profile->skill_points_total - $profile->skill_points_unspent
                : 0,
            'skill_tree_identity' => $profile?->skill_tree_identity,
            'skill_trees' => $skillTrees,
            'active_slots' => $activeSlots,
            'passive_modifiers' => $skillBuild['passive_modifiers'] ?? [],
            'ai' => $aiState,
            'shopkeeper_name' => $intro?->shopkeeper_name,
            'true_name_branch' => $intro?->branch_identity === 'true_name',
            'tutorial_projection' => [
                'stats' => ['vitality' => 10, 'might' => 10, 'finesse' => 10, 'spirit' => 10, 'agility' => 10],
                'weapon' => 'starter knife',
            ],
            'contract_completed' => $profile?->underground_contract_completed_at !== null,
            'growth_paths' => $stage === UndergroundIntroStage::CRYSTAL_SELECTION
                ? $this->alphaV1Catalog->growthPaths()
                : null,
            'growth_path' => $growthPath,
            'playtest' => $stage === UndergroundIntroStage::UNDERGROUND_OPEN
                && $profile?->growth_path_key !== null
                && config('app.env') !== 'production'
                    ? $this->alphaV1Catalog->playtestOptions($profile->growth_path_key)
                    : null,
            'default_hunting_ground_key' => $huntingGroundState['default_key'] ?? null,
            'hunting_grounds' => $huntingGroundState['grounds'] ?? null,
            'respec' => $respecState,
            'trial' => $trialState,
            'awakening' => $awakeningState,
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

    private function respecCost(UndergroundProfile $profile): int
    {
        return $profile->combat_level * self::RESPEC_COST_PER_LEVEL;
    }

    private function nextLevelRequirement(UndergroundProfile $profile): int
    {
        $curve = $this->runtimeCatalog->xpCurve();

        return $this->progression->xpRequiredForNextLevel(
            $profile->combat_level,
            $curve['first_level_cost'],
            $curve['cost_increment_per_level'],
        );
    }

    /** @return array<string, mixed> */
    private function projectBattle(UndergroundBattle $battle, bool $withActions): array
    {
        if ($battle->activity_type === UndergroundBattle::ACTIVITY_PLAYTEST) {
            return $this->playtest->projectBattle($battle, $withActions);
        }
        if ($battle->activity_type === UndergroundBattle::ACTIVITY_EXPLORATION) {
            return $this->runtime->projectExplorationBattle($battle, $withActions);
        }
        if ($battle->activity_type === UndergroundBattle::ACTIVITY_TRIAL) {
            return $this->runtime->projectTrialBattle($battle, $withActions);
        }
        $snapshot = $battle->snapshot;
        $displayName = $snapshot['encounter_display_name'] ?? null;
        $playerDisplayName = $snapshot['player_display_name'] ?? null;
        $log = $this->loadedLog($battle);

        return [
            'id' => $battle->request_id,
            'context' => $battle->activity_type === UndergroundBattle::ACTIVITY_TUTORIAL ? 'tutorial' : 'scripted_loss',
            'player_display_name' => is_string($playerDisplayName) ? $playerDisplayName : '秘書',
            'encounter_name' => is_string($displayName) ? $displayName : '（ダミー）',
            'result' => $battle->result,
            'rounds' => $battle->rounds,
            'xp_awarded' => $battle->xp_awarded,
            'shard_delta' => $battle->shard_delta,
            'finished_at' => $battle->finished_at->toAtomString(),
            'detail_available' => $withActions
                ? $log instanceof UndergroundBattleLog
                : (bool) ($battle->getAttribute('active_log_exists') ?? false),
            'detail_message' => $withActions && ! ($log instanceof UndergroundBattleLog)
                ? '詳細ログは保存期間を過ぎました。'
                : null,
            'actions' => $withActions && $log instanceof UndergroundBattleLog
                ? $log->actions
                : null,
            'summary' => is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [
                'result' => $battle->result,
                'rounds' => $battle->rounds,
                'damage_dealt' => $battle->damage_dealt,
                'damage_received' => $battle->damage_received,
                'effective_healing' => $battle->healing_done,
            ],
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

    /** @param array<string, mixed> $payload */
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
