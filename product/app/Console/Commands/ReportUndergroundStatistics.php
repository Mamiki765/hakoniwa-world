<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundBattleStatisticsProjector;
use App\Models\UndergroundBattle;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReportUndergroundStatistics extends Command
{
    private const CHUNK_SIZE = 500;

    protected $signature = 'underground:statistics
                            {--from= : Inclusive ISO-8601 lower bound}
                            {--to= : Inclusive ISO-8601 upper bound}
                            {--activity=* : Activity types; defaults to exploration and trial}
                            {--max-rows= : Optional safety gate; no report is emitted when exceeded}';

    protected $description = 'Stream permanent Underground battle statistics without action logs or snapshots';

    public function handle(): int
    {
        $activities = $this->option('activity');
        $activities = $activities !== []
            ? array_values(array_unique(array_map('strval', $activities)))
            : [UndergroundBattle::ACTIVITY_EXPLORATION, UndergroundBattle::ACTIVITY_TRIAL];
        $allowed = [
            UndergroundBattle::ACTIVITY_EXPLORATION,
            UndergroundBattle::ACTIVITY_TRIAL,
            UndergroundBattle::ACTIVITY_TUTORIAL,
            UndergroundBattle::ACTIVITY_STORY,
            UndergroundBattle::ACTIVITY_PLAYTEST,
            UndergroundBattle::ACTIVITY_GUIDE_DUEL,
        ];
        if (array_diff($activities, $allowed) !== []) {
            $this->error('An unsupported activity type was requested.');

            return self::INVALID;
        }
        $maxRowsOption = $this->option('max-rows');
        $maxRows = null;
        if ($maxRowsOption !== null && trim((string) $maxRowsOption) !== '') {
            $maxRows = filter_var($maxRowsOption, FILTER_VALIDATE_INT);
            if (! is_int($maxRows) || $maxRows < 1 || $maxRows > 100_000_000) {
                $this->error('max-rows must be between 1 and 100000000 when specified.');

                return self::INVALID;
            }
        }
        try {
            $from = $this->timestampOption('from');
            $to = $this->timestampOption('to');
        } catch (Throwable) {
            $this->error('from or to is not a valid timestamp.');

            return self::INVALID;
        }
        if ($from !== null && $to !== null && $from->isAfter($to)) {
            $this->error('from must not be later than to.');

            return self::INVALID;
        }

        $query = DB::table('underground_battles as battles')
            ->join('underground_profiles as profiles', 'profiles.id', '=', 'battles.underground_profile_id')
            ->join('secretaries', 'secretaries.id', '=', 'profiles.secretary_id')
            ->where('battles.statistics_version', UndergroundBattleStatisticsProjector::VERSION)
            ->whereIn('battles.activity_type', $activities)
            ->select([
                'battles.id as battle_id',
                'battles.statistics',
                'battles.finished_at',
                'profiles.secretary_id',
                'secretaries.name as secretary_name',
            ]);
        $this->applyRange($query, 'battles.finished_at', $from, $to);

        $overall = $this->emptyAggregate();
        $secretaries = [];
        $first = null;
        $last = null;
        $rowCount = 0;
        foreach ($query->lazyById(self::CHUNK_SIZE, 'battles.id', 'battle_id') as $row) {
            $rowCount++;
            if ($maxRows !== null && $rowCount > $maxRows) {
                $this->error("The result exceeds max-rows {$maxRows}; no partial report was emitted.");

                return self::FAILURE;
            }
            $statistics = $this->statisticsPayload($row->statistics);
            $finishedAt = Carbon::parse((string) $row->finished_at);
            if ($first === null || $finishedAt->isBefore($first)) {
                $first = $finishedAt->copy();
            }
            if ($last === null || $finishedAt->isAfter($last)) {
                $last = $finishedAt->copy();
            }
            $this->add($overall, $statistics);
            $secretaryId = (int) $row->secretary_id;
            $secretaries[$secretaryId] ??= [
                'secretary_id' => $secretaryId,
                'secretary_name' => is_string($row->secretary_name) ? $row->secretary_name : null,
                ...$this->emptyAggregate(),
            ];
            $this->add($secretaries[$secretaryId], $statistics);
        }

        $lending = DB::table('secretary_lending_participations as participations')
            ->join('underground_battles as participation_battles', 'participation_battles.id', '=', 'participations.underground_battle_id')
            ->join('secretaries', 'secretaries.id', '=', 'participations.secretary_id')
            ->whereIn('participation_battles.activity_type', $activities)
            ->selectRaw('participations.secretary_id, secretaries.name as secretary_name, COUNT(*) as participation_count')
            ->groupBy('participations.secretary_id', 'secretaries.name')
            ->orderBy('participations.secretary_id');
        $this->applyRange($lending, 'participation_battles.finished_at', $from, $to);
        foreach ($lending->cursor() as $row) {
            $secretaryId = (int) $row->secretary_id;
            $count = max(0, (int) $row->participation_count);
            $overall['rental_participation_count'] += $count;
            $secretaries[$secretaryId] ??= [
                'secretary_id' => $secretaryId,
                'secretary_name' => is_string($row->secretary_name) ? $row->secretary_name : null,
                ...$this->emptyAggregate(),
            ];
            $secretaries[$secretaryId]['rental_participation_count'] += $count;
        }

        $this->finalizeAggregate($overall);
        $this->line(json_encode([
            'statistics_version' => UndergroundBattleStatisticsProjector::VERSION,
            'activities' => $activities,
            'recorded_from' => $first?->toAtomString(),
            'recorded_to' => $last?->toAtomString(),
            ...$overall,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        ksort($secretaries, SORT_NUMERIC);
        foreach ($secretaries as &$row) {
            $this->finalizeAggregate($row);
            $this->line(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        unset($row);

        return self::SUCCESS;
    }

    private function timestampOption(string $name): ?Carbon
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? Carbon::parse($value) : null;
    }

    private function applyRange(Builder $query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }
        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    /** @return array<string, mixed> */
    private function statisticsPayload(mixed $value): array
    {
        try {
            $decoded = is_array($value)
                ? $value
                : (is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : null);
        } catch (Throwable) {
            $decoded = null;
        }
        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'completeness' => [
                'complete' => false,
                'issue_count' => 1,
                'reasons' => ['statistics_payload_invalid' => 1],
            ],
            'party' => ['damage_dealt' => null],
            'self' => [
                'damage_dealt' => null,
                'maximum_hit' => null,
                'action_usage' => null,
                'awakened_in_battle' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyAggregate(): array
    {
        $coverage = static fn (): array => [
            'recorded_battle_count' => 0,
            'unknown_battle_count' => 0,
            'not_recorded_battle_count' => 0,
        ];

        return [
            'battle_count' => 0,
            'complete_statistics_battle_count' => 0,
            'incomplete_statistics_battle_count' => 0,
            'statistics_issue_count' => 0,
            'statistics_issue_reasons' => [],
            'party_damage_dealt' => null,
            'self_recorded_battle_count' => 0,
            'self_damage_dealt' => null,
            'self_maximum_hit' => null,
            'self_maximum_hit_action_key' => null,
            'self_maximum_hit_damage_source' => null,
            'self_action_usage' => null,
            'self_most_used_action_key' => null,
            'self_awakened_battle_count' => null,
            'rental_participation_count' => 0,
            'field_coverage' => [
                'party.damage_dealt' => $coverage(),
                'self.damage_dealt' => $coverage(),
                'self.maximum_hit' => $coverage(),
                'self.action_usage' => $coverage(),
                'self.awakened_in_battle' => $coverage(),
            ],
        ];
    }

    /** @param array<string, mixed> $aggregate
     * @param  array<string, mixed>  $statistics
     */
    private function add(array &$aggregate, array $statistics): void
    {
        $party = is_array($statistics['party'] ?? null) ? $statistics['party'] : [];
        $self = is_array($statistics['self'] ?? null) ? $statistics['self'] : [];
        $completeness = is_array($statistics['completeness'] ?? null) ? $statistics['completeness'] : [];
        $aggregate['battle_count']++;
        if (($completeness['complete'] ?? false) === true) {
            $aggregate['complete_statistics_battle_count']++;
        } else {
            $aggregate['incomplete_statistics_battle_count']++;
        }
        $aggregate['statistics_issue_count'] += max(0, (int) ($completeness['issue_count'] ?? 0));
        $reasons = is_array($completeness['reasons'] ?? null) ? $completeness['reasons'] : [];
        foreach ($reasons as $reason => $count) {
            if (! is_string($reason) || ! is_numeric($count)) {
                continue;
            }
            $aggregate['statistics_issue_reasons'][$reason]
                = ($aggregate['statistics_issue_reasons'][$reason] ?? 0) + max(0, (int) $count);
        }
        ksort($aggregate['statistics_issue_reasons']);

        $this->recordCoverage($aggregate, 'party.damage_dealt', $party, 'damage_dealt', 'numeric');
        if (is_numeric($party['damage_dealt'] ?? null)) {
            $aggregate['party_damage_dealt'] = ($aggregate['party_damage_dealt'] ?? 0)
                + max(0, (int) $party['damage_dealt']);
        }

        $this->recordCoverage($aggregate, 'self.damage_dealt', $self, 'damage_dealt', 'numeric');
        if (is_numeric($self['damage_dealt'] ?? null)) {
            $aggregate['self_recorded_battle_count']++;
            $aggregate['self_damage_dealt'] = ($aggregate['self_damage_dealt'] ?? 0)
                + max(0, (int) $self['damage_dealt']);
        }

        $this->recordCoverage($aggregate, 'self.maximum_hit', $self, 'maximum_hit', 'numeric');
        if (is_numeric($self['maximum_hit'] ?? null)) {
            $candidate = [
                'damage' => max(0, (int) $self['maximum_hit']),
                'action_key' => is_string($self['maximum_hit_action_key'] ?? null)
                    ? $self['maximum_hit_action_key'] : null,
                'damage_source' => is_string($self['maximum_hit_damage_source'] ?? null)
                    ? $self['maximum_hit_damage_source'] : null,
            ];
            if ($this->preferMaximum($candidate, $aggregate)) {
                $aggregate['self_maximum_hit'] = $candidate['damage'];
                $aggregate['self_maximum_hit_action_key'] = $candidate['action_key'];
                $aggregate['self_maximum_hit_damage_source'] = $candidate['damage_source'];
            }
        }

        $this->recordCoverage($aggregate, 'self.action_usage', $self, 'action_usage', 'array');
        if (is_array($self['action_usage'] ?? null)) {
            $aggregate['self_action_usage'] ??= [];
            foreach ($self['action_usage'] as $actionKey => $count) {
                if (! is_string($actionKey) || $actionKey === '' || ! is_numeric($count)) {
                    continue;
                }
                $aggregate['self_action_usage'][$actionKey]
                    = ($aggregate['self_action_usage'][$actionKey] ?? 0) + max(0, (int) $count);
            }
        }
        $this->recordCoverage($aggregate, 'self.awakened_in_battle', $self, 'awakened_in_battle', 'boolean');
        if (is_bool($self['awakened_in_battle'] ?? null)) {
            $aggregate['self_awakened_battle_count'] = ($aggregate['self_awakened_battle_count'] ?? 0)
                + ($self['awakened_in_battle'] ? 1 : 0);
        }
    }

    /** @param array<string, mixed> $aggregate
     * @param  array<string, mixed>  $container
     */
    private function recordCoverage(
        array &$aggregate,
        string $coverageKey,
        array $container,
        string $field,
        string $type,
    ): void {
        if (! array_key_exists($field, $container)) {
            $aggregate['field_coverage'][$coverageKey]['not_recorded_battle_count']++;

            return;
        }
        $recorded = match ($type) {
            'array' => is_array($container[$field]),
            'boolean' => is_bool($container[$field]),
            default => is_numeric($container[$field]),
        };
        $key = $recorded ? 'recorded_battle_count' : 'unknown_battle_count';
        $aggregate['field_coverage'][$coverageKey][$key]++;
    }

    /** @param array{damage:int,action_key:string|null,damage_source:string|null} $candidate
     * @param  array<string, mixed>  $aggregate
     */
    private function preferMaximum(array $candidate, array $aggregate): bool
    {
        if ($aggregate['self_maximum_hit'] === null || $candidate['damage'] > $aggregate['self_maximum_hit']) {
            return true;
        }
        if ($candidate['damage'] < $aggregate['self_maximum_hit']) {
            return false;
        }
        $currentKey = $aggregate['self_maximum_hit_action_key'];
        if ($candidate['action_key'] === $currentKey) {
            return strcmp($candidate['damage_source'] ?? '', $aggregate['self_maximum_hit_damage_source'] ?? '') < 0;
        }

        return $candidate['action_key'] !== null
            && ($currentKey === null || strcmp($candidate['action_key'], $currentKey) < 0);
    }

    /** @param array<string, mixed> $aggregate */
    private function finalizeAggregate(array &$aggregate): void
    {
        if (! is_array($aggregate['self_action_usage'])) {
            return;
        }
        ksort($aggregate['self_action_usage']);
        $bestKey = null;
        $bestCount = -1;
        foreach ($aggregate['self_action_usage'] as $actionKey => $count) {
            if ($count > $bestCount) {
                $bestKey = $actionKey;
                $bestCount = $count;
            }
        }
        $aggregate['self_most_used_action_key'] = $bestKey;
    }
}
