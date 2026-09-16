<?php

namespace App\Application\Underground;

use App\Models\Secretary;
use App\Models\SecretaryGuideConversationTotal;
use App\Models\UndergroundBattle;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkipBatch;
use App\Models\UndergroundSkipSettlement;
use App\Models\UndergroundTrialProgress;
use App\Models\User;

final readonly class UndergroundJournalService
{
    public function __construct(private UndergroundRuntimeCatalog $catalog) {}

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

        $query = UndergroundBattle::query()->where('underground_profile_id', $profile->id)
            ->whereNotNull('finished_at')
            ->where(function ($query): void {
                $query->whereIn('activity_type', [
                    UndergroundBattle::ACTIVITY_EXPLORATION,
                    UndergroundBattle::ACTIVITY_TRIAL,
                    UndergroundBattle::ACTIVITY_PLAYTEST,
                ])->orWhere(function ($tutorial): void {
                    $tutorial->where('activity_type', UndergroundBattle::ACTIVITY_TUTORIAL)
                        ->where('activity_key', 'first_descent_tutorial');
                });
            });
        // Solo legacy totals have one owner. Never attribute a legacy party's total to its leader.
        $damageDealt = "CASE WHEN statistics IS NULL AND underground_party_id IS NULL THEN damage_dealt ELSE (statistics->'self'->>'damage_dealt')::bigint END";
        $damageReceived = "CASE WHEN statistics IS NULL AND underground_party_id IS NULL THEN damage_received ELSE (statistics->'self'->>'damage_received')::bigint END";
        $totals = $query->selectRaw("COUNT(*) AS battle_count,
            COUNT(*) FILTER (WHERE result = 'victory') AS victory_count,
            SUM({$damageDealt}) AS dealt,
            COUNT({$damageDealt}) AS dealt_known,
            SUM({$damageReceived}) AS received,
            COUNT({$damageReceived}) AS received_known")->firstOrFail();
        $count = (int) $totals->getAttribute('battle_count');

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
            'victory_count' => (int) $totals->getAttribute('victory_count'),
            'damage_dealt' => $count === 0 ? 0 : ($totals->getAttribute('dealt') === null ? null : (int) $totals->getAttribute('dealt')),
            'damage_received' => $count === 0 ? 0 : ($totals->getAttribute('received') === null ? null : (int) $totals->getAttribute('received')),
            'damage_dealt_unknown_battles' => $count - (int) $totals->getAttribute('dealt_known'),
            'damage_received_unknown_battles' => $count - (int) $totals->getAttribute('received_known'),
            'skip_tickets_used' => (int) UndergroundSkipSettlement::query()->where('underground_profile_id', $profile->id)->sum('ticket_cost')
                + (int) UndergroundSkipBatch::query()->where('underground_profile_id', $profile->id)->sum('ticket_cost'),
            'guide_punch_count' => (int) SecretaryGuideConversationTotal::query()->where('secretary_id', $profile->secretary_id)->value('punch_count'),
        ];
    }
}
