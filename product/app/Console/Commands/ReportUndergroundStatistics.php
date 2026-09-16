<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundBattleStatisticsProjector;
use App\Models\UndergroundBattle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

final class ReportUndergroundStatistics extends Command
{
    protected $signature = 'underground:statistics
                            {--from= : Inclusive ISO-8601 lower bound}
                            {--to= : Inclusive ISO-8601 upper bound}
                            {--activity=* : Activity types; defaults to exploration and trial}
                            {--limit=100000 : Refuse a larger result set rather than truncate it}';

    protected $description = 'Read permanent Underground battle statistics without action logs or snapshots';

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
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1_000_000) {
            $this->error('The limit must be between 1 and 1000000.');

            return self::INVALID;
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

        $query = UndergroundBattle::query()
            ->with('profile.secretary')
            ->where('statistics_version', UndergroundBattleStatisticsProjector::VERSION)
            ->whereIn('activity_type', $activities)
            ->orderBy('finished_at')
            ->orderBy('id');
        if ($from !== null) {
            $query->where('finished_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('finished_at', '<=', $to);
        }
        $count = (clone $query)->count();
        if ($count > $limit) {
            $this->error("The result contains {$count} battles, above limit {$limit}; no partial report was emitted.");

            return self::FAILURE;
        }

        $overall = $this->emptyAggregate();
        $secretaries = [];
        $first = null;
        $last = null;
        foreach ($query->get() as $battle) {
            $statistics = $battle->statistics;
            if (! is_array($statistics)) {
                continue;
            }
            $first ??= $battle->finished_at?->toAtomString();
            $last = $battle->finished_at?->toAtomString();
            $this->add($overall, $statistics);
            $secretary = $battle->profile->secretary;
            $secretaryId = (int) $secretary->id;
            $secretaries[$secretaryId] ??= [
                'secretary_id' => $secretaryId,
                'secretary_name' => is_string($secretary->name) ? $secretary->name : null,
                ...$this->emptyAggregate(),
            ];
            $this->add($secretaries[$secretaryId], $statistics);
        }

        $this->line(json_encode([
            'statistics_version' => UndergroundBattleStatisticsProjector::VERSION,
            'activities' => $activities,
            'recorded_from' => $first,
            'recorded_to' => $last,
            ...$overall,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        ksort($secretaries, SORT_NUMERIC);
        foreach ($secretaries as $row) {
            $this->line(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function timestampOption(string $name): ?Carbon
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? Carbon::parse($value) : null;
    }

    /** @return array<string, mixed> */
    private function emptyAggregate(): array
    {
        return [
            'battle_count' => 0,
            'complete_statistics_battle_count' => 0,
            'incomplete_statistics_battle_count' => 0,
            'statistics_issue_count' => 0,
            'statistics_issue_reasons' => [],
            'party_damage_dealt' => 0,
            'self_recorded_battle_count' => 0,
            'self_damage_dealt' => 0,
            'self_maximum_hit' => 0,
            'self_awakened_battle_count' => 0,
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
        $aggregate['party_damage_dealt'] += max(0, (int) ($party['damage_dealt'] ?? 0));
        if (is_numeric($self['damage_dealt'] ?? null)) {
            $aggregate['self_recorded_battle_count']++;
            $aggregate['self_damage_dealt'] += max(0, (int) $self['damage_dealt']);
        }
        if (is_numeric($self['maximum_hit'] ?? null)) {
            $aggregate['self_maximum_hit'] = max($aggregate['self_maximum_hit'], (int) $self['maximum_hit']);
        }
        if (($self['awakened_in_battle'] ?? false) === true) {
            $aggregate['self_awakened_battle_count']++;
        }
    }
}
