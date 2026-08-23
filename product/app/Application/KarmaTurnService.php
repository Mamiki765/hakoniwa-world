<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Turn\TurnContext;
use App\Models\Nation;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

final class KarmaTurnService
{
    public function __construct(
        private readonly CapacityBoundedAssetService $boundedAssets,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @return array{karma_snapshots: int, turn_start_monsters: int} */
    public function prepare(TurnContext $context): array
    {
        $this->settings($context->ruleset);
        $nations = Nation::query()->whereIn('id', $context->state->lifecycleNationIds())
            ->orderBy('id')->lockForUpdate()->get(['id', 'name', 'karma']);
        foreach ($nations as $nation) {
            $context->state->setKarmaStartSnapshot($nation->id, (int) $nation->karma);
            $this->events->record($context, 'karma.turn_start_snapshot', $nation, [
                'nation_id' => $nation->id,
                'nation_name' => $nation->name,
                'karma' => (int) $nation->karma,
                'target_turn' => $context->targetTurn,
            ], 'admin');
        }
        $coordinates = $this->aliveMonsterCoordinates($context);
        $context->state->setMonsterCoordinateSnapshot('turn_start', $coordinates);

        return ['karma_snapshots' => $nations->count(), 'turn_start_monsters' => count($coordinates)];
    }

    public function snapshotMissileBoundary(TurnContext $context): int
    {
        $coordinates = $this->aliveMonsterCoordinates($context);
        $context->state->setMonsterCoordinateSnapshot('missile_boundary', $coordinates);
        $this->events->record($context, 'karma.monster_snapshot_classified', $context->world, [
            'boundary' => 'missile_resolution',
            'alive_monster_count' => count($coordinates),
        ], 'admin');

        return count($coordinates);
    }

    /** @return array{nations: int, requested: int, applied: int, overflow: int} */
    public function settleAllianceMoney(TurnContext $context): array
    {
        $metrics = ['nations' => 0, 'requested' => 0, 'applied' => 0, 'overflow' => 0];
        foreach ($context->state->karmaLedgers() as $nationId => $ledger) {
            if ($ledger['alliance_money'] < 1) {
                continue;
            }
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            $credit = $this->boundedAssets->creditMoney($nation, $ledger['alliance_money'], $context->ruleset);
            $metrics['nations']++;
            $metrics['requested'] += $credit->requested;
            $metrics['applied'] += $credit->applied;
            $metrics['overflow'] += $credit->overflow;
            $this->events->record($context, 'karma.alliance_money', $nation, [
                'nation_id' => $nation->id,
                'requested_money' => $credit->requested,
                'applied_money' => $credit->applied,
                'overflow_money' => $credit->overflow,
            ], 'nation');
        }

        return $metrics;
    }

    public function sanctionCount(TurnContext $context, int $nationId): int
    {
        $maximum = $this->settings($context->ruleset)['maximum'];
        $ledger = $context->state->karmaLedgerForNation($nationId);

        return max(0, $context->state->karmaStartSnapshot($nationId) + $ledger['crime_points'] - $maximum);
    }

    /** @return array{nations: int, changed: int, crime_points: int, victim_reductions: int, decay_reductions: int, recovery_reductions: int, monster_kill_reductions: int} */
    public function finalize(TurnContext $context): array
    {
        $settings = $this->settings($context->ruleset);
        $metrics = [
            'nations' => 0, 'changed' => 0, 'crime_points' => 0,
            'victim_reductions' => 0, 'decay_reductions' => 0,
            'recovery_reductions' => 0, 'monster_kill_reductions' => 0,
        ];
        foreach ($context->state->karmaLedgers() as $nationId => $ledger) {
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            $start = $context->state->karmaStartSnapshot($nationId);
            if ((int) $nation->karma !== $start) {
                throw new DomainException('Persistent KARMA changed before the canonical Turn finalization boundary.');
            }
            $preCap = $start + $ledger['crime_points'];
            $expectedSanctions = max(0, $preCap - $settings['maximum']);
            if ($ledger['sanction_count'] !== $expectedSanctions) {
                throw new DomainException('KARMA sanction overflow was not resolved exactly once.');
            }
            $candidate = min($settings['maximum'], $preCap);
            $victimReduction = min(
                max(0, $start),
                $ledger['hostile_impacts_received'] * $settings['victim_reduction_per_impact'],
            );
            if ($candidate >= 0) {
                $candidate = max(0, $candidate - $victimReduction);
            }
            $decay = $context->targetTurn % $settings['decay_interval_turns'] === 0 && $candidate > 0
                ? min($candidate, $settings['decay_amount'])
                : 0;
            $candidate -= $decay;
            $recoveryReduction = $ledger['recovery_entry'] && $candidate > 0
                ? min($candidate, $settings['recovery_entry_reduction'])
                : 0;
            $candidate -= $recoveryReduction;
            $monsterKillReduction = $ledger['foreign_monster_kill']
                ? min($settings['foreign_monster_kill_reduction'], $candidate - $settings['minimum'])
                : 0;
            $candidate = max($settings['minimum'], $candidate - $monsterKillReduction);
            if ($candidate !== $start) {
                $nation->update(['karma' => $candidate]);
                $metrics['changed']++;
            }
            $metrics['nations']++;
            $metrics['crime_points'] += $ledger['crime_points'];
            $metrics['victim_reductions'] += $victimReduction;
            $metrics['decay_reductions'] += $decay;
            $metrics['recovery_reductions'] += $recoveryReduction;
            $metrics['monster_kill_reductions'] += $monsterKillReduction;
            $this->events->record($context, 'karma.finalized', $nation, [
                'nation_id' => $nation->id,
                'start_karma' => $start,
                'crime_points' => $ledger['crime_points'],
                'pre_cap_karma' => $preCap,
                'sanction_count' => $ledger['sanction_count'],
                'victim_reduction' => $victimReduction,
                'natural_decay' => $decay,
                'recovery_reduction' => $recoveryReduction,
                'foreign_monster_kill_reduction' => $monsterKillReduction,
                'final_karma' => $candidate,
            ], 'admin');
        }

        return $metrics;
    }

    /** @return list<string> */
    private function aliveMonsterCoordinates(TurnContext $context): array
    {
        return DB::table('monster_occupancies as occupancy')
            ->join('monster_instances as monster', 'monster.id', '=', 'occupancy.monster_instance_id')
            ->join('map_cells as cell', 'cell.id', '=', 'occupancy.map_cell_id')
            ->join('map_spaces as space', 'space.id', '=', 'cell.map_space_id')
            ->where('monster.world_id', $context->world->id)
            ->where('monster.state', 'alive')
            ->where('space.key', 'surface')
            ->orderBy('cell.x')->orderBy('cell.y')->orderBy('monster.id')
            ->get(['cell.x', 'cell.y'])
            ->map(static fn (object $row): string => ((int) $row->x).':'.((int) $row->y))
            ->unique()->values()->all();
    }

    /** @return array{minimum: int, maximum: int, victim_reduction_per_impact: int, decay_interval_turns: int, decay_amount: int, recovery_entry_reduction: int, foreign_monster_kill_reduction: int} */
    private function settings(RulesetVersion $ruleset): array
    {
        $settings = $ruleset->settings['karma'] ?? null;
        if (! is_array($settings)
            || ($settings['minimum'] ?? null) !== -10
            || ($settings['maximum'] ?? null) !== 100) {
            throw new DomainException('The current Ruleset has no supported KARMA contract.');
        }

        /** @var array{minimum: int, maximum: int, victim_reduction_per_impact: int, decay_interval_turns: int, decay_amount: int, recovery_entry_reduction: int, foreign_monster_kill_reduction: int} $settings */
        return $settings;
    }
}
