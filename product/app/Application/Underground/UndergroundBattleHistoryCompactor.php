<?php

namespace App\Application\Underground;

use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundPartyMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class UndergroundBattleHistoryCompactor
{
    public function __construct(private UndergroundBattleStorage $storage) {}

    /** @return array{candidates:int,battle_bytes:int,member_bytes:int,self_damage_backfillable:int,self_damage_null:int,damage_source_breakdown_null:int,healing_source_breakdown_null:int,oldest_finished_at:string|null,newest_finished_at:string|null} */
    public function preview(Carbon $cutoff, int $limit): array
    {
        $battles = $this->candidates($cutoff)->limit($limit)->get();
        $battleBytes = 0;
        $memberBytes = 0;
        $selfDamageBackfillable = 0;
        $selfDamageNull = 0;
        foreach ($battles as $battle) {
            $battleBytes += $this->jsonBytes($battle->snapshot);
            if ($battle->underground_party_id !== null) {
                foreach (UndergroundPartyMember::query()
                    ->where('underground_party_id', $battle->underground_party_id)
                    ->get(['snapshot']) as $member) {
                    $memberBytes += $this->jsonBytes($member->snapshot);
                }
            }
            if ($battle->underground_party_id === null) {
                $selfDamageBackfillable++;
            } else {
                $selfDamageNull++;
            }
        }

        return [
            'candidates' => $battles->count(),
            'battle_bytes' => $battleBytes,
            'member_bytes' => $memberBytes,
            'self_damage_backfillable' => $selfDamageBackfillable,
            'self_damage_null' => $selfDamageNull,
            'damage_source_breakdown_null' => $battles->count(),
            'healing_source_breakdown_null' => $battles->count(),
            'oldest_finished_at' => $battles->min('finished_at')?->toAtomString(),
            'newest_finished_at' => $battles->max('finished_at')?->toAtomString(),
        ];
    }

    /**
     * @return array{processed:int,statistics_backfilled:int,battle_bytes_before:int,battle_bytes_after:int,member_bytes_before:int,member_bytes_after:int,stopped_by:string}
     */
    public function compact(Carbon $cutoff, int $limit, int $batchSize, int $maxSeconds): array
    {
        $started = microtime(true);
        $result = [
            'processed' => 0,
            'statistics_backfilled' => 0,
            'battle_bytes_before' => 0,
            'battle_bytes_after' => 0,
            'member_bytes_before' => 0,
            'member_bytes_after' => 0,
            'stopped_by' => 'no_candidates',
        ];

        while ($result['processed'] < $limit) {
            if (microtime(true) - $started >= $maxSeconds) {
                $result['stopped_by'] = 'time_limit';
                break;
            }
            $ids = $this->candidates($cutoff)
                ->limit(min($batchSize, $limit - $result['processed']))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($ids === []) {
                $result['stopped_by'] = $result['processed'] > 0 ? 'complete' : 'no_candidates';
                break;
            }

            foreach ($ids as $id) {
                if (microtime(true) - $started >= $maxSeconds) {
                    $result['stopped_by'] = 'time_limit';
                    break 2;
                }
                $row = $this->compactOne($id, $cutoff);
                if ($row === null) {
                    continue;
                }
                $result['processed']++;
                $result['statistics_backfilled'] += $row['statistics_backfilled'];
                foreach (['battle_bytes_before', 'battle_bytes_after', 'member_bytes_before', 'member_bytes_after'] as $key) {
                    $result[$key] += $row[$key];
                }
                if ($result['processed'] >= $limit) {
                    $result['stopped_by'] = 'row_limit';
                    break 2;
                }
            }
        }

        return $result;
    }

    /** @return Builder<UndergroundBattle> */
    private function candidates(Carbon $cutoff): Builder
    {
        $now = Carbon::now();

        return UndergroundBattle::query()
            ->whereNull('compaction_version')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<=', $cutoff)
            ->whereDoesntHave('log', static fn (Builder $query): Builder => $query->where('expires_at', '>', $now))
            ->orderBy('id');
    }

    /**
     * @return array{statistics_backfilled:int,battle_bytes_before:int,battle_bytes_after:int,member_bytes_before:int,member_bytes_after:int}|null
     */
    private function compactOne(int $battleId, Carbon $cutoff): ?array
    {
        return DB::transaction(function () use ($battleId, $cutoff): ?array {
            $battle = UndergroundBattle::query()->whereKey($battleId)->lockForUpdate()->first();
            if (! $battle instanceof UndergroundBattle
                || $battle->compaction_version !== null
                || $battle->finished_at === null
                || $battle->finished_at->isAfter($cutoff)
                || UndergroundBattleLog::query()
                    ->where('underground_battle_id', $battle->id)
                    ->where('expires_at', '>', Carbon::now())
                    ->exists()) {
                return null;
            }

            $before = $this->jsonBytes($battle->snapshot);
            $memberBefore = 0;
            $memberAfter = 0;
            if ($battle->underground_party_id !== null) {
                $members = UndergroundPartyMember::query()
                    ->where('underground_party_id', $battle->underground_party_id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($members as $member) {
                    $memberSnapshot = $member->getAttribute('snapshot');
                    if (! is_array($memberSnapshot)) {
                        throw new \RuntimeException('Underground party member snapshot is not an array.');
                    }
                    $memberBefore += $this->jsonBytes($memberSnapshot);
                    $memberSnapshot = $this->storage->compactPartyMemberSnapshot($memberSnapshot);
                    $member->setAttribute('snapshot', $memberSnapshot);
                    $member->save();
                    $memberAfter += $this->jsonBytes($memberSnapshot);
                }
            }

            $statisticsBackfilled = 0;
            if ($battle->statistics_version === null) {
                $battle->statistics_version = UndergroundBattleStatisticsProjector::VERSION;
                $battle->statistics = $this->legacyStatistics($battle);
                $statisticsBackfilled = 1;
            }
            $battle->snapshot = $this->storage->compactSnapshot($battle->snapshot);
            $battle->compaction_version = UndergroundBattleStorage::COMPACTION_VERSION;
            $battle->compacted_at = Carbon::now();
            $battle->save();

            return [
                'statistics_backfilled' => $statisticsBackfilled,
                'battle_bytes_before' => $before,
                'battle_bytes_after' => $this->jsonBytes($battle->snapshot),
                'member_bytes_before' => $memberBefore,
                'member_bytes_after' => $memberAfter,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function legacyStatistics(UndergroundBattle $battle): array
    {
        $snapshot = $battle->snapshot;
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $summaryMetrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : $summary;
        $party = is_array($snapshot['party'] ?? null) ? $snapshot['party'] : [];
        $partySize = is_numeric($party['party_size'] ?? null)
            ? max(1, (int) $party['party_size'])
            : ($battle->underground_party_id === null
                ? 1
                : max(1, UndergroundPartyMember::query()
                    ->where('underground_party_id', $battle->underground_party_id)->count()));
        $solo = $battle->underground_party_id === null;
        $awakening = is_array($snapshot['awakening'] ?? null) ? $snapshot['awakening'] : [];
        $partyAwakening = is_array($snapshot['party_awakening'] ?? null) ? $snapshot['party_awakening'] : [];
        $awakenedCount = $partyAwakening !== []
            ? count(array_filter($partyAwakening, static fn (mixed $value): bool => is_array($value) && ($value['triggered'] ?? false) === true))
            : (($awakening['triggered'] ?? false) === true ? 1 : 0);
        $selfAwakeningRound = $this->legacySelfAwakeningRound($snapshot);
        $endingHp = $solo && is_numeric($summary['player_remaining_hp'] ?? null)
            ? (int) $summary['player_remaining_hp']
            : null;

        return [
            'source' => 'legacy_backfill',
            'party_size' => $partySize,
            'completeness' => [
                'complete' => false,
                'issue_count' => $solo ? 2 : 3,
                'reasons' => array_filter([
                    'legacy_damage_source_breakdown_unavailable' => 1,
                    'legacy_healing_source_breakdown_unavailable' => 1,
                    'legacy_party_self_attribution_unavailable' => $solo ? null : 1,
                ], static fn (mixed $value): bool => $value !== null),
            ],
            'party' => [
                'damage_dealt' => $battle->damage_dealt,
                'damage_by_source' => null,
                'damage_received' => $battle->damage_received,
                'effective_healing' => $battle->healing_done,
                'healing_by_source' => null,
                'damage_prevented' => is_numeric($summaryMetrics['damage_prevented'] ?? null)
                    ? (int) $summaryMetrics['damage_prevented'] : null,
                'complete_guard_count' => null,
                'complete_guard_prevented_damage' => null,
                'knockouts' => null,
                'revivals' => null,
                'awakened_combatants' => $awakenedCount,
            ],
            'self' => [
                'damage_dealt' => $solo ? $battle->damage_dealt : null,
                'damage_by_source' => null,
                'maximum_hit' => null,
                'damage_received' => $solo ? $battle->damage_received : null,
                'effective_healing' => $solo ? $battle->healing_done : null,
                'effective_healing_received' => $solo ? $battle->healing_done : null,
                'healing_by_source' => null,
                'damage_prevented' => $solo && is_numeric($summaryMetrics['damage_prevented'] ?? null)
                    ? (int) $summaryMetrics['damage_prevented'] : null,
                'complete_guard_count' => null,
                'complete_guard_prevented_damage' => null,
                'enemy_defeats' => null,
                'normal_attacks' => null,
                'skill_uses' => null,
                'critical_hits' => null,
                'mp_spent' => $solo && is_numeric($summary['mp_spent'] ?? null) ? (int) $summary['mp_spent'] : null,
                'mp_recovered' => $solo
                    && is_numeric($summary['mp_natural_recovery'] ?? null)
                    && is_numeric($summary['mp_skill_recovery'] ?? null)
                        ? (int) $summary['mp_natural_recovery'] + (int) $summary['mp_skill_recovery'] : null,
                'minimum_hp' => null,
                'ending_hp' => $endingHp,
                'knockouts' => null,
                'revivals_received' => null,
                'revivals_performed' => null,
                'awakened_at_start' => null,
                'awakened_in_battle' => $selfAwakeningRound !== null
                    ? true
                    : (($awakening['triggered'] ?? false) === true ? null : false),
                'awakening_round' => $selfAwakeningRound,
                'awakening_technique_uses' => isset($awakening['technique']['used'])
                    ? (($awakening['technique']['used'] ?? false) === true ? 1 : 0) : null,
                'awakening_triggered' => isset($awakening['triggered'])
                    ? ($awakening['triggered'] === true) : null,
            ],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function legacySelfAwakeningRound(array $snapshot): ?int
    {
        $leaderId = null;
        $members = is_array($snapshot['party']['members'] ?? null) ? $snapshot['party']['members'] : [];
        foreach ($members as $id => $member) {
            if (is_array($member) && ($member['source_type'] ?? null) === 'self') {
                $leaderId = is_string($member['combatant_id'] ?? null)
                    ? $member['combatant_id']
                    : (is_string($id) ? $id : null);
                break;
            }
        }
        $events = is_array($snapshot['portrait_events'] ?? null) ? $snapshot['portrait_events'] : [];
        foreach ($events as $event) {
            if (is_array($event)
                && ($event['type'] ?? null) === 'awakening'
                && ($leaderId === null || ($event['combatant_id'] ?? null) === $leaderId)
                && is_numeric($event['round'] ?? null)) {
                return max(1, (int) $event['round']);
            }
        }

        return null;
    }

    private function jsonBytes(mixed $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
