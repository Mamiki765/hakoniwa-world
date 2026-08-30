<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

/**
 * Versioned alpha-v1 build/status model executed by the canonical round envelope.
 */
final readonly class AlphaV1CombatModel
{
    public function __construct(
        private AlphaV1CombatRules $rules,
        private UndergroundBuildValidator $validator,
        private DeterministicEquipmentGenerator $equipmentGenerator,
        private PriorityCombatAi $ai,
        private CanonicalCombatOrchestrator $orchestrator,
    ) {}

    /**
     * @param  array<string, mixed>  $equipmentOverrides
     */
    public function fight(
        AlphaV1BuildCatalog $catalog,
        string $buildKey,
        string $enemyKey,
        string $tierKey,
        int $seed,
        int $maxRounds,
        ?int $naturalRecoveryOverride = null,
        array $equipmentOverrides = [],
    ): BuildCombatResult {
        if ($seed < 0 || $seed > 2_147_483_647 || $maxRounds < 1 || $maxRounds > 200) {
            throw new InvalidArgumentException('Underground alpha-v1 fight input is invalid.');
        }
        $build = $this->validator->validate($catalog, $buildKey);
        $tier = $catalog->tier($tierKey);
        $combatLevel = $tier['combat_level'] ?? null;
        $itemLevel = $tier['item_level'] ?? null;
        if (! is_int($combatLevel) || ! is_int($itemLevel)) {
            throw new InvalidArgumentException("Underground alpha-v1 tier [{$tierKey}] is invalid.");
        }
        $scaleBps = $this->rules->progressionScaleBps($combatLevel, $itemLevel);
        $equipment = [];
        foreach ($build['equipment'] as $request) {
            $equipmentItemLevel = $itemLevel;
            $slot = $request['slot'] ?? null;
            if (is_string($slot) && array_key_exists($slot, $equipmentOverrides)) {
                $override = $equipmentOverrides[$slot];
                if (! is_array($override)
                    || ! is_int($override['item_level'] ?? null)
                    || ! is_array($override['request'] ?? null)
                    || ($override['request']['slot'] ?? null) !== $slot) {
                    throw new InvalidArgumentException("Underground alpha-v1 equipment override [{$slot}] is invalid.");
                }
                $equipmentItemLevel = $override['item_level'];
                $request = $override['request'];
            }
            $equipment[] = $this->equipmentGenerator->generate($catalog, $equipmentItemLevel, $request);
        }
        $equipmentAggregate = $this->equipmentGenerator->aggregate($equipment);
        $player = $this->playerState($catalog, $build, $equipmentAggregate, $scaleBps);
        $enemy = $this->enemyState($catalog, $enemyKey, $scaleBps);
        $random = new UndergroundRandom($seed);
        $naturalRecovery = $naturalRecoveryOverride ?? $catalog->balanceInt('mp_natural_recovery');
        if ($naturalRecovery < 0 || $naturalRecovery > AlphaV1CombatRules::MAX_MP) {
            throw new InvalidArgumentException('Underground alpha-v1 natural MP recovery is invalid.');
        }
        $metrics = [
            'damage_dealt' => 0,
            'damage_received' => 0,
            'effective_healing' => 0,
            'damage_prevented' => 0,
            'mp_spent' => 0,
            'mp_natural_recovery' => 0,
            'mp_skill_recovery' => 0,
            'mp_overflow' => 0,
            'mp_exhaustion_round' => null,
            'skill_unavailable_due_to_mp' => 0,
        ];
        $actionUsage = ['normal_attack' => 0, 'defend' => 0, 'ai_fallback' => 0, 'action_skipped' => 0, 'counter' => 0];
        foreach ($player->skills as $skillKey) {
            $actionUsage[$skillKey] = 0;
        }
        $statusUptime = [];
        $mpHistory = [];
        $actionLog = [];

        $completedRounds = $this->orchestrator->run(
            $maxRounds,
            static fn (): bool => $player->alive() && $enemy->alive(),
            function (int $round) use (
                $player,
                $enemy,
                $naturalRecovery,
                &$metrics,
                &$mpHistory,
            ): void {
                $player->tickCooldowns();
                $enemy->tickCooldowns();
                $this->changeMp($player, $naturalRecovery, 0, $round, 'natural', $metrics, $mpHistory);
                $this->changeMp($enemy, $naturalRecovery, 0, $round, 'natural', $metrics, $mpHistory, false);
            },
            fn (int $round): array => $this->turnOrder($player, $enemy, $random, $round),
            function (string $side, int $round) use (
                $catalog,
                $player,
                $enemy,
                $random,
                &$metrics,
                &$actionUsage,
                &$mpHistory,
                &$actionLog,
            ): void {
                $actor = $side === 'player' ? $player : $enemy;
                $target = $side === 'player' ? $enemy : $player;
                $this->executeTurn(
                    $catalog,
                    $actor,
                    $target,
                    $random,
                    $round,
                    $metrics,
                    $actionUsage,
                    $mpHistory,
                    $actionLog,
                );
            },
            function (int $round) use (
                $catalog,
                $player,
                $enemy,
                &$metrics,
                &$statusUptime,
                &$actionLog,
            ): void {
                $this->processRoundEnd($catalog, $player, $enemy, $round, $metrics, $statusUptime, $actionLog);
                $this->processRoundEnd($catalog, $enemy, $player, $round, $metrics, $statusUptime, $actionLog);
                $actionLog[] = [
                    'kind' => 'round_end',
                    'round' => $round,
                    'side' => 'system',
                    'action' => 'round_end',
                    'player' => $this->stateSnapshot($player),
                    'enemy' => $this->stateSnapshot($enemy),
                ];
            },
        );

        $winner = ! $player->alive() ? 'enemy' : (! $enemy->alive() ? 'player' : 'stalemate');
        $abnormal = $this->abnormalState($player, $enemy);

        return new BuildCombatResult(
            AlphaV1CombatRules::IDENTITY,
            AlphaV1CombatRules::GENERATOR_IDENTITY,
            $seed,
            $buildKey,
            $enemyKey,
            $tierKey,
            $winner,
            $completedRounds,
            $player->hp,
            $enemy->hp,
            $metrics['damage_dealt'],
            $metrics['damage_received'],
            $metrics['effective_healing'],
            $metrics['damage_prevented'],
            $metrics['mp_spent'],
            $metrics['mp_natural_recovery'],
            $metrics['mp_skill_recovery'],
            $metrics['mp_overflow'],
            $metrics['mp_exhaustion_round'],
            $metrics['skill_unavailable_due_to_mp'],
            $player->mp,
            $actionUsage,
            $statusUptime,
            $player->roleStacks,
            $mpHistory,
            $abnormal,
            $actionLog,
            $equipment,
        );
    }

    /**
     * @param  array<string, mixed>  $build
     * @param  array{stats: array<string, int>, weapon_power: int, physical_defense: int, magical_defense: int, max_hp: int, modifiers: array<string, int|bool|string>, unique_effects: list<string>}  $equipment
     */
    private function playerState(
        AlphaV1BuildCatalog $catalog,
        array $build,
        array $equipment,
        int $scaleBps,
    ): BuildCombatState {
        $stats = $this->rules->scaledStats($build['base_stats'], $equipment['stats'], $scaleBps);
        $modifiers = $this->passiveModifiers($catalog, $build['passive_nodes']);
        foreach ($equipment['modifiers'] as $key => $value) {
            $modifiers[$key] = (int) ($modifiers[$key] ?? 0) + (int) $value;
        }
        foreach ($equipment['unique_effects'] as $effect) {
            match ($effect) {
                'sanguine_edge' => $modifiers = $this->addModifiers($modifiers, [
                    'all_damage_bps' => -500,
                    'lifesteal_bps' => 600,
                ]),
                'heavy_retort' => $modifiers = $this->addModifiers($modifiers, [
                    'agility_bps' => -1_500,
                    'counter_power_bps' => 2_500,
                ]),
                'afterguard_focus' => $modifiers['afterguard_focus'] = true,
                'graceful_focus' => $modifiers['graceful_focus'] = true,
                default => null,
            };
        }
        $physicalDefense = $equipment['physical_defense'] + ($stats['vitality'] * 4);
        $magicalDefense = $equipment['magical_defense'] + ($stats['spirit'] * 4);

        return new BuildCombatState(
            'player',
            $build['key'],
            $build['label'],
            false,
            $this->rules->maxHp($stats, $scaleBps, $equipment['max_hp']),
            $stats,
            $physicalDefense,
            $magicalDefense,
            $this->rules->defenseReference($scaleBps),
            max(1, $equipment['weapon_power']),
            $build['active_skills'],
            $build['ai_rules'],
            $modifiers,
            $catalog->manifest()['normal_attack'],
        );
    }

    private function enemyState(
        AlphaV1BuildCatalog $catalog,
        string $enemyKey,
        int $scaleBps,
    ): BuildCombatState {
        $enemy = $catalog->enemy($enemyKey);
        $stats = $enemy['base_stats'] ?? null;
        if (! is_array($stats)) {
            throw new InvalidArgumentException("Underground alpha-v1 enemy [{$enemyKey}] stats are invalid.");
        }
        $stats = $this->rules->scaledStats($stats, [], $scaleBps);
        foreach (['label', 'max_hp', 'physical_defense', 'magical_defense', 'weapon_power', 'skills', 'ai_rules', 'normal_attack'] as $key) {
            if (! array_key_exists($key, $enemy)) {
                throw new InvalidArgumentException("Underground alpha-v1 enemy [{$enemyKey}] is invalid.");
            }
        }

        return new BuildCombatState(
            'enemy',
            $enemyKey,
            (string) $enemy['label'],
            ($enemy['boss'] ?? false) === true,
            max(1, intdiv((int) $enemy['max_hp'] * $scaleBps, 10_000)),
            $stats,
            max(0, intdiv((int) $enemy['physical_defense'] * $scaleBps, 10_000)),
            max(0, intdiv((int) $enemy['magical_defense'] * $scaleBps, 10_000)),
            $this->rules->defenseReference($scaleBps),
            max(1, intdiv((int) $enemy['weapon_power'] * $scaleBps, 10_000)),
            $this->stringList($enemy['skills']),
            is_array($enemy['ai_rules']) ? $enemy['ai_rules'] : [],
            is_array($enemy['modifiers'] ?? null) ? $enemy['modifiers'] : [],
            is_array($enemy['normal_attack']) ? $enemy['normal_attack'] : [],
        );
    }

    /** @return list<'player'|'enemy'> */
    private function turnOrder(
        BuildCombatState $player,
        BuildCombatState $enemy,
        UndergroundRandom $random,
        int $round,
    ): array {
        $playerInitiative = $this->initiative($player);
        $enemyInitiative = $this->initiative($enemy);
        if ($playerInitiative > $enemyInitiative) {
            return ['player', 'enemy'];
        }
        if ($enemyInitiative > $playerInitiative) {
            return ['enemy', 'player'];
        }

        return $random->integer("alpha-v1:initiative:round:{$round}", 0, 1) === 0
            ? ['player', 'enemy']
            : ['enemy', 'player'];
    }

    private function initiative(BuildCombatState $state): int
    {
        $agilityBps = (int) ($state->modifiers['agility_bps'] ?? 0);
        $value = $state->stat('agility') + intdiv($state->stat('agility') * $agilityBps, 10_000);
        foreach ($state->statuses as $status) {
            foreach ($status['effects'] as $effect) {
                if (($effect['type'] ?? null) === 'initiative_modifier') {
                    $value += intdiv($value * ((int) ($effect['value_bps'] ?? 0)) * $status['stacks'], 10_000);
                }
            }
        }

        return max(0, $value);
    }

    /**
     * @param  array<string, int|null>  $metrics
     * @param  array<string, int>  $actionUsage
     * @param  list<array<string, int>>  $mpHistory
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function executeTurn(
        AlphaV1BuildCatalog $catalog,
        BuildCombatState $actor,
        BuildCombatState $target,
        UndergroundRandom $random,
        int $round,
        array &$metrics,
        array &$actionUsage,
        array &$mpHistory,
        array &$actionLog,
    ): void {
        if ($this->actionImpaired($actor, $random, $round)) {
            if ($actor->side === 'player') {
                $actionUsage['action_skipped']++;
            }
            $actionLog[] = $this->logRow($round, $actor, 'action_impaired', 0, false, false);

            return;
        }

        $action = $this->ai->select($actor, $target, $catalog, $round);
        $actionLog[] = [
            'kind' => 'decision',
            'round' => $round,
            'side' => $actor->side,
            'action' => 'decision',
            'action_key' => $action['type'] === 'skill' ? $action['key'] : $action['type'],
            'reason' => $action['reason'],
            'fallback' => $action['fallback'],
            'mp_blocked' => $action['mp_blocked'],
        ];
        if ($actor->side === 'player' && $action['mp_blocked']) {
            $metrics['skill_unavailable_due_to_mp']++;
        }
        if ($action['fallback'] && $actor->side === 'player') {
            $actionUsage['ai_fallback']++;
        }
        if ($action['type'] === 'defend') {
            $actor->guarding = true;
            if ($actor->side === 'player') {
                $actionUsage['defend']++;
            }
            $actionLog[] = $this->logRow($round, $actor, 'defend', 0, false, false, effectType: 'guard');

            return;
        }
        if ($action['type'] === 'normal_attack') {
            if ($actor->side === 'player') {
                $actionUsage['normal_attack']++;
            }
            $this->applyDamage(
                $actor,
                $target,
                $actor->normalAttack,
                $random,
                $round,
                'normal_attack',
                $metrics,
                $actionUsage,
                $actionLog,
            );

            return;
        }

        $skillKey = $action['key'];
        if (! is_string($skillKey) || ! $this->ai->skillAvailable($actor, $catalog, $skillKey)) {
            throw new InvalidArgumentException('Underground alpha-v1 AI selected an unavailable skill.');
        }
        $skill = $catalog->skill($skillKey);
        $cost = $this->ai->effectiveCost($actor, (int) $skill['mp_cost']);
        $this->changeMp($actor, 0, $cost, $round, 'skill_cost', $metrics, $mpHistory, $actor->side === 'player');
        $actor->cooldowns[$skillKey] = (int) $skill['cooldown'];
        if ($actor->side === 'player') {
            $actionUsage[$skillKey] = ($actionUsage[$skillKey] ?? 0) + 1;
        }
        $consumeStatus = $skill['consume_status'] ?? null;
        if (is_string($consumeStatus)) {
            unset($actor->statuses[$consumeStatus]);
        }
        foreach ($skill['effects'] as $effect) {
            if (! $actor->alive() || ! $target->alive()) {
                break;
            }
            $this->applySkillEffect(
                $catalog,
                $actor,
                $target,
                $effect,
                $random,
                $round,
                $skillKey,
                $metrics,
                $actionUsage,
                $mpHistory,
                $actionLog,
            );
        }
        if (($skill['grace_on_use'] ?? false) === true && ($actor->modifiers['grace_enabled'] ?? false) === true) {
            $this->grantRoleStack($actor, 'grace', $catalog->balanceInt('role_stack_cap'));
        }
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  array<string, int|null>  $metrics
     * @param  array<string, int>  $actionUsage
     * @param  list<array<string, int>>  $mpHistory
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function applySkillEffect(
        AlphaV1BuildCatalog $catalog,
        BuildCombatState $actor,
        BuildCombatState $target,
        array $effect,
        UndergroundRandom $random,
        int $round,
        string $skillKey,
        array &$metrics,
        array &$actionUsage,
        array &$mpHistory,
        array &$actionLog,
    ): void {
        $effectTarget = ($effect['target'] ?? 'enemy') === 'self' ? $actor : $target;
        match ($effect['type'] ?? null) {
            'damage' => $this->applyDamage(
                $actor,
                $effectTarget,
                $effect,
                $random,
                $round,
                $skillKey,
                $metrics,
                $actionUsage,
                $actionLog,
            ),
            'heal' => $this->applyHeal($actor, $effectTarget, $effect, $round, $skillKey, $metrics, $actionLog),
            'barrier' => $this->applyBarrier($actor, $effectTarget, $effect, $round, $skillKey, $actionLog),
            'apply_status' => $this->applyStatus(
                $catalog,
                $actor,
                $effectTarget,
                (string) ($effect['status'] ?? ''),
                $random,
                $round,
                $skillKey,
                $actionLog,
            ),
            'cleanse' => $this->removeStatuses($actor, $effectTarget, 'debuff', $effect, $round, $skillKey, $actionLog),
            'dispel' => $this->removeStatuses($actor, $effectTarget, 'buff', $effect, $round, $skillKey, $actionLog),
            'mp_restore' => $this->changeMp(
                $effectTarget,
                max(0, (int) ($effect['amount'] ?? 0)),
                0,
                $round,
                'skill_recovery',
                $metrics,
                $mpHistory,
                $effectTarget->side === 'player',
            ),
            'telegraph' => $this->applyStatus(
                $catalog,
                $actor,
                $actor,
                'telegraph',
                $random,
                $round,
                $skillKey,
                $actionLog,
                true,
            ),
            default => throw new InvalidArgumentException("Underground alpha-v1 skill [{$skillKey}] effect is unsupported."),
        };
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  array<string, int|null>  $metrics
     * @param  array<string, int>  $actionUsage
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function applyDamage(
        BuildCombatState $actor,
        BuildCombatState $target,
        array $effect,
        UndergroundRandom $random,
        int $round,
        string $actionKey,
        array &$metrics,
        array &$actionUsage,
        array &$actionLog,
    ): void {
        $hits = max(1, (int) ($effect['hits'] ?? 1));
        for ($hit = 1; $hit <= $hits && $actor->alive() && $target->alive(); $hit++) {
            $coefficients = is_array($effect['stat_coefficients'] ?? null) ? $effect['stat_coefficients'] : [];
            $effectivePower = $this->rules->weightedStats($this->currentStats($actor), $coefficients);
            $effectivePower += intdiv($actor->weaponPower * (int) ($effect['weapon_coefficient_bps'] ?? 0), 10_000);
            $effectivePower += max(0, (int) ($effect['fixed'] ?? 0));
            $rawDamage = max(1, intdiv($effectivePower * (int) ($effect['potency_bps'] ?? 10_000), 10_000));
            $targetMaxHpBps = max(0, (int) ($effect['target_max_hp_bps'] ?? 0));
            if ($targetMaxHpBps > 0) {
                $percentageComponent = intdiv($target->maxHp * $targetMaxHpBps, 10_000);
                $sourceCap = $this->rules->weightedStats(
                    $this->currentStats($actor),
                    is_array($effect['source_cap_coefficients'] ?? null) ? $effect['source_cap_coefficients'] : [],
                );
                $sourceCap = intdiv($sourceCap * (int) ($effect['source_cap_multiplier_bps'] ?? 10_000), 10_000);
                $rawDamage += min($percentageComponent, max(0, $sourceCap));
            }

            $category = (string) ($effect['category'] ?? 'physical');
            $damageBps = 10_000 + (int) ($actor->modifiers['all_damage_bps'] ?? 0)
                + (int) ($actor->modifiers[$category.'_damage_bps'] ?? 0)
                + $this->statusModifier($actor, 'damage_dealt_modifier', $category);
            $consumeStack = $effect['consume_stack'] ?? null;
            if (is_array($consumeStack) && is_string($consumeStack['key'] ?? null)) {
                $stackKey = $consumeStack['key'];
                $consumed = min(
                    $actor->roleStacks[$stackKey] ?? 0,
                    max(0, (int) ($consumeStack['maximum'] ?? 0)),
                );
                $damageBps += $consumed * (int) ($consumeStack['bonus_per_stack_bps'] ?? 0);
                $actor->roleStacks[$stackKey] = ($actor->roleStacks[$stackKey] ?? 0) - $consumed;
            }
            if ($category === 'miracle') {
                $period = (int) ($actor->modifiers['apotheosis_period_rounds'] ?? 0);
                if ($period > 0 && $round % $period === 0) {
                    $damageBps += (int) ($actor->modifiers['apotheosis_bonus_bps'] ?? 0);
                }
                if (($actor->flags['graceful_focus'] ?? false) === true) {
                    $damageBps += 2_500;
                    $actor->flags['graceful_focus'] = false;
                }
            }
            if (($actor->flags['afterguard_focus'] ?? false) === true && $actionKey !== 'normal_attack') {
                $damageBps += 2_000;
                $actor->flags['afterguard_focus'] = false;
            }
            $rawDamage = max(1, intdiv($rawDamage * max(1, $damageBps), 10_000));

            $critical = false;
            if (($effect['can_crit'] ?? false) === true) {
                $criticalChance = min(
                    AlphaV1CombatRules::CRITICAL_CHANCE_CAP_BPS,
                    max(0, $this->scaledProbabilityContribution($actor, 'finesse', 2_000)
                        + (int) ($actor->modifiers['critical_chance_bps'] ?? 0)),
                );
                $critical = $random->integer("alpha-v1:critical:{$actor->key}:{$actionKey}:{$hit}", 1, 10_000)
                    <= $criticalChance;
                if ($critical) {
                    $rawDamage = intdiv(
                        $rawDamage * (15_000 + (int) ($actor->modifiers['critical_damage_bps'] ?? 0)),
                        10_000,
                    );
                }
            }
            $variance = $random->integer("alpha-v1:variance:{$actor->key}:{$actionKey}:{$hit}", 95, 105);
            $preMitigation = max(1, intdiv($rawDamage * $variance, 100));
            $evasion = min(
                AlphaV1CombatRules::EVASION_CAP_BPS,
                max(0, $this->scaledProbabilityContribution($target, 'agility', 1_200)
                    + (int) ($target->modifiers['evasion_bps'] ?? 0)),
            );
            if (($effect['dodgeable'] ?? true) === true
                && $random->integer("alpha-v1:evasion:{$target->key}:{$actionKey}:{$hit}", 1, 10_000) <= $evasion) {
                if ($target->side === 'player') {
                    $metrics['damage_prevented'] += $preMitigation;
                }
                $actionLog[] = $this->logRow($round, $actor, $actionKey, 0, $critical, true, effectType: 'damage');

                continue;
            }

            $guarded = $target->guarding;
            $parried = false;
            $guardBps = 10_000;
            if ($guarded) {
                $guardReduction = min(6_000, 3_500 + max(0, (int) ($target->modifiers['guard_reduction_bps'] ?? 0)));
                $guardBps -= $guardReduction;
                $parryChance = max(0, (int) ($target->modifiers['parry_bps'] ?? 0));
                $parried = $parryChance > 0
                    && $random->integer("alpha-v1:parry:{$target->key}:{$actionKey}:{$hit}", 1, 10_000)
                        <= min(5_000, $parryChance);
                if ($parried) {
                    $guardBps = intdiv($guardBps * 6_000, 10_000);
                }
                $target->guarding = false;
            }
            $combinedBps = $this->targetDamageBps($target, $category);
            $combinedBps = intdiv($combinedBps * $guardBps, 10_000);
            $combinedBps = max(10_000 - AlphaV1CombatRules::DAMAGE_REDUCTION_CAP_BPS, min(20_000, $combinedBps));
            $postMitigation = max(1, intdiv($preMitigation * $combinedBps, 10_000));
            $settled = $this->settlePostMitigationDamage(
                $target,
                $postMitigation,
                $actor->side === 'player',
                $metrics,
            );
            $hpDamage = $settled['hp_damage'];
            $barrierAbsorbed = $settled['barrier_absorbed'];
            if ($target->side === 'player') {
                $metrics['damage_prevented'] += max(0, $preMitigation - $postMitigation);
            }
            if ($guarded || $barrierAbsorbed > 0) {
                if (($target->modifiers['fighting_spirit_enabled'] ?? false) === true) {
                    $this->grantRoleStack($target, 'fighting_spirit', 5);
                }
                if ($barrierAbsorbed > 0 && ($target->modifiers['grace_enabled'] ?? false) === true) {
                    $this->grantRoleStack($target, 'grace', 5);
                }
                if (($target->modifiers['afterguard_focus'] ?? false) === true) {
                    $target->flags['afterguard_focus'] = true;
                }
                $this->counter($target, $actor, $round, $metrics, $actionUsage, $actionLog);
            }
            if ($actor->side === 'player' && $hpDamage > 0) {
                $lifestealBps = max(0, (int) ($actor->modifiers['lifesteal_bps'] ?? 0));
                if ($lifestealBps > 0) {
                    $this->healExact($actor, intdiv($hpDamage * $lifestealBps, 10_000), $metrics);
                }
            }
            $actionLog[] = $this->logRow($round, $actor, $actionKey, $hpDamage, $critical, false, $guarded, $parried, $barrierAbsorbed, 'damage');
        }
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  array<string, int|null>  $metrics
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function applyHeal(
        BuildCombatState $source,
        BuildCombatState $target,
        array $effect,
        int $round,
        string $actionKey,
        array &$metrics,
        array &$actionLog,
    ): void {
        $amount = $this->recoveryAmount($source, $target, $effect);
        $amount = intdiv($amount * (10_000 + (int) ($source->modifiers['healing_bps'] ?? 0)), 10_000);
        $effective = $this->healExact($target, $amount, $metrics);
        if ($effective > 0 && $source->side === 'player' && ($source->modifiers['grace_enabled'] ?? false) === true) {
            $this->grantRoleStack($source, 'grace', 5);
            if (($source->modifiers['graceful_focus'] ?? false) === true) {
                $source->flags['graceful_focus'] = true;
            }
        }
        $actionLog[] = $this->logRow($round, $source, $actionKey, -$effective, false, false, effectType: 'recovery');
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function applyBarrier(
        BuildCombatState $source,
        BuildCombatState $target,
        array $effect,
        int $round,
        string $actionKey,
        array &$actionLog,
    ): void {
        $amount = $this->recoveryAmount($source, $target, $effect);
        $amount = intdiv($amount * (10_000 + (int) ($source->modifiers['barrier_bps'] ?? 0)), 10_000);
        $cap = intdiv($target->maxHp * 3_500, 10_000);
        $before = $target->barrier;
        $target->barrier = min($cap, $target->barrier + max(0, $amount));
        $actionLog[] = $this->logRow($round, $source, $actionKey, -($target->barrier - $before), false, false, effectType: 'barrier');
    }

    /** @param array<string, mixed> $effect */
    private function recoveryAmount(BuildCombatState $source, BuildCombatState $target, array $effect): int
    {
        $coefficients = is_array($effect['source_stat_coefficients'] ?? null)
            ? $effect['source_stat_coefficients']
            : [];
        $amount = $this->rules->weightedStats($this->currentStats($source), $coefficients);
        $amount += intdiv($target->maxHp * max(0, (int) ($effect['target_max_hp_bps'] ?? 0)), 10_000);
        $amount += max(0, (int) ($effect['fixed'] ?? 0));

        return max(0, $amount);
    }

    /**
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function applyStatus(
        AlphaV1BuildCatalog $catalog,
        BuildCombatState $source,
        BuildCombatState $target,
        string $statusKey,
        UndergroundRandom $random,
        int $round,
        string $actionKey,
        array &$actionLog,
        bool $force = false,
    ): void {
        $definition = $catalog->status($statusKey);
        $chance = min(10_000, max(0,
            (int) ($definition['application_chance_bps'] ?? 10_000)
            + (int) ($source->modifiers['status_potency_bps'] ?? 0),
        ));
        if (! $force && $random->integer("alpha-v1:status:{$source->key}:{$actionKey}:{$statusKey}", 1, 10_000) > $chance) {
            $actionLog[] = $this->logRow($round, $source, 'status_resisted:'.$statusKey, 0, false, false, effectType: 'status_resisted');

            return;
        }
        $effects = $definition['effects'];
        $control = $this->containsEffect($effects, 'action_impairment');
        $bossConverted = false;
        $bossFactorBps = 10_000;
        if ($target->boss && $control) {
            $bossEffects = $definition['boss_profile']['effects'] ?? null;
            if (! is_array($bossEffects) || ! array_is_list($bossEffects)) {
                throw new InvalidArgumentException("Underground boss status profile [{$statusKey}] is invalid.");
            }
            $bossFactorBps = [10_000, 7_500, 6_000, 5_000][min(3, $target->controlResistance)];
            $effects = array_map(static function (array $effect) use ($bossFactorBps): array {
                if (is_int($effect['value_bps'] ?? null)) {
                    $effect['value_bps'] = intdiv($effect['value_bps'] * $bossFactorBps, 10_000);
                }

                return $effect;
            }, $bossEffects);
            $target->controlResistance = min(3, $target->controlResistance + 1);
            $target->controlResistanceRounds = 2;
            $bossConverted = true;
            $control = false;
        }
        $effects = $this->snapshotPeriodicEffects($source, $target, $effects);
        foreach ($effects as $effect) {
            if (($effect['type'] ?? null) === 'barrier') {
                $this->applyBarrier($source, $target, $effect, $round, 'status:'.$statusKey, $actionLog);
            }
        }
        $existing = $target->statuses[$statusKey] ?? null;
        $stacks = 1;
        if (is_array($existing) && ($definition['stack_policy'] ?? null) === 'stack_refresh') {
            $stacks = min((int) $definition['max_stacks'], $existing['stacks'] + 1);
        }
        $target->statuses[$statusKey] = [
            'key' => $statusKey,
            'disposition' => $definition['disposition'],
            'remaining' => (int) $definition['duration_rounds'],
            'applied_round' => $round,
            'stacks' => $stacks,
            'effects' => $effects,
            'control' => $control,
        ];
        $actionLog[] = $this->logRow(
            $round,
            $source,
            ($bossConverted ? 'boss_status:' : 'status:').$statusKey,
            $bossConverted ? $bossFactorBps : $stacks,
            false,
            false,
            effectType: 'status_applied',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $effects
     * @return list<array<string, mixed>>
     */
    private function snapshotPeriodicEffects(
        BuildCombatState $source,
        BuildCombatState $target,
        array $effects,
    ): array {
        $periodicMultiplierBps = max(0, 10_000 + (int) ($source->modifiers['periodic_bps'] ?? 0));
        foreach ($effects as &$effect) {
            if (($effect['type'] ?? null) === 'periodic_damage') {
                $percentage = intdiv($target->maxHp * max(0, (int) ($effect['target_max_hp_bps'] ?? 0)), 10_000);
                $cap = $this->rules->weightedStats(
                    $this->currentStats($source),
                    is_array($effect['source_stat_coefficients'] ?? null) ? $effect['source_stat_coefficients'] : [],
                );
                $cap = intdiv($cap * (int) ($effect['source_cap_multiplier_bps'] ?? 10_000), 10_000);
                $effect['tick_value'] = max(1, min($percentage, max(1, $cap)));
                $effect['periodic_multiplier_bps'] = $periodicMultiplierBps;
                $effect['source_side'] = $source->side;
            } elseif (($effect['type'] ?? null) === 'periodic_heal') {
                $effect['tick_value'] = max(1,
                    $this->rules->weightedStats(
                        $this->currentStats($source),
                        is_array($effect['source_stat_coefficients'] ?? null) ? $effect['source_stat_coefficients'] : [],
                    ) + intdiv($target->maxHp * max(0, (int) ($effect['target_max_hp_bps'] ?? 0)), 10_000),
                );
                $effect['periodic_multiplier_bps'] = $periodicMultiplierBps;
                $effect['source_side'] = $source->side;
            }
        }
        unset($effect);

        return $effects;
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function removeStatuses(
        BuildCombatState $source,
        BuildCombatState $target,
        string $disposition,
        array $effect,
        int $round,
        string $actionKey,
        array &$actionLog,
    ): void {
        $maximum = max(1, (int) ($effect['maximum'] ?? 1));
        $keys = array_keys(array_filter(
            $target->statuses,
            static fn (array $status): bool => $status['disposition'] === $disposition,
        ));
        sort($keys, SORT_STRING);
        $removed = 0;
        foreach (array_slice($keys, 0, $maximum) as $key) {
            unset($target->statuses[$key]);
            $removed++;
        }
        if ($removed > 0 && $disposition === 'debuff' && $source->side === 'player'
            && ($source->modifiers['grace_enabled'] ?? false) === true) {
            $this->grantRoleStack($source, 'grace', 5);
        }
        $actionLog[] = $this->logRow($round, $source, $actionKey, -$removed, false, false, effectType: 'status_removed');
    }

    /**
     * @param  array<string, int|null>  $metrics
     * @param  array<string, int>  $statusUptime
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function processRoundEnd(
        AlphaV1BuildCatalog $catalog,
        BuildCombatState $state,
        BuildCombatState $opponent,
        int $round,
        array &$metrics,
        array &$statusUptime,
        array &$actionLog,
    ): void {
        foreach ($state->statuses as $key => &$status) {
            $statusUptime[$state->side.':'.$key] = ($statusUptime[$state->side.':'.$key] ?? 0) + 1;
            if ($status['applied_round'] >= $round) {
                continue;
            }
            foreach ($status['effects'] as $effect) {
                $ticks = max(1, $status['stacks']);
                if (($effect['type'] ?? null) === 'periodic_damage' && $state->alive()) {
                    $amount = max(1, intdiv(
                        max(1, (int) ($effect['tick_value'] ?? 1))
                            * $ticks
                            * max(0, (int) ($effect['periodic_multiplier_bps'] ?? 10_000))
                            + 5_000,
                        10_000,
                    ));
                    $settled = $this->settlePostMitigationDamage(
                        $state,
                        $amount,
                        ($effect['source_side'] ?? null) === 'player',
                        $metrics,
                    );
                    $actionLog[] = $this->logRow(
                        $round,
                        $state,
                        'periodic_damage:'.$key,
                        $settled['hp_damage'],
                        false,
                        false,
                        barrierAbsorbed: $settled['barrier_absorbed'],
                        effectType: 'damage',
                    );
                } elseif (($effect['type'] ?? null) === 'periodic_heal' && $state->alive()) {
                    $amount = max(1, intdiv(
                        max(1, (int) ($effect['tick_value'] ?? 1))
                            * $ticks
                            * max(0, (int) ($effect['periodic_multiplier_bps'] ?? 10_000))
                            + 5_000,
                        10_000,
                    ));
                    $effective = $this->healExact($state, $amount, $metrics);
                    $actionLog[] = $this->logRow($round, $state, 'periodic_heal:'.$key, -$effective, false, false, effectType: 'recovery');
                }
            }
            $status['remaining']--;
            if ($status['remaining'] <= 0) {
                $actionLog[] = $this->logRow($round, $state, 'status_expired:'.$key, 0, false, false, effectType: 'status_expired');
                unset($state->statuses[$key]);
            }
        }
        unset($status);

        if ($state->side === 'player' && $state->alive()) {
            $regenerationBps = max(0, (int) ($state->modifiers['self_regeneration_target_hp_bps'] ?? 0));
            if ($regenerationBps > 0) {
                $cap = $state->stat('vitality') * 3;
                $amount = min(intdiv($state->maxHp * $regenerationBps, 10_000), $cap);
                $effective = $this->healExact($state, $amount, $metrics);
                if ($effective > 0) {
                    $actionLog[] = $this->logRow($round, $state, 'self_regeneration', -$effective, false, false, effectType: 'recovery');
                }
            }
        }
        if ($state->controlResistanceRounds > 0) {
            $state->controlResistanceRounds--;
            if ($state->controlResistanceRounds === 0) {
                $state->controlResistance = max(0, $state->controlResistance - 1);
            }
        }
    }

    private function actionImpaired(BuildCombatState $state, UndergroundRandom $random, int $round): bool
    {
        foreach ($state->statuses as $key => $status) {
            foreach ($status['effects'] as $effect) {
                if (($effect['type'] ?? null) !== 'action_impairment') {
                    continue;
                }
                $chance = min(10_000, max(0, (int) ($effect['skip_chance_bps'] ?? 10_000)));
                $resistance = min(
                    AlphaV1CombatRules::ACTION_IMPAIRMENT_RESISTANCE_CAP_BPS,
                    $this->scaledProbabilityContribution($state, 'agility', 2_000),
                );
                $chance = intdiv($chance * (10_000 - $resistance), 10_000);
                if ($random->integer("alpha-v1:impairment:{$state->key}:{$key}:{$round}", 1, 10_000) <= $chance) {
                    unset($state->statuses[$key]);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, int|null>  $metrics
     * @param  array<string, int>  $actionUsage
     * @param  list<array<string, mixed>>  $actionLog
     */
    private function counter(
        BuildCombatState $defender,
        BuildCombatState $attacker,
        int $round,
        array &$metrics,
        array &$actionUsage,
        array &$actionLog,
    ): void {
        $powerBps = max(0, (int) ($defender->modifiers['counter_power_bps'] ?? 0));
        if ($powerBps === 0 || ($defender->flags['counter_round'] ?? null) === $round
            || ! $defender->alive() || ! $attacker->alive()) {
            return;
        }
        $defender->flags['counter_round'] = $round;
        $effectivePower = intdiv(
            (($defender->stat('vitality') * 6) + ($defender->stat('might') * 4)) * $powerBps,
            10_000,
        );
        $damageBps = max(
            10_000 - AlphaV1CombatRules::DAMAGE_REDUCTION_CAP_BPS,
            min(20_000, $this->targetDamageBps($attacker, 'physical')),
        );
        $settled = $this->settlePostMitigationDamage(
            $attacker,
            max(1, intdiv($effectivePower * $damageBps, 10_000)),
            $defender->side === 'player',
            $metrics,
        );
        if ($defender->side === 'player') {
            $actionUsage['counter']++;
        }
        $actionLog[] = $this->logRow(
            $round,
            $defender,
            'counter',
            $settled['hp_damage'],
            false,
            false,
            barrierAbsorbed: $settled['barrier_absorbed'],
            effectType: 'counter',
        );
    }

    /**
     * @param  array<string, int|null>  $metrics
     * @return array{hp_damage: int, barrier_absorbed: int}
     */
    private function settlePostMitigationDamage(
        BuildCombatState $target,
        int $damage,
        bool $sourceIsPlayer,
        array &$metrics,
    ): array {
        $damage = max(0, $damage);
        $barrierAbsorbed = min($target->barrier, $damage);
        $target->barrier -= $barrierAbsorbed;
        $hpDamage = min($target->hp, $damage - $barrierAbsorbed);
        $target->hp -= $hpDamage;

        if ($sourceIsPlayer) {
            $metrics['damage_dealt'] += $hpDamage + $barrierAbsorbed;
        } elseif ($target->side === 'player') {
            $metrics['damage_received'] += $hpDamage;
        }
        if ($target->side === 'player') {
            $metrics['damage_prevented'] += $barrierAbsorbed;
        }

        return ['hp_damage' => $hpDamage, 'barrier_absorbed' => $barrierAbsorbed];
    }

    private function targetDamageBps(BuildCombatState $target, string $category): int
    {
        $defense = $category === 'miracle' ? $target->magicalDefense : $target->physicalDefense;
        $defense += $this->defenseStatusDelta($target, $category, $defense);
        $mitigationBps = max(
            10_000 - AlphaV1CombatRules::DAMAGE_REDUCTION_CAP_BPS,
            intdiv($target->defenseReference * 10_000, $target->defenseReference + max(0, $defense)),
        );
        $takenBps = 10_000
            - max(0, (int) ($target->modifiers['damage_taken_reduction_bps'] ?? 0))
            + $this->statusModifier($target, 'damage_taken_modifier', $category);

        return intdiv($mitigationBps * max(1, $takenBps), 10_000);
    }

    /** @param array<string, int|null> $metrics */
    private function healExact(BuildCombatState $target, int $amount, array &$metrics): int
    {
        $effective = min(max(0, $amount), $target->maxHp - $target->hp);
        $target->hp += $effective;
        if ($target->side === 'player') {
            $metrics['effective_healing'] += $effective;
        }

        return $effective;
    }

    /**
     * @param  array<string, int|null>  $metrics
     * @param  list<array<string, int>>  $history
     */
    private function changeMp(
        BuildCombatState $state,
        int $gain,
        int $cost,
        int $round,
        string $source,
        array &$metrics,
        array &$history,
        bool $recordMetrics = true,
    ): void {
        if ($cost > $state->mp) {
            throw new InvalidArgumentException('Underground alpha-v1 MP cost exceeds the current value.');
        }
        $before = $state->mp;
        $afterCost = $before - $cost;
        $overflow = max(0, $afterCost + $gain - AlphaV1CombatRules::MAX_MP);
        $state->mp = min(AlphaV1CombatRules::MAX_MP, $afterCost + $gain);
        if (! $recordMetrics) {
            return;
        }
        $metrics['mp_spent'] += $cost;
        if ($source === 'natural') {
            $metrics['mp_natural_recovery'] += min($gain, AlphaV1CombatRules::MAX_MP - $afterCost);
        } elseif ($source === 'skill_recovery') {
            $metrics['mp_skill_recovery'] += min($gain, AlphaV1CombatRules::MAX_MP - $afterCost);
        }
        $metrics['mp_overflow'] += $overflow;
        if ($state->mp === 0 && $metrics['mp_exhaustion_round'] === null) {
            $metrics['mp_exhaustion_round'] = $round;
        }
        $history[] = [
            'round' => $round,
            'before' => $before,
            'after' => $state->mp,
            'gained' => $gain,
            'spent' => $cost,
            'overflow' => $overflow,
        ];
    }

    /**
     * @param  array<string, int>  $passiveNodes
     * @return array<string, int|bool|string>
     */
    private function passiveModifiers(AlphaV1BuildCatalog $catalog, array $passiveNodes): array
    {
        $modifiers = [];
        foreach ($passiveNodes as $nodeKey => $rank) {
            $node = $catalog->node($nodeKey)['node'];
            foreach (($node['modifiers'] ?? []) as $modifier) {
                if (! is_array($modifier) || ! is_string($modifier['key'] ?? null)) {
                    continue;
                }
                $key = $modifier['key'];
                if (($modifier['flag'] ?? false) === true) {
                    $modifiers[$key] = true;
                } elseif (is_int($modifier['fixed_value'] ?? null)) {
                    $modifiers[$key] = $modifier['fixed_value'];
                } elseif (is_int($modifier['value_per_rank'] ?? null)) {
                    $modifiers[$key] = (int) ($modifiers[$key] ?? 0) + ($modifier['value_per_rank'] * $rank);
                }
            }
        }

        return $modifiers;
    }

    /**
     * @param  array<string, int|bool|string>  $base
     * @param  array<string, int>  $delta
     * @return array<string, int|bool|string>
     */
    private function addModifiers(array $base, array $delta): array
    {
        foreach ($delta as $key => $value) {
            $base[$key] = (int) ($base[$key] ?? 0) + $value;
        }

        return $base;
    }

    /** @return array<string, int> */
    private function currentStats(BuildCombatState $state): array
    {
        $stats = [];
        foreach (AlphaV1CombatRules::STATS as $key) {
            $stats[$key] = $state->stat($key);
        }

        return $stats;
    }

    /** @return array<string, mixed> */
    private function stateSnapshot(BuildCombatState $state): array
    {
        $statuses = [];
        foreach ($state->statuses as $key => $status) {
            $statuses[] = [
                'key' => $key,
                'remaining' => $status['remaining'],
                'stacks' => $status['stacks'],
            ];
        }

        return [
            'hp' => $state->hp,
            'max_hp' => $state->maxHp,
            'mp' => $state->mp,
            'barrier' => $state->barrier,
            'statuses' => $statuses,
            'role_stacks' => $state->roleStacks,
        ];
    }

    private function scaledProbabilityContribution(
        BuildCombatState $state,
        string $stat,
        int $basisPointsAtReference,
    ): int {
        return max(0, intdiv(
            $state->stat($stat) * $basisPointsAtReference,
            max(1, $state->defenseReference),
        ));
    }

    private function statusModifier(BuildCombatState $state, string $type, string $category): int
    {
        $value = 0;
        foreach ($state->statuses as $status) {
            foreach ($status['effects'] as $effect) {
                if (($effect['type'] ?? null) === $type
                    && (($effect['category'] ?? 'all') === 'all' || ($effect['category'] ?? null) === $category)) {
                    $value += (int) ($effect['value_bps'] ?? 0) * $status['stacks'];
                }
            }
        }

        return $value;
    }

    private function defenseStatusDelta(BuildCombatState $state, string $category, int $base): int
    {
        $stat = $category === 'miracle' ? 'magical_defense' : 'physical_defense';
        $bps = 0;
        foreach ($state->statuses as $status) {
            foreach ($status['effects'] as $effect) {
                if (($effect['type'] ?? null) === 'stat_modifier' && ($effect['stat'] ?? null) === $stat) {
                    $bps += (int) ($effect['value_bps'] ?? 0) * $status['stacks'];
                }
            }
        }

        return intdiv($base * $bps, 10_000);
    }

    /** @param list<array<string, mixed>> $effects */
    private function containsEffect(array $effects, string $type): bool
    {
        return in_array($type, array_column($effects, 'type'), true);
    }

    private function grantRoleStack(BuildCombatState $state, string $key, int $cap): void
    {
        $state->roleStacks[$key] = min($cap, ($state->roleStacks[$key] ?? 0) + 1);
    }

    /** @return list<string> */
    private function abnormalState(BuildCombatState $player, BuildCombatState $enemy): array
    {
        $abnormal = [];
        if ($player->hp < 0 || $player->hp > $player->maxHp) {
            $abnormal[] = 'player_hp_out_of_range';
        }
        if ($enemy->hp < 0 || $enemy->hp > $enemy->maxHp) {
            $abnormal[] = 'enemy_hp_out_of_range';
        }
        if ($player->mp < 0 || $player->mp > AlphaV1CombatRules::MAX_MP) {
            $abnormal[] = 'player_mp_out_of_range';
        }
        if ($player->barrier < 0 || $enemy->barrier < 0) {
            $abnormal[] = 'barrier_underflow';
        }
        foreach ([$player, $enemy] as $state) {
            foreach ($state->cooldowns as $cooldown) {
                if ($cooldown < 0) {
                    $abnormal[] = 'cooldown_underflow';
                }
            }
            foreach ($state->statuses as $status) {
                if ($status['remaining'] < 1 || $status['stacks'] < 1) {
                    $abnormal[] = 'status_duration_or_stack_invalid';
                }
            }
        }

        return array_values(array_unique($abnormal));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('Underground alpha-v1 string list is invalid.');
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('Underground alpha-v1 string list is invalid.');
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function logRow(
        int $round,
        BuildCombatState $actor,
        string $action,
        int $amount,
        bool $critical,
        bool $evaded,
        bool $guarded = false,
        bool $parried = false,
        int $barrierAbsorbed = 0,
        string $effectType = 'state',
    ): array {
        return [
            'kind' => 'effect',
            'effect_type' => $effectType,
            'round' => $round,
            'side' => $actor->side,
            'action' => $action,
            'amount' => $amount,
            'critical' => $critical,
            'evaded' => $evaded,
            'guarded' => $guarded,
            'parried' => $parried,
            'barrier_absorbed' => $barrierAbsorbed,
            'actor_hp' => $actor->hp,
            'actor_mp' => $actor->mp,
        ];
    }
}
