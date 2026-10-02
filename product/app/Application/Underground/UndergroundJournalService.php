<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\Secretary;
use App\Models\SecretaryGuideConversationTotal;
use App\Models\UndergroundContentClearProgress;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class UndergroundJournalService
{
    public function __construct(
        private UndergroundRuntimeCatalog $catalog,
        private UndergroundReceiptRollupService $rollups,
        private UndergroundLifetimeStatistics $statistics,
        private UndergroundAlphaV1PlayerCatalog $playerCatalog,
        private UndergroundLendingRewardService $lendingRewards,
        private UndergroundAwakening $awakening,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        $profile = $secretary instanceof Secretary
            ? UndergroundProfile::query()->where('secretary_id', $secretary->id)->first()
            : null;
        if ($profile === null || $profile->villa_purchased_at === null) {
            throw new UndergroundRuntimeException('underground_villa_required', '別荘を購入すると冒険日誌を読めます。');
        }

        $totals = $this->rollups->totals($profile->id);
        $count = $totals['battle_count'];
        $statistics = $this->statistics->totals($profile->id);
        $actionNames = $this->actionNames();

        $clearedTrials = UndergroundTrialProgress::query()->where('underground_profile_id', $profile->id)
            ->whereNotNull('first_cleared_at')->orderBy('trial_key')->get(['trial_key', 'first_cleared_at']);
        $trials = [];
        $trophies = [];
        $hasShelf = $profile->trophy_shelf_purchased_at !== null;
        foreach ($clearedTrials as $progress) {
            $trial = $this->catalog->trial($progress->trial_key);
            $trials[] = ['key' => $progress->trial_key, 'name' => $trial['label']];
            $trophy = match ($progress->trial_key) {
                'trial_01' => ['name' => 'ワイバーンの翼', 'achievement' => '試練1クリア'],
                'trial_02' => ['name' => 'デュラハンの兜', 'achievement' => '試練2クリア'],
                default => null,
            };
            if ($hasShelf && $trophy !== null) {
                $trophies[] = ['key' => $progress->trial_key, ...$trophy,
                    'achieved_at' => $progress->first_cleared_at?->toIso8601String()];
            }
        }
        if ($hasShelf) {
            $otherworldTrophies = [
                'bahamul_beginner_1' => ['name' => '黒竜の爪(初級1)', 'achievement' => '黒竜バハムル撃破(初級1)'],
                'bahamul_intermediate_1' => ['name' => '黒竜の爪(中級1)', 'achievement' => '黒竜バハムル撃破(中級1)'],
            ];
            $otherworldClears = UndergroundContentClearProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->where('content_type', 'hunting_ground')
                ->whereIn('content_key', array_keys($otherworldTrophies))
                ->where('actual_clear_count', '>', 0)
                ->orderBy('content_key')
                ->get(['content_key', 'first_cleared_at']);
            foreach ($otherworldClears as $clear) {
                $trophies[] = [
                    'key' => $clear->content_key,
                    ...$otherworldTrophies[$clear->content_key],
                    'achieved_at' => $clear->first_cleared_at !== null
                        ? CarbonImmutable::parse($clear->first_cleared_at)->toIso8601String()
                        : null,
                ];
            }

            // The unsellable first-victory reward is timestamped with the duel's finished_at.
            // Read this permanent record, not a battle receipt that may later be pruned.
            $gram = UndergroundOwnedEquipment::query()->where('underground_profile_id', $profile->id)
                ->where('definition_key', 'demon_sword_gram')->where('instance_kind', 'fixed')->first();
            if ($gram !== null) {
                $trophies[] = ['key' => 'dream_queen', 'name' => '魔剣のレプリカ',
                    'achievement' => '夢の女王Lv1254クリア', 'achieved_at' => $gram->acquired_at->toIso8601String()];
            }
        }

        return [
            'cleared_trials' => $trials,
            'trophies' => $trophies,
            'battle_count' => $count,
            'victory_count' => $totals['victory_count'],
            'damage_dealt' => $count === 0 ? 0 : ($totals['damage_dealt_known_count'] === 0 ? null : $totals['damage_dealt_sum']),
            'damage_received' => $count === 0 ? 0 : ($totals['damage_received_known_count'] === 0 ? null : $totals['damage_received_sum']),
            'damage_dealt_unknown_battles' => $count - $totals['damage_dealt_known_count'],
            'damage_received_unknown_battles' => $count - $totals['damage_received_known_count'],
            'skip_tickets_used' => $totals['skip_tickets_used'],
            'guide_punch_count' => (int) SecretaryGuideConversationTotal::query()->where('secretary_id', $profile->secretary_id)->value('punch_count'),
            ...$this->combatRecords($statistics, $actionNames),
            'content_clears' => $this->contentClears($profile, array_column($trials, 'key')),
            'lending_participation_count' => $this->lendingRewards->lifetimeParticipationCount($user),
        ];
    }

    /** @return array<string, string> */
    private function actionNames(): array
    {
        $names = ['normal_attack' => '通常攻撃', 'counter' => '反撃'];
        foreach ($this->playerCatalog->laboratoryCatalog()->manifest()['skills'] as $key => $skill) {
            $names[$key] = $skill['label'];
        }
        foreach ($this->playerCatalog->growthPaths() as $path) {
            foreach ($this->awakening->techniques($path['key']) as $technique) {
                $names[$technique['key']] = $technique['name'];
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $statistics
     * @param  array<string, string>  $actionNames
     * @return array<string, mixed>
     */
    private function combatRecords(array $statistics, array $actionNames): array
    {
        $maximum = $statistics['self']['maximum_hit'] ?? [];
        $usage = $statistics['self']['action_usage'] ?? [];
        $skills = [];
        foreach ($usage['known_sums'] ?? [] as $key => $count) {
            if ($key !== 'normal_attack' && $count > 0) {
                $skills[] = ['key' => $key, 'name' => $actionNames[$key] ?? null, 'count' => $count];
            }
        }
        usort($skills, static fn (array $left, array $right): int => ($right['count'] <=> $left['count']) ?: strcmp($left['key'], $right['key']));
        $support = [];
        foreach (['self' => 'revivals_performed', 'party' => 'revivals'] as $scope => $revivals) {
            foreach (['effective_healing' => 'effective_healing', 'damage_prevented' => 'damage_prevented', 'revivals' => $revivals] as $name => $key) {
                $metric = $statistics[$scope][$key] ?? [];
                $known = $metric['known_count'] ?? 0;
                $unknown = $metric['unknown_count'] ?? 0;
                $support[$scope][$name] = [
                    'value' => $known === 0 && $unknown > 0 ? null : ($metric['known_sum'] ?? 0),
                    'known_battles' => $known, 'unknown_battles' => $unknown,
                ];
            }
        }

        return [
            'maximum_hit' => [
                'value' => $maximum['value'] ?? null,
                'action_name' => $actionNames[$maximum['action_key'] ?? ''] ?? null,
                'known_battles' => $maximum['known_count'] ?? 0, 'unknown_battles' => $maximum['unknown_count'] ?? 0,
            ],
            'favorite_skills' => [
                'entries' => array_slice($skills, 0, 3),
                'known_battles' => $usage['known_count'] ?? 0, 'unknown_battles' => $usage['unknown_count'] ?? 0,
            ],
            'combat_support' => $support,
        ];
    }

    /**
     * @param  list<string>  $clearedTrials
     * @return list<array<string, mixed>>
     */
    private function contentClears(UndergroundProfile $profile, array $clearedTrials): array
    {
        $names = [];
        foreach ($this->playerCatalog->explorationHuntingGrounds() as $ground) {
            $names['hunting_ground'][$ground['key']] = $ground['name'];
        }
        foreach ($this->playerCatalog->otherworld()['stages'] as $key => $stage) {
            $names['hunting_ground'][$key] = $stage['name'];
        }
        foreach ($this->catalog->trialKeys() as $key) {
            $names['trial'][$key] = $this->catalog->trial($key)['label'];
        }
        $clears = [];
        foreach (UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)
            ->orderBy('content_type')->orderBy('content_key')->get(['content_type', 'content_key', 'actual_clear_count', 'total_clear_count']) as $progress) {
            $clears[$progress->content_type.':'.$progress->content_key] = [
                'type' => $progress->content_type, 'key' => $progress->content_key,
                'name' => $names[$progress->content_type][$progress->content_key] ?? null,
                'actual_clear_count' => $progress->actual_clear_count,
                'skip_clear_count' => $progress->total_clear_count - $progress->actual_clear_count,
            ];
        }
        // A permanent first clear proves completion, not its lifetime count.
        foreach ($clearedTrials as $key) {
            $clears['trial:'.$key] ??= ['type' => 'trial', 'key' => $key, 'name' => $names['trial'][$key],
                'actual_clear_count' => null, 'skip_clear_count' => null];
        }
        ksort($clears);

        return array_values($clears);
    }
}
