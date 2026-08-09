<?php

namespace App\Application;

use App\Domain\Award\NationAwardCatalog;
use App\Domain\Turn\TurnContext;
use App\Models\NationAward;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AwardTurnFinalizer
{
    public function __construct(private readonly MonsterKillCycleService $monsterCycles) {}

    /**
     * @return array{
     *     condition_awards: int,
     *     turn_awards: int,
     *     monster_turn_awards: int,
     *     monster_cycle_rows_initialized: int
     * }
     */
    public function finalize(TurnContext $context): array
    {
        $nationIds = $context->state->stableNationIds();
        $aggregates = $context->state->nationAggregates();
        $existing = $this->existingOneTimeAwards($context, $nationIds);
        $metrics = [
            'condition_awards' => 0,
            'turn_awards' => 0,
            'monster_turn_awards' => 0,
            'monster_cycle_rows_initialized' => 0,
        ];

        foreach ($nationIds as $nationId) {
            $aggregate = $aggregates[$nationId] ?? null;
            if ($aggregate === null) {
                throw new DomainException("Award finalization is missing Nation {$nationId} final aggregate.");
            }
            $start = $context->state->nationStartSummary($nationId);
            $values = [
                'population_loss' => max(0, $start['population'] - $aggregate['population']),
                'population' => $aggregate['population'],
                'refugees_received' => $context->state->refugeesReceivedForNation($nationId),
            ];
            foreach (NationAwardCatalog::conditionSeries() as $series) {
                $awardedThisTurn = false;
                foreach ($series['tiers'] as $tier) {
                    if (($existing[$nationId][$tier['key']] ?? null) === $context->targetTurn) {
                        $awardedThisTurn = true;
                        break;
                    }
                }
                if ($awardedThisTurn) {
                    continue;
                }
                foreach ($series['tiers'] as $tier) {
                    if (isset($existing[$nationId][$tier['key']])) {
                        continue;
                    }
                    if ($values[$series['metric']] >= $tier['threshold']
                        && $this->grant($context, $nationId, $tier['key'], false)) {
                        $metrics['condition_awards']++;
                        $existing[$nationId][$tier['key']] = $context->targetTurn;
                    }
                    break;
                }
            }
        }

        if ($context->targetTurn % 100 !== 0 || $nationIds === []) {
            return $metrics;
        }

        $maximumPopulation = max(array_map(
            static fn (int $nationId): int => $aggregates[$nationId]['population'],
            $nationIds,
        ));
        foreach ($nationIds as $nationId) {
            if ($aggregates[$nationId]['population'] === $maximumPopulation
                && $this->grant($context, $nationId, 'award.turn', true)) {
                $metrics['turn_awards']++;
            }
        }

        $cycleCounts = $this->monsterCycles->counts($context->world, $context->targetTurn, $nationIds);
        $maximumKills = max($cycleCounts);
        if ($maximumKills > 0) {
            foreach ($nationIds as $nationId) {
                if ($cycleCounts[$nationId] === $maximumKills
                    && $this->grant($context, $nationId, 'award.monster_turn', true)) {
                    $metrics['monster_turn_awards']++;
                }
            }
        }
        $metrics['monster_cycle_rows_initialized'] = $this->monsterCycles
            ->initializeNextInterval($context->world, $context->targetTurn, $nationIds);

        return $metrics;
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, array<string, int>>
     */
    private function existingOneTimeAwards(TurnContext $context, array $nationIds): array
    {
        if ($nationIds === []) {
            return [];
        }
        $existing = [];
        $rows = NationAward::query()
            ->where('world_id', $context->world->id)
            ->whereIn('nation_id', $nationIds)
            ->whereIn('award_key', NationAwardCatalog::oneTimeKeys())
            ->get(['nation_id', 'award_key', 'awarded_turn']);
        foreach ($rows as $row) {
            $existing[$row->nation_id][$row->award_key] = $row->awarded_turn;
        }

        return $existing;
    }

    private function grant(TurnContext $context, int $nationId, string $awardKey, bool $recurring): bool
    {
        if (NationAwardCatalog::definition($awardKey) === null) {
            throw new DomainException("Unknown Nation award key {$awardKey}.");
        }

        return DB::table('nation_awards')->insertOrIgnore([
            'world_id' => $context->world->id,
            'nation_id' => $nationId,
            'award_key' => $awardKey,
            'awarded_turn' => $context->targetTurn,
            'award_occurrence_key' => $recurring ? "turn:{$context->targetTurn}" : 'once',
            'created_at' => now(),
        ]) === 1;
    }
}
