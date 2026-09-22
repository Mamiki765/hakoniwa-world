<?php

namespace App\Application\Underground;

use App\Models\Secretary;
use App\Models\SecretaryGuideConversationTotal;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;

final readonly class UndergroundJournalService
{
    public function __construct(
        private UndergroundRuntimeCatalog $catalog,
        private UndergroundReceiptRollupService $rollups,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        $profile = $secretary instanceof Secretary
            ? UndergroundProfile::query()->where('secretary_id', $secretary->id)->first()
            : null;
        if (! $profile instanceof UndergroundProfile || $profile->villa_purchased_at === null) {
            throw new UndergroundRuntimeException('underground_villa_required', '別荘を購入すると冒険日誌を読めます。');
        }

        $totals = $this->rollups->totals($profile->id);
        $count = $totals['battle_count'];

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
        ];
    }
}
