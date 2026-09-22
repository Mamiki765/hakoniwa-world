<?php

namespace App\Application\Underground;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Bounded by metric/action kinds, never by receipts or calendar buckets. */
final class UndergroundLifetimeStatistics
{
    public const JOURNAL_SCOPE = "(activity_type IN ('exploration', 'trial', 'playtest') OR (activity_type = 'tutorial' AND activity_key = 'first_descent_tutorial'))";

    private const SELF_SUMS = [
        'effective_healing', 'effective_healing_received',
        'damage_prevented', 'complete_guard_count', 'complete_guard_prevented_damage',
        'enemy_complete_guard_count', 'enemy_defeats', 'normal_attacks', 'skill_uses',
        'critical_hits', 'mp_spent', 'mp_recovered', 'knockouts', 'revivals_received',
        'revivals_performed', 'awakened_at_start', 'awakened_in_battle', 'awakening_technique_uses',
    ];

    private const PARTY_SUMS = [
        'damage_dealt', 'damage_received', 'effective_healing', 'damage_prevented',
        'complete_guard_count', 'complete_guard_prevented_damage', 'knockouts', 'revivals', 'awakened_combatants',
    ];

    private const MAPS = ['self' => ['damage_by_source', 'healing_by_source', 'action_usage'], 'party' => ['damage_by_source', 'healing_by_source']];

    /** @return array<string, mixed> */
    public function range(int $profileId, int $from, int $through): array
    {
        $totals = [];
        foreach ($this->source($profileId)->where('id', '>', $from)->where('id', '<=', $through)->orderBy('id')->cursor() as $row) {
            $totals = $this->add($totals, $row);
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    public function totals(int $profileId): array
    {
        // One SQL snapshot contains both the saved prefix and its unaggregated
        // tail. The marker sorts first; no concurrent collector can duplicate it.
        $prefix = DB::table('underground_receipt_rollups')->where('underground_profile_id', $profileId)->where('stream', 'battle')
            ->selectRaw('0::bigint AS id, lifetime_statistics AS statistics, TRUE AS saved, NULL::bigint AS underground_party_id, 0 AS damage_dealt, 0 AS damage_received, 0 AS healing_done');
        $tail = $this->source($profileId)->whereRaw("id > COALESCE((SELECT verified_through_id FROM underground_receipt_rollups WHERE underground_profile_id = ? AND stream = 'battle'), 0)", [$profileId]);
        // UNION column order is explicit, independent of the source helper.
        $tail->select(['id', 'statistics', DB::raw('FALSE AS saved'), 'underground_party_id', 'damage_dealt', 'damage_received', 'healing_done']);
        $totals = [];
        foreach ($prefix->unionAll($tail)->orderBy('id')->cursor() as $row) {
            $totals = $row->saved ? (json_decode($row->statistics ?? '[]', true, 512, JSON_THROW_ON_ERROR)) : $this->add($totals, $row);
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, mixed>
     */
    public function merge(array $left, array $right): array
    {
        if ($left === []) {
            return $right;
        }
        if ($right === []) {
            return $left;
        }
        foreach (['self' => self::SELF_SUMS, 'party' => self::PARTY_SUMS] as $scope => $keys) {
            foreach ($keys as $key) {
                foreach (['known_sum', 'known_count', 'unknown_count'] as $column) {
                    $left[$scope][$key][$column] += $right[$scope][$key][$column];
                }
            }
        }
        foreach (self::MAPS as $scope => $keys) {
            foreach ($keys as $key) {
                foreach ($right[$scope][$key]['known_sums'] as $name => $value) {
                    $left[$scope][$key]['known_sums'][$name] = ($left[$scope][$key]['known_sums'][$name] ?? 0) + $value;
                }
                $left[$scope][$key]['known_count'] += $right[$scope][$key]['known_count'];
                $left[$scope][$key]['unknown_count'] += $right[$scope][$key]['unknown_count'];
                ksort($left[$scope][$key]['known_sums']);
            }
        }
        $maximum = &$left['self']['maximum_hit'];
        $candidate = $right['self']['maximum_hit'];
        if ($candidate['value'] !== null && ($maximum['value'] === null || $candidate['value'] > $maximum['value'])) {
            $maximum['value'] = $candidate['value'];
            $maximum['action_key'] = $candidate['action_key'];
            $maximum['damage_source'] = $candidate['damage_source'];
        }
        $maximum['known_count'] += $candidate['known_count'];
        $maximum['unknown_count'] += $candidate['unknown_count'];
        foreach ($right['incomplete_reasons'] as $reason => $count) {
            $left['incomplete_reasons'][$reason] = ($left['incomplete_reasons'][$reason] ?? 0) + $count;
        }
        ksort($left['incomplete_reasons']);

        return $left;
    }

    private function source(int $profileId): Builder
    {
        return DB::table('underground_battles')->where('underground_profile_id', $profileId)->whereNotNull('finished_at')
            ->whereRaw(self::JOURNAL_SCOPE)
            ->select(['id', 'statistics', 'underground_party_id', 'damage_dealt', 'damage_received', 'healing_done']);
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function add(array $totals, stdClass $row): array
    {
        $statistics = json_decode($row->statistics ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $self = $statistics['self'] ?? [];
        $party = $statistics['party'] ?? [];
        if ($row->statistics === null) {
            $party = ['damage_dealt' => $row->damage_dealt, 'damage_received' => $row->damage_received, 'effective_healing' => $row->healing_done];
        }
        if ($row->statistics === null && $row->underground_party_id === null) {
            $self = ['damage_dealt' => $row->damage_dealt, 'damage_received' => $row->damage_received, 'effective_healing' => $row->healing_done];
        }
        $part = ['self' => [], 'party' => [], 'incomplete_reasons' => $statistics['completeness']['reasons'] ?? []];
        if ($row->statistics === null) {
            $part['incomplete_reasons']['legacy_statistics_missing'] = 1;
        }
        $scopes = ['self' => $self, 'party' => $party];
        foreach (['self' => self::SELF_SUMS, 'party' => self::PARTY_SUMS] as $scope => $keys) {
            foreach ($keys as $key) {
                $value = $scopes[$scope][$key] ?? null;
                $known = is_numeric($value) || is_bool($value);
                $part[$scope][$key] = ['known_sum' => $known ? (int) $value : 0, 'known_count' => $known ? 1 : 0, 'unknown_count' => $known ? 0 : 1];
            }
        }
        foreach (self::MAPS as $scope => $keys) {
            foreach ($keys as $key) {
                $value = $scopes[$scope][$key] ?? null;
                $known = is_array($value);
                $part[$scope][$key] = ['known_sums' => $known ? $value : [], 'known_count' => $known ? 1 : 0, 'unknown_count' => $known ? 0 : 1];
            }
        }
        $maximum = $self['maximum_hit'] ?? null;
        $part['self']['maximum_hit'] = [
            'value' => is_numeric($maximum) ? (int) $maximum : null,
            'action_key' => $self['maximum_hit_action_key'] ?? null,
            'damage_source' => $self['maximum_hit_damage_source'] ?? null,
            'known_count' => is_numeric($maximum) ? 1 : 0, 'unknown_count' => is_numeric($maximum) ? 0 : 1,
        ];

        return $this->merge($totals, $part);
    }
}
