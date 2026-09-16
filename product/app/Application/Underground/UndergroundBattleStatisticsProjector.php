<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\PartyCombatResult;

/** Produces the small, permanent per-battle statistics record at settlement time. */
final class UndergroundBattleStatisticsProjector
{
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public function fromSolo(BuildCombatResult $result): array
    {
        $initialPlayer = is_array($result->initialState['player'] ?? null)
            ? $result->initialState['player']
            : [];
        $initialEnemy = is_array($result->initialState['enemy'] ?? null)
            ? $result->initialState['enemy']
            : [];

        return $this->project(
            $result->actionLog,
            [
                'self' => ['team' => 'player', 'combatant_id' => 'self', ...$initialPlayer],
                'enemy:1' => ['team' => 'enemy', 'combatant_id' => 'enemy:1', ...$initialEnemy],
            ],
            [
                'self' => ['team' => 'player', 'combatant_id' => 'self', 'hp' => $result->playerRemainingHp],
                'enemy:1' => ['team' => 'enemy', 'combatant_id' => 'enemy:1', 'hp' => $result->enemyRemainingHp],
            ],
            [
                'damage_dealt' => $result->damageDealt,
                'damage_received' => $result->damageReceived,
                'effective_healing' => $result->effectiveHealing,
                'damage_prevented' => $result->damagePrevented,
            ],
            ['self' => $result->awakening],
            'self',
            false,
        );
    }

    /**
     * The original scripted-combat format predates source-attributed effects.
     * Tutorial and early story battles are provably solo, so total=self is safe,
     * while source breakdowns remain explicitly unknown.
     *
     * @return array<string, mixed>
     */
    public function fromScriptedSolo(CombatResult $result): array
    {
        $maximumHit = 0;
        $minimumHp = $result->playerRemainingHp;
        foreach ($result->actionLog as $row) {
            if (($row['side'] ?? null) === 'player' && is_numeric($row['amount'] ?? null)) {
                $maximumHit = max($maximumHit, (int) $row['amount']);
            }
            if (is_numeric($row['player_hp'] ?? null)) {
                $minimumHp = min($minimumHp, (int) $row['player_hp']);
            }
        }
        $damageBySource = ['direct' => $result->damageDealt, 'periodic' => 0, 'counter' => 0];
        $healingBySource = [
            'direct' => $result->healingDone, 'periodic' => 0, 'lifesteal' => 0,
            'regeneration' => 0, 'revival' => 0, 'awakening' => 0,
        ];
        $knockouts = $result->winner === 'enemy' ? 1 : 0;

        return [
            'source' => 'scripted_solo_summary',
            'party_size' => 1,
            'completeness' => [
                'complete' => true,
                'issue_count' => 0,
                'reasons' => [],
            ],
            'party' => [
                'damage_dealt' => $result->damageDealt,
                'damage_by_source' => $damageBySource,
                'damage_received' => $result->damageReceived,
                'effective_healing' => $result->healingDone,
                'healing_by_source' => $healingBySource,
                'damage_prevented' => null,
                'complete_guard_count' => 0,
                'complete_guard_prevented_damage' => 0,
                'knockouts' => $knockouts,
                'revivals' => 0,
                'awakened_combatants' => 0,
            ],
            'self' => [
                'damage_dealt' => $result->damageDealt,
                'damage_by_source' => $damageBySource,
                'maximum_hit' => $maximumHit,
                'damage_received' => $result->damageReceived,
                'effective_healing' => $result->healingDone,
                'effective_healing_received' => $result->healingDone,
                'healing_by_source' => $healingBySource,
                'damage_prevented' => null,
                'complete_guard_count' => 0,
                'complete_guard_prevented_damage' => 0,
                'enemy_defeats' => $result->winner === 'player' ? 1 : 0,
                'normal_attacks' => $result->normalAttackUsage,
                'skill_uses' => array_sum($result->skillUsage),
                'critical_hits' => 0,
                'mp_spent' => null,
                'mp_recovered' => null,
                'minimum_hp' => $minimumHp,
                'ending_hp' => $result->playerRemainingHp,
                'knockouts' => $knockouts,
                'revivals_received' => 0,
                'revivals_performed' => 0,
                'awakened_at_start' => false,
                'awakened_in_battle' => false,
                'awakening_round' => null,
                'awakening_technique_uses' => 0,
                'awakening_triggered' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function fromParty(PartyCombatResult $result, string $leaderCombatantId): array
    {
        return $this->project(
            $result->actionLog,
            $result->initialStates,
            $result->finalStates,
            $result->metrics,
            $result->awakening,
            $leaderCombatantId,
            true,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $logs
     * @param  array<string, array<string, mixed>>  $initialStates
     * @param  array<string, array<string, mixed>>  $finalStates
     * @param  array<string, mixed>  $metrics
     * @param  array<string, array<string, mixed>>  $awakenings
     * @return array<string, mixed>
     */
    private function project(
        array $logs,
        array $initialStates,
        array $finalStates,
        array $metrics,
        array $awakenings,
        string $leaderCombatantId,
        bool $partyMode,
    ): array {
        $partySize = 0;
        $partyAwakened = [];
        foreach ($initialStates as $id => $state) {
            if (($state['team'] ?? null) !== 'player') {
                continue;
            }
            $partySize++;
            if (($state['awakened'] ?? false) === true) {
                $partyAwakened[(string) $id] = true;
            }
        }
        foreach ($awakenings as $id => $awakening) {
            if (($initialStates[$id]['team'] ?? null) === 'player'
                && ($awakening['triggered'] ?? false) === true) {
                $partyAwakened[(string) $id] = true;
            }
        }

        $initialLeader = is_array($initialStates[$leaderCombatantId] ?? null)
            ? $initialStates[$leaderCombatantId]
            : [];
        $finalLeader = is_array($finalStates[$leaderCombatantId] ?? null)
            ? $finalStates[$leaderCombatantId]
            : [];
        $minimumHp = is_numeric($initialLeader['hp'] ?? null) ? (int) $initialLeader['hp'] : null;
        $selfDamage = 0;
        $selfMaximumHit = 0;
        $selfDamageReceived = 0;
        $selfHealing = 0;
        $selfHealingReceived = 0;
        $selfPrevented = 0;
        $selfCompleteGuards = 0;
        $selfCompleteGuardPrevented = 0;
        $selfEnemyDefeats = 0;
        $selfNormalAttacks = 0;
        $selfSkills = 0;
        $selfCriticalHits = 0;
        $selfMpSpent = 0;
        $selfMpRecovered = 0;
        $selfKnockouts = 0;
        $selfRevivalsReceived = 0;
        $selfRevivalsPerformed = 0;
        $selfAwakeningRound = null;
        $selfAwakeningTechniques = 0;
        $partyKnockouts = 0;
        $partyRevivals = 0;
        $partyDamagePrevented = 0;
        $partyDamageReceived = 0;
        $partyCompleteGuards = 0;
        $partyCompleteGuardPrevented = 0;
        $partyDamageBySource = ['direct' => 0, 'periodic' => 0, 'counter' => 0];
        $selfDamageBySource = ['direct' => 0, 'periodic' => 0, 'counter' => 0];
        $partyHealingBySource = [
            'direct' => 0, 'periodic' => 0, 'lifesteal' => 0,
            'regeneration' => 0, 'revival' => 0, 'awakening' => 0,
        ];
        $selfHealingBySource = $partyHealingBySource;
        $issues = [];
        $damageIncomplete = false;
        $healingIncomplete = false;

        foreach ($logs as $row) {
            $effectType = $row['effect_type'] ?? null;
            $actorIsSelf = $this->isSelfActor($row, $leaderCombatantId, $partyMode);
            $targetIsSelf = $this->isSelfTarget($row, $leaderCombatantId, $partyMode);
            if (in_array($effectType, ['damage', 'counter'], true)) {
                $damageSource = $this->damageSource($row);
                $damageIssue = $this->damageIssue($row, $damageSource, $partyMode);
                if ($damageSource === null || $damageIssue !== null) {
                    $issue = $damageIssue ?? 'unclassified_damage_source';
                    $issues[$issue] = ($issues[$issue] ?? 0) + 1;
                    $damageIncomplete = true;
                } else {
                    $actorId = $this->statisticsActorId($row, $damageSource);
                    $actorSide = $this->statisticsActorSide($row, $damageSource);
                    $targetId = $this->statisticsTargetId($row, $damageSource);
                    $targetSide = $this->statisticsTargetSide($row, $damageSource);
                    $actorIsSelf = $this->identityIsSelf(
                        $actorId, $actorSide, $leaderCombatantId, $partyMode,
                    );
                    $targetIsSelf = $this->identityIsSelf(
                        $targetId, $targetSide, $leaderCombatantId, $partyMode,
                    );
                    $effectiveDamage = max(0, (int) $row['effective_damage']);
                    $hpDamage = max(0, (int) $row['hp_damage']);
                    $prevented = max(0, (int) $row['prevented_damage']);
                    if ($actorSide === 'player' && $targetSide === 'enemy') {
                        $partyDamageBySource[$damageSource] += $effectiveDamage;
                    }
                    if ($actorIsSelf && $targetSide === 'enemy') {
                        $selfDamage += $effectiveDamage;
                        $selfDamageBySource[$damageSource] += $effectiveDamage;
                        $selfMaximumHit = max($selfMaximumHit, $effectiveDamage);
                        if (($row['critical'] ?? false) === true && $effectiveDamage > 0) {
                            $selfCriticalHits++;
                        }
                        if ($row['defeated'] === true) {
                            $selfEnemyDefeats++;
                        }
                    }
                    if ($targetIsSelf) {
                        $selfDamageReceived += $hpDamage;
                        $selfPrevented += $prevented;
                        $minimumHp = $minimumHp === null
                            ? (int) $row['target_hp_after']
                            : min($minimumHp, (int) $row['target_hp_after']);
                        if ($row['defeated'] === true) {
                            $selfKnockouts++;
                        }
                        if (($row['complete_guarded'] ?? false) === true) {
                            $selfCompleteGuards++;
                            $selfCompleteGuardPrevented += (int) $row['complete_guard_prevented_damage'];
                        }
                    }
                    if ($targetSide === 'player') {
                        $partyDamageReceived += $hpDamage;
                        $partyDamagePrevented += $prevented;
                        if (($row['complete_guarded'] ?? false) === true) {
                            $partyCompleteGuards++;
                            $partyCompleteGuardPrevented += (int) $row['complete_guard_prevented_damage'];
                        }
                        if ($row['defeated'] === true) {
                            $partyKnockouts++;
                        }
                    }
                }
            }

            if (in_array($effectType, ['recovery', 'revival'], true)) {
                $healingSource = $this->healingSource($row);
                $healingIssue = $this->healingIssue($row, $healingSource, $partyMode);
                if ($healingSource === null || $healingIssue !== null) {
                    $issue = $healingIssue ?? 'unclassified_healing_source';
                    $issues[$issue] = ($issues[$issue] ?? 0) + 1;
                    $healingIncomplete = true;
                } else {
                    $actorId = $this->statisticsActorId($row, $healingSource);
                    $actorSide = $this->statisticsActorSide($row, $healingSource);
                    $targetId = $this->statisticsTargetId($row, $healingSource);
                    $targetSide = $this->statisticsTargetSide($row, $healingSource);
                    $actorIsSelf = $this->identityIsSelf(
                        $actorId, $actorSide, $leaderCombatantId, $partyMode,
                    );
                    $targetIsSelf = $this->identityIsSelf(
                        $targetId, $targetSide, $leaderCombatantId, $partyMode,
                    );
                    $effectiveHealing = abs((int) $row['amount']);
                    if ($actorIsSelf) {
                        $selfHealing += $effectiveHealing;
                        $selfHealingBySource[$healingSource] += $effectiveHealing;
                    }
                    if ($targetIsSelf) {
                        $selfHealingReceived += $effectiveHealing;
                    }
                    if ($actorSide === 'player' && $targetSide === 'player') {
                        $partyHealingBySource[$healingSource] += $effectiveHealing;
                    }
                    if (($row['kind'] ?? null) === 'revival' && ($row['revived'] ?? false) === true) {
                        if ($targetSide === 'player') {
                            $partyRevivals++;
                        }
                        if ($targetIsSelf) {
                            $selfRevivalsReceived++;
                        }
                        if ($actorIsSelf) {
                            $selfRevivalsPerformed++;
                        }
                    }
                }
            }

            if ($effectType === 'mp_cost' && $actorIsSelf) {
                $selfMpSpent += max(0, (int) ($row['amount'] ?? 0));
            }
            if ($effectType === 'mp_recovery' && $actorIsSelf) {
                $selfMpRecovered += max(0, (int) ($row['amount'] ?? 0));
            }
            if (($row['kind'] ?? null) === 'decision' && $actorIsSelf) {
                $action = $row['action_key'] ?? null;
                if ($action === 'normal_attack') {
                    $selfNormalAttacks++;
                } elseif (is_string($action) && ! in_array($action, ['defend', 'awakening', 'action_skipped'], true)) {
                    $selfSkills++;
                }
            }
            if (($row['kind'] ?? null) === 'awakening' && $actorIsSelf) {
                $partyAwakened[$leaderCombatantId] = true;
                if ($selfAwakeningRound === null) {
                    $selfAwakeningRound = max(1, (int) ($row['round'] ?? 1));
                }
            } elseif (($row['kind'] ?? null) === 'awakening'
                && is_string($row['actor_id'] ?? null)
                && ($initialStates[$row['actor_id']]['team'] ?? null) === 'player') {
                $partyAwakened[$row['actor_id']] = true;
            }
            if (($row['kind'] ?? null) === 'awakening_technique' && $actorIsSelf) {
                $selfAwakeningTechniques++;
            }
        }

        $leaderAwakening = is_array($awakenings[$leaderCombatantId] ?? null)
            ? $awakenings[$leaderCombatantId]
            : [];
        $awakenedAtStart = ($initialLeader['awakened'] ?? false) === true;
        $partyDamage = $this->metric($metrics, 'damage_dealt');
        $partyReceived = $this->metric($metrics, 'damage_received');
        $partyHealing = $this->metric($metrics, 'effective_healing');
        $partyPrevented = $this->metric($metrics, 'damage_prevented');
        if ($partyDamage === null || $partyReceived === null || $partyPrevented === null) {
            $issues['missing_damage_metric'] = ($issues['missing_damage_metric'] ?? 0) + 1;
            $damageIncomplete = true;
        } elseif (! $damageIncomplete
            && (array_sum($partyDamageBySource) !== $partyDamage
                || $partyDamageReceived !== $partyReceived
                || $partyDamagePrevented !== $partyPrevented)) {
            $issues['damage_total_mismatch'] = ($issues['damage_total_mismatch'] ?? 0) + 1;
            $damageIncomplete = true;
        }
        if ($partyHealing === null) {
            $issues['missing_healing_metric'] = ($issues['missing_healing_metric'] ?? 0) + 1;
            $healingIncomplete = true;
        } elseif (! $healingIncomplete && array_sum($partyHealingBySource) !== $partyHealing) {
            $issues['healing_total_mismatch'] = ($issues['healing_total_mismatch'] ?? 0) + 1;
            $healingIncomplete = true;
        }
        ksort($issues);

        return [
            'source' => 'runtime',
            'party_size' => $partySize,
            'completeness' => [
                'complete' => $issues === [],
                'issue_count' => array_sum($issues),
                'reasons' => $issues,
            ],
            'party' => [
                'damage_dealt' => $partyDamage,
                'damage_by_source' => $damageIncomplete ? null : $partyDamageBySource,
                'damage_received' => $partyReceived,
                'effective_healing' => $partyHealing,
                'healing_by_source' => $healingIncomplete ? null : $partyHealingBySource,
                'damage_prevented' => $partyPrevented,
                'complete_guard_count' => $damageIncomplete ? null : $partyCompleteGuards,
                'complete_guard_prevented_damage' => $damageIncomplete ? null : $partyCompleteGuardPrevented,
                'knockouts' => $damageIncomplete ? null : $partyKnockouts,
                'revivals' => $healingIncomplete ? null : $partyRevivals,
                'awakened_combatants' => count($partyAwakened),
            ],
            'self' => [
                'damage_dealt' => $damageIncomplete ? null : $selfDamage,
                'damage_by_source' => $damageIncomplete ? null : $selfDamageBySource,
                'maximum_hit' => $damageIncomplete ? null : $selfMaximumHit,
                'damage_received' => $damageIncomplete ? null : $selfDamageReceived,
                'effective_healing' => $healingIncomplete ? null : $selfHealing,
                'effective_healing_received' => $healingIncomplete ? null : $selfHealingReceived,
                'healing_by_source' => $healingIncomplete ? null : $selfHealingBySource,
                'damage_prevented' => $damageIncomplete ? null : $selfPrevented,
                'complete_guard_count' => $damageIncomplete ? null : $selfCompleteGuards,
                'complete_guard_prevented_damage' => $damageIncomplete ? null : $selfCompleteGuardPrevented,
                'enemy_defeats' => $damageIncomplete ? null : $selfEnemyDefeats,
                'normal_attacks' => $selfNormalAttacks,
                'skill_uses' => $selfSkills,
                'critical_hits' => $damageIncomplete ? null : $selfCriticalHits,
                'mp_spent' => $selfMpSpent,
                'mp_recovered' => $selfMpRecovered,
                'minimum_hp' => $damageIncomplete ? null : $minimumHp,
                'ending_hp' => is_numeric($finalLeader['hp'] ?? null) ? (int) $finalLeader['hp'] : null,
                'knockouts' => $damageIncomplete ? null : $selfKnockouts,
                'revivals_received' => $healingIncomplete ? null : $selfRevivalsReceived,
                'revivals_performed' => $healingIncomplete ? null : $selfRevivalsPerformed,
                'awakened_at_start' => $awakenedAtStart,
                'awakened_in_battle' => ! $awakenedAtStart
                    && (($leaderAwakening['triggered'] ?? false) === true || $selfAwakeningRound !== null),
                'awakening_round' => $selfAwakeningRound,
                'awakening_technique_uses' => $selfAwakeningTechniques,
                'awakening_triggered' => ($leaderAwakening['triggered'] ?? false) === true,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function isSelfActor(array $row, string $leaderCombatantId, bool $partyMode): bool
    {
        return is_string($row['actor_id'] ?? null)
            ? $row['actor_id'] === $leaderCombatantId
            : ! $partyMode && ($row['side'] ?? null) === 'player';
    }

    /** @param array<string, mixed> $row */
    private function isSelfTarget(array $row, string $leaderCombatantId, bool $partyMode): bool
    {
        return is_string($row['target_id'] ?? null)
            ? $row['target_id'] === $leaderCombatantId
            : ! $partyMode && ($row['target_side'] ?? null) === 'player';
    }

    /** @param array<string, mixed> $row */
    private function damageSource(array $row): ?string
    {
        $source = $row['damage_source'] ?? null;

        return in_array($source, ['direct', 'periodic', 'counter'], true) ? $source : null;
    }

    /** @param array<string, mixed> $row */
    private function healingSource(array $row): ?string
    {
        $source = $row['effect_source'] ?? null;

        return in_array($source, ['direct', 'periodic', 'lifesteal', 'regeneration', 'revival', 'awakening'], true)
            ? $source
            : null;
    }

    /** @param array<string, mixed> $row */
    private function damageIssue(array $row, ?string $source, bool $partyMode): ?string
    {
        if ($source === null) {
            return 'unclassified_damage_source';
        }
        foreach (['effective_damage', 'hp_damage', 'prevented_damage', 'target_hp_after'] as $key) {
            if (! is_numeric($row[$key] ?? null)) {
                return 'damage_missing_'.$key;
            }
        }
        if (! is_bool($row['defeated'] ?? null)) {
            return 'damage_missing_defeated';
        }
        if (! in_array($this->statisticsActorSide($row, $source), ['player', 'enemy'], true)) {
            return 'damage_missing_actor_side';
        }
        if (! in_array($this->statisticsTargetSide($row, $source), ['player', 'enemy'], true)) {
            return 'damage_missing_target_side';
        }
        if ($source === 'periodic'
            && (! is_string($row['source_combatant_id'] ?? null)
                || ! is_string($row['source_side'] ?? null)
                || ! is_string($row['periodic_target_combatant_id'] ?? null)
                || ! is_string($row['periodic_target_side'] ?? null)
                || ! is_string($row['periodic_status_key'] ?? null))) {
            return 'periodic_damage_missing_source_metadata';
        }
        if (($row['complete_guarded'] ?? false) === true
            && ! is_numeric($row['complete_guard_prevented_damage'] ?? null)) {
            return 'complete_guard_missing_prevented_amount';
        }
        if ($partyMode
            && (! is_string($this->statisticsActorId($row, $source))
                || ! is_string($this->statisticsTargetId($row, $source)))) {
            return 'party_damage_missing_actor_or_target';
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function healingIssue(array $row, ?string $source, bool $partyMode): ?string
    {
        if ($source === null) {
            return 'unclassified_healing_source';
        }
        if (! is_numeric($row['amount'] ?? null)) {
            return 'healing_missing_amount';
        }
        if (! in_array($this->statisticsActorSide($row, $source), ['player', 'enemy'], true)) {
            return 'healing_missing_actor_side';
        }
        if (! in_array($this->statisticsTargetSide($row, $source), ['player', 'enemy'], true)) {
            return 'healing_missing_target_side';
        }
        if ($source === 'periodic'
            && (! is_string($row['source_combatant_id'] ?? null)
                || ! is_string($row['source_side'] ?? null)
                || ! is_string($row['periodic_target_combatant_id'] ?? null)
                || ! is_string($row['periodic_target_side'] ?? null)
                || ! is_string($row['periodic_status_key'] ?? null))) {
            return 'periodic_healing_missing_source_metadata';
        }
        if ($partyMode
            && (! is_string($this->statisticsActorId($row, $source))
                || ! is_string($this->statisticsTargetId($row, $source)))) {
            return 'party_healing_missing_actor_or_target';
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function statisticsActorId(array $row, ?string $source): ?string
    {
        $value = $source === 'periodic' ? ($row['source_combatant_id'] ?? null) : ($row['actor_id'] ?? null);

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $row */
    private function statisticsActorSide(array $row, ?string $source): ?string
    {
        $value = $source === 'periodic'
            ? ($row['source_side'] ?? null)
            : ($row['team'] ?? $row['side'] ?? null);

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $row */
    private function statisticsTargetId(array $row, ?string $source): ?string
    {
        $value = $source === 'periodic'
            ? ($row['periodic_target_combatant_id'] ?? null)
            : ($row['target_id'] ?? null);
        if (! is_string($value) && in_array($source, ['lifesteal', 'regeneration'], true)) {
            $value = $row['actor_id'] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $row */
    private function statisticsTargetSide(array $row, ?string $source): ?string
    {
        $value = $source === 'periodic'
            ? ($row['periodic_target_side'] ?? null)
            : ($row['target_side'] ?? null);
        if (! is_string($value) && in_array($source, ['lifesteal', 'regeneration'], true)) {
            $value = $row['team'] ?? $row['side'] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    private function identityIsSelf(
        ?string $combatantId,
        ?string $side,
        string $leaderCombatantId,
        bool $partyMode,
    ): bool {
        return $combatantId !== null
            ? $combatantId === $leaderCombatantId
            : ! $partyMode && $side === 'player';
    }

    /** @param array<string, mixed> $metrics */
    private function metric(array $metrics, string $key): ?int
    {
        return is_numeric($metrics[$key] ?? null) && (int) $metrics[$key] >= 0
            ? (int) $metrics[$key]
            : null;
    }
}
