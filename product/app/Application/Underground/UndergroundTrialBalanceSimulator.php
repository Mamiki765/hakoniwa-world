<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use InvalidArgumentException;

/**
 * DB-free Trial sequence simulator using the current player combat snapshot path.
 */
final readonly class UndergroundTrialBalanceSimulator
{
    public const SIMULATION_TYPE = 'trial_sequence';

    public const SIMULATOR_VERSION = 'underground-trial-balance-v2';

    /** @var list<string> */
    private const PRIMARY_BUILD_KEYS = ['martial_red', 'guardianship_blue', 'blessing_green', 'free_black'];

    /** @var list<string> */
    private const STP_COMPARISON_BUILD_KEYS = [
        'matched_might_attack',
        'matched_might_vitality',
        'matched_might_agility',
        'matched_spirit_attack',
        'matched_spirit_vitality',
        'matched_spirit_agility',
        'owner_blessing_hp1000_zero_agility',
    ];

    /** @var list<string> */
    private const ZERO_AGILITY_BUILD_KEYS = [
        'matched_might_attack',
        'matched_might_vitality',
        'matched_spirit_attack',
        'matched_spirit_vitality',
        'owner_blessing_hp1000_zero_agility',
    ];

    public function __construct(
        private AtomicUndergroundExplorationCombat $combat,
        private UndergroundAlphaV1PlayerCatalog $players,
        private UndergroundEquipmentCatalog $equipment,
        private AlphaV1CombatRules $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function run(
        array $manifest,
        string $manifestContents,
        string $manifestHash,
        string $manifestPath,
        string $commitSha,
        ?bool $workingTreeDirty,
        ?int $seedStart = null,
        ?int $count = null,
        ?string $scenarioFilter = null,
    ): array {
        $normalized = $this->normalizeManifest($manifest);
        $this->assertManifestContents($manifest, $manifestContents, $manifestHash);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commitSha) !== 1 || $workingTreeDirty !== false) {
            throw new InvalidArgumentException('Trial simulation requires an exact clean source commit.');
        }

        $seedStart ??= $normalized['seed_start'];
        $count ??= $normalized['seed_count'];
        $this->assertSeedRange($seedStart, $count);
        $scenarios = $this->scenarioDefinitions($normalized, $scenarioFilter);
        $reports = [];
        $abnormalSeeds = [];
        foreach ($scenarios as $scenario) {
            $report = $this->simulateScenario($normalized, $scenario, $seedStart, $count);
            $reports[] = $report;
            foreach ($report['abnormal_seeds'] as $seed) {
                $abnormalSeeds[] = [
                    'scenario' => $scenario['id'],
                    'seed' => $seed,
                ];
            }
        }

        $trialContractPassed = $abnormalSeeds === []
            && array_reduce(
                $reports,
                static fn (bool $passed, array $report): bool => $passed
                    && $report['max_battles_observed'] <= 10
                    && $report['continued_after_failure_count'] === 0
                    && $report['heal_overflow_count'] === 0,
                true,
            );
        $builds = $this->buildReport($normalized);

        return [
            'schema_version' => 1,
            'simulation_type' => self::SIMULATION_TYPE,
            'simulator_version' => self::SIMULATOR_VERSION,
            'trial_identity' => $normalized['trial_identity'],
            'combat_identity' => AlphaV1CombatRules::IDENTITY,
            'player_growth_identity' => $this->players->growthIdentity(),
            'skill_tree_identity' => $this->players->skillTreeIdentity(),
            'equipment_catalog_identity' => $this->equipment->identity(),
            'seed_strategy_identity' => $normalized['seed_strategy_identity'],
            'manifest_path' => $manifestPath,
            'manifest_hash' => $manifestHash,
            'manifest_contents' => $manifestContents,
            'source_commit' => $commitSha,
            'working_tree_dirty' => false,
            'seed_range' => ['start' => $seedStart, 'count' => $count],
            'mp_contract' => [
                'starts_each_battle_at' => AlphaV1CombatRules::MAX_MP,
                'persists_between_battles' => false,
                'natural_recovery_per_round' => $normalized['natural_recovery'],
            ],
            'primary_interbattle_heal_bps' => $normalized['primary_heal_bps'],
            'checkpoints' => $normalized['checkpoints'],
            'recovery_comparison_bps' => $normalized['recovery_comparison_bps'],
            'recovery_comparison_levels' => $normalized['recovery_comparison_levels'],
            'builds' => $builds,
            'battle_sequence' => $this->sequenceReport($normalized),
            'agility_balance' => $this->agilityBalanceReport($normalized, $builds),
            'scenarios' => $reports,
            'owner_target_observation' => $this->ownerTargetObservation($reports, $normalized),
            'abnormal_seeds' => array_slice($abnormalSeeds, 0, 10),
            'trial_contract_passed' => $trialContractPassed,
            'laboratory_contract_passed' => $trialContractPassed,
            'experiment_thresholds_passed' => null,
            'reproduction_arguments' => $this->reproductionArguments(
                $manifestPath,
                $seedStart,
                $count,
                $commitSha,
                $scenarioFilter,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function replay(array $manifest, string $scenarioId, int $seed): array
    {
        $normalized = $this->normalizeManifest($manifest);
        $this->assertSeedRange($seed, 1);
        $scenario = $this->scenarioDefinitions($normalized, $scenarioId)[0];
        $result = $this->simulateTrial($normalized, $scenario, $seed, true);

        return [
            'simulation_type' => self::SIMULATION_TYPE,
            'simulator_version' => self::SIMULATOR_VERSION,
            'combat_identity' => AlphaV1CombatRules::IDENTITY,
            'trial_identity' => $normalized['trial_identity'],
            'scenario' => $scenario,
            'seed' => $seed,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array{id: string, build_key: string, level: int, heal_bps: int}  $scenario
     * @return array<string, mixed>
     */
    private function simulateScenario(array $normalized, array $scenario, int $seedStart, int $count): array
    {
        $clearCount = 0;
        $bossReached = 0;
        $bossCleared = 0;
        $stalemates = 0;
        $abnormalResults = 0;
        $continuedAfterFailure = 0;
        $healOverflow = 0;
        $trialEndHp = 0;
        $clearEndHp = 0;
        $roundTotals = [];
        $defeatDistribution = array_fill(1, 10, ['defeat' => 0, 'stalemate' => 0, 'total' => 0]);
        $battleMetrics = array_fill(1, 10, [
            'entered' => 0,
            'victories' => 0,
            'defeats' => 0,
            'stalemates' => 0,
            'rounds' => 0,
            'remaining_hp' => 0,
            'remaining_hp_survivors' => 0,
            'heal' => 0,
            'post_heal_hp' => 0,
            'damage_dealt' => 0,
            'damage_received' => 0,
            'effective_healing' => 0,
            'damage_prevented' => 0,
            'phase_transitions' => 0,
        ]);
        $actionUsage = [];
        $damageDealt = 0;
        $damageReceived = 0;
        $effectiveHealing = 0;
        $damagePrevented = 0;
        $mpExhaustions = 0;
        $mpUnavailable = 0;
        $finalMp = 0;
        $abnormalSeeds = [];
        $maxBattlesObserved = 0;
        $context = null;

        for ($offset = 0; $offset < $count; $offset++) {
            $seed = $seedStart + $offset;
            $trial = $this->simulateTrial($normalized, $scenario, $seed, false);
            $context ??= $trial['build'];
            $clearCount += $trial['cleared'] ? 1 : 0;
            $bossReached += $trial['boss_reached'] ? 1 : 0;
            $bossCleared += $trial['boss_cleared'] ? 1 : 0;
            $stalemates += $trial['stalemate_count'];
            $abnormalResults += $trial['abnormal_result_count'];
            $continuedAfterFailure += $trial['continued_after_failure'] ? 1 : 0;
            $healOverflow += $trial['heal_overflow_count'];
            $trialEndHp += $trial['final_hp'];
            $clearEndHp += $trial['cleared'] ? $trial['final_hp'] : 0;
            $roundTotals[] = $trial['total_rounds'];
            $maxBattlesObserved = max($maxBattlesObserved, count($trial['battles']));
            if ($trial['failed_battle'] !== null) {
                $failure = $trial['failure_result'];
                $defeatDistribution[$trial['failed_battle']][$failure]++;
                $defeatDistribution[$trial['failed_battle']]['total']++;
            }
            if ($trial['abnormal_result_count'] > 0 && count($abnormalSeeds) < 10) {
                $abnormalSeeds[] = $seed;
            }
            foreach ($trial['battles'] as $battle) {
                $index = $battle['index'];
                $battleMetrics[$index]['entered']++;
                $battleMetrics[$index]['victories'] += $battle['winner'] === 'player' ? 1 : 0;
                $battleMetrics[$index]['defeats'] += $battle['winner'] === 'enemy' ? 1 : 0;
                $battleMetrics[$index]['stalemates'] += $battle['winner'] === 'stalemate' ? 1 : 0;
                $battleMetrics[$index]['rounds'] += $battle['rounds'];
                $battleMetrics[$index]['remaining_hp'] += $battle['remaining_hp'];
                $battleMetrics[$index]['remaining_hp_survivors'] += $battle['winner'] === 'player'
                    ? $battle['remaining_hp']
                    : 0;
                $battleMetrics[$index]['heal'] += $battle['interbattle_heal'];
                $battleMetrics[$index]['post_heal_hp'] += $battle['post_heal_hp'];
                $battleMetrics[$index]['damage_dealt'] += $battle['damage_dealt'];
                $battleMetrics[$index]['damage_received'] += $battle['damage_received'];
                $battleMetrics[$index]['effective_healing'] += $battle['effective_healing'];
                $battleMetrics[$index]['damage_prevented'] += $battle['damage_prevented'];
                $battleMetrics[$index]['phase_transitions'] += $battle['phase_transition_count'];
                $damageDealt += $battle['damage_dealt'];
                $damageReceived += $battle['damage_received'];
                $effectiveHealing += $battle['effective_healing'];
                $damagePrevented += $battle['damage_prevented'];
                $mpExhaustions += $battle['mp_exhausted'] ? 1 : 0;
                $mpUnavailable += $battle['skill_unavailable_due_to_mp'];
                $finalMp += $battle['final_mp'];
                foreach ($battle['action_usage'] as $key => $uses) {
                    $actionUsage[$key] = ($actionUsage[$key] ?? 0) + $uses;
                }
            }
        }
        sort($roundTotals);

        $battleReport = [];
        foreach ($battleMetrics as $index => $metrics) {
            $entered = $metrics['entered'];
            $victories = $metrics['victories'];
            $battleReport[] = [
                'index' => $index,
                'key' => $normalized['sequence'][$index - 1],
                'label' => $normalized['enemies'][$normalized['sequence'][$index - 1]]['label'],
                'entered_count' => $entered,
                'victory_count' => $victories,
                'defeat_count' => $metrics['defeats'],
                'stalemate_count' => $metrics['stalemates'],
                'average_rounds' => $this->average($metrics['rounds'], $entered),
                'average_remaining_hp_all_entries' => $this->average($metrics['remaining_hp'], $entered),
                'average_remaining_hp_survivors' => $this->average($metrics['remaining_hp_survivors'], $victories),
                'average_interbattle_heal' => $this->average($metrics['heal'], $victories),
                'average_post_heal_hp' => $this->average($metrics['post_heal_hp'], $victories),
                'average_damage_dealt' => $this->average($metrics['damage_dealt'], $entered),
                'average_damage_received' => $this->average($metrics['damage_received'], $entered),
                'average_effective_healing' => $this->average($metrics['effective_healing'], $entered),
                'average_damage_prevented' => $this->average($metrics['damage_prevented'], $entered),
                'phase_transition_count' => $metrics['phase_transitions'],
            ];
        }
        $battleCount = array_sum(array_column($battleMetrics, 'entered'));

        return [
            'id' => $scenario['id'],
            'build_key' => $scenario['build_key'],
            'build_label' => $normalized['builds'][$scenario['build_key']]['label'],
            'combat_level' => $scenario['level'],
            'interbattle_heal_bps' => $scenario['heal_bps'],
            'iterations' => $count,
            'build' => $context,
            'clear_count' => $clearCount,
            'clear_rate' => $this->rate($clearCount, $count),
            'boss_reached_count' => $bossReached,
            'boss_reached_rate' => $this->rate($bossReached, $count),
            'boss_clear_count' => $bossCleared,
            'boss_clear_rate_all_trials' => $this->rate($bossCleared, $count),
            'boss_clear_rate_after_reaching' => $this->rate($bossCleared, $bossReached),
            'defeat_battle_distribution' => $defeatDistribution,
            'battle_metrics' => $battleReport,
            'trial_end_average_hp_all_trials' => $this->average($trialEndHp, $count),
            'trial_end_average_hp_clears' => $this->average($clearEndHp, $clearCount),
            'average_total_rounds' => $this->average(array_sum($roundTotals), $count),
            'median_total_rounds' => $this->percentile($roundTotals, 50),
            'stalemate_count' => $stalemates,
            'abnormal_result_count' => $abnormalResults,
            'average_damage_dealt' => $this->average($damageDealt, $count),
            'average_damage_received' => $this->average($damageReceived, $count),
            'average_in_combat_healing' => $this->average($effectiveHealing, $count),
            'average_damage_prevented' => $this->average($damagePrevented, $count),
            'action_usage_total' => $actionUsage,
            'guard_usage_total' => $actionUsage['defend'] ?? 0,
            'counter_usage_total' => $actionUsage['counter'] ?? 0,
            'barrier_action_usage_total' => array_sum(array_intersect_key(
                $actionUsage,
                array_flip(['counter_stance', 'renewing_guard', 'crystal_aegis']),
            )),
            'mp_exhausted_battle_count' => $mpExhaustions,
            'skill_unavailable_due_to_mp_count' => $mpUnavailable,
            'average_final_mp_per_battle' => $this->average($finalMp, $battleCount),
            'abnormal_seeds' => $abnormalSeeds,
            'max_battles_observed' => $maxBattlesObserved,
            'continued_after_failure_count' => $continuedAfterFailure,
            'heal_overflow_count' => $healOverflow,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array{id: string, build_key: string, level: int, heal_bps: int}  $scenario
     * @return array<string, mixed>
     */
    private function simulateTrial(array $normalized, array $scenario, int $seed, bool $includeActionLogs): array
    {
        $build = $this->buildContext($normalized, $scenario['build_key'], $scenario['level']);
        $snapshot = $build['player_snapshot'];
        $currentHp = $build['max_hp'];
        $battles = [];
        $failedBattle = null;
        $failureResult = null;
        $totalRounds = 0;
        $stalemates = 0;
        $abnormalResults = 0;
        $healOverflow = 0;
        foreach ($normalized['sequence'] as $offset => $enemyKey) {
            $index = $offset + 1;
            $snapshot['current_hp'] = $currentHp;
            $result = $this->combat->fight(
                $build['catalog'],
                $snapshot,
                $enemyKey,
                $this->battleSeed($normalized['trial_identity'], $seed, $index),
                $normalized['max_rounds'],
                $normalized['natural_recovery'],
            );
            $totalRounds += $result->rounds;
            $abnormalResults += $result->abnormalState === [] ? 0 : 1;
            $remainingHp = $result->winner === 'enemy' ? 0 : $result->playerRemainingHp;
            $interbattleHeal = 0;
            $postHealHp = $remainingHp;
            if ($result->winner === 'player' && $index < 10) {
                $nominalHeal = intdiv($build['max_hp'] * $scenario['heal_bps'], 10_000);
                $postHealHp = min($build['max_hp'], $remainingHp + $nominalHeal);
                $interbattleHeal = $postHealHp - $remainingHp;
                if ($postHealHp > $build['max_hp']) {
                    $healOverflow++;
                }
            }
            $battle = [
                'index' => $index,
                'key' => $enemyKey,
                'label' => $normalized['enemies'][$enemyKey]['label'],
                'seed' => $result->seed,
                'combat_identity' => $result->rulesIdentity,
                'winner' => $result->winner,
                'rounds' => $result->rounds,
                'remaining_hp' => $remainingHp,
                'interbattle_heal' => $interbattleHeal,
                'post_heal_hp' => $postHealHp,
                'damage_dealt' => $result->damageDealt,
                'damage_received' => $result->damageReceived,
                'effective_healing' => $result->effectiveHealing,
                'damage_prevented' => $result->damagePrevented,
                'action_usage' => $result->actionUsage,
                'starting_mp' => AlphaV1CombatRules::MAX_MP,
                'mp_exhausted' => $result->mpExhaustionRound !== null,
                'skill_unavailable_due_to_mp' => $result->skillUnavailableDueToMp,
                'final_mp' => $result->finalMp,
                'phase_transition_count' => count(array_filter(
                    $result->actionLog,
                    static fn (array $row): bool => ($row['effect_type'] ?? null) === 'phase_transition',
                )),
                'abnormal_state' => $result->abnormalState,
            ];
            if ($includeActionLogs) {
                $battle['action_log'] = $result->actionLog;
                $battle['mp_history'] = $result->mpHistory;
            }
            $battles[] = $battle;
            if ($result->winner !== 'player' || $result->abnormalState !== []) {
                $failedBattle = $index;
                $failureResult = $result->winner === 'stalemate' ? 'stalemate' : 'defeat';
                $stalemates += $result->winner === 'stalemate' ? 1 : 0;

                break;
            }
            $currentHp = $postHealHp;
        }
        $cleared = count($battles) === 10
            && $failedBattle === null
            && $battles[9]['winner'] === 'player';
        $finalBattle = $battles[count($battles) - 1];

        return [
            'seed' => $seed,
            'build' => $this->publicBuildContext($build),
            'cleared' => $cleared,
            'boss_reached' => count($battles) >= 10,
            'boss_cleared' => $cleared,
            'failed_battle' => $failedBattle,
            'failure_result' => $failureResult,
            'final_hp' => $finalBattle['winner'] === 'enemy' ? 0 : $finalBattle['remaining_hp'],
            'total_rounds' => $totalRounds,
            'stalemate_count' => $stalemates,
            'abnormal_result_count' => $abnormalResults,
            'heal_overflow_count' => $healOverflow,
            'continued_after_failure' => $failedBattle !== null && count($battles) > $failedBattle,
            'battles' => $battles,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function buildContext(array $normalized, string $buildKey, int $level): array
    {
        $configured = $normalized['builds'][$buildKey];
        $growthPath = $configured['growth_path'];
        $entitlement = $this->players->stpEntitlement($growthPath, $level);
        $allocatedStp = $this->allocateStp($entitlement, $configured['stp_weights_bps']);
        $equipmentDefinitions = array_map(
            fn (string $key): array => $this->equipment->definition($key),
            $configured['equipment_keys'],
        );
        foreach ($equipmentDefinitions as $definition) {
            if ($definition['rank'] !== 3) {
                throw new InvalidArgumentException("Trial build [{$buildKey}] must use Rank 3 equipment.");
            }
        }
        $loadout = $this->equipment->combatLoadout($equipmentDefinitions);
        $skillPoints = $this->assertInitialSkillAllocation($configured['skill_allocations']);
        $definition = $this->players->explorationCombatDefinition(
            $growthPath,
            $level,
            $allocatedStp,
            $loadout,
            $configured['label'],
            null,
            $configured['skill_allocations'],
        );
        $combatManifest = $definition['catalog']->manifest();
        foreach (['skills', 'statuses', 'enemies'] as $section) {
            foreach ($normalized[$section] as $key => $entry) {
                if (array_key_exists($key, $combatManifest[$section])) {
                    throw new InvalidArgumentException("Trial content [{$section}.{$key}] collides with the current catalog.");
                }
                $combatManifest[$section][$key] = $entry;
            }
        }

        return [
            ...$definition,
            'catalog' => new AlphaV1BuildCatalog($combatManifest),
            'build_key' => $buildKey,
            'label' => $configured['label'],
            'growth_path' => $growthPath,
            'combat_level' => $level,
            'stp_entitlement' => $entitlement,
            'allocated_stp' => $allocatedStp,
            'skill_points_spent' => $skillPoints,
            'skill_points_unspent' => $this->players->initialSkillPoints() - $skillPoints,
            'skill_allocations' => $configured['skill_allocations'],
            'equipment_keys' => $configured['equipment_keys'],
        ];
    }

    /**
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function publicBuildContext(array $build): array
    {
        return [
            'key' => $build['build_key'],
            'label' => $build['label'],
            'growth_path' => $build['growth_path'],
            'combat_level' => $build['combat_level'],
            'progression_stats' => $build['progression_stats'],
            'combat_stats' => $build['combat_stats'],
            'max_hp' => $build['max_hp'],
            'stp_entitlement' => $build['stp_entitlement'],
            'allocated_stp' => $build['allocated_stp'],
            'skill_points_total' => $this->players->initialSkillPoints(),
            'skill_points_spent' => $build['skill_points_spent'],
            'skill_points_unspent' => $build['skill_points_unspent'],
            'skill_allocations' => $build['skill_allocations'],
            'active_skills' => $build['active_skills'],
            'passive_modifiers' => $build['passive_modifiers'],
            'equipment_keys' => $build['equipment_keys'],
            'equipment' => $build['equipment'],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, array<string, array<string, mixed>>>  $builds
     * @return array<string, mixed>
     */
    private function agilityBalanceReport(array $normalized, array $builds): array
    {
        $ratioCurve = [];
        foreach ([[100, 100], [120, 100], [150, 100], [200, 100], [250, 100], [300, 100]] as [$self, $opponent]) {
            $ratioCurve[] = $this->agilityObservation($self, $opponent);
        }

        $trialRatios = [];
        foreach ($builds as $buildKey => $levels) {
            foreach ($levels as $levelKey => $build) {
                $selfAgility = (int) $build['combat_stats']['agility'];
                foreach ($normalized['sequence'] as $index => $enemyKey) {
                    $enemy = $normalized['enemies'][$enemyKey];
                    $observation = $this->agilityObservation(
                        $selfAgility,
                        (int) $enemy['base_stats']['agility'],
                    );
                    $trialRatios[] = [
                        'build_key' => $buildKey,
                        'build_label' => $build['label'],
                        'level' => (int) substr($levelKey, 2),
                        'battle_index' => $index + 1,
                        'enemy_key' => $enemyKey,
                        'enemy_label' => $enemy['label'],
                        ...$observation,
                    ];
                }
            }
        }

        return [
            'relative_advantage_formula' => 'max(0, (self - opponent) / (self + opponent))',
            'relative_advantage_cap_bps' => AlphaV1CombatRules::RELATIVE_AGILITY_ADVANTAGE_CAP_BPS,
            'evasion_total_cap_bps' => AlphaV1CombatRules::EVASION_CAP_BPS,
            'evasion_modifier_stacking' => 'relative_agility_bonus_plus_evasion_bps_then_total_cap',
            'ratio_curve' => $ratioCurve,
            'saturation' => $this->agilityObservation(1_000_000, 1),
            'trial_one_ratios' => $trialRatios,
        ];
    }

    /** @return array<string, int|float|string> */
    private function agilityObservation(int $selfAgility, int $opponentAgility): array
    {
        $profile = $this->rules->agilityProfile($selfAgility, $opponentAgility);

        return [
            'self_agility' => $selfAgility,
            'opponent_agility' => $opponentAgility,
            'ratio' => round($selfAgility / $opponentAgility, 4),
            'initiative' => $selfAgility > $opponentAgility
                ? 'self'
                : ($selfAgility < $opponentAgility ? 'opponent' : 'tie_break'),
            ...$profile,
            'evasion_rate' => $profile['evasion_bonus_bps'] / 10_000,
            'two_hit_rate' => $profile['two_hit_rate_bps'] / 10_000,
            'three_hit_rate' => $profile['three_hit_rate_bps'] / 10_000,
            'four_hit_rate' => $profile['four_hit_rate_bps'] / 10_000,
            'expected_damage_multiplier' => $profile['expected_damage_multiplier_bps'] / 10_000,
            'expected_incoming_damage_multiplier' => $profile['expected_incoming_damage_multiplier_bps'] / 10_000,
        ];
    }

    /**
     * @param  array<string, int>  $weights
     * @return array<string, int>
     */
    private function allocateStp(int $entitlement, array $weights): array
    {
        $allocated = [];
        $remainders = [];
        $allocatedTotal = 0;
        foreach (AlphaV1CombatRules::STATS as $stat) {
            $scaled = $entitlement * $weights[$stat];
            $allocated[$stat] = intdiv($scaled, 10_000);
            $remainders[$stat] = $scaled % 10_000;
            $allocatedTotal += $allocated[$stat];
        }
        $order = AlphaV1CombatRules::STATS;
        usort($order, static fn (string $left, string $right): int => $remainders[$right] <=> $remainders[$left]);
        for ($remaining = $entitlement - $allocatedTotal, $index = 0; $remaining > 0; $remaining--, $index++) {
            $allocated[$order[$index % count($order)]]++;
        }
        $ordered = [];
        foreach (AlphaV1CombatRules::STATS as $stat) {
            $ordered[$stat] = $allocated[$stat];
        }

        return $ordered;
    }

    /** @param array<string, array{rank: int, active_slot: int|null}> $allocations */
    private function assertInitialSkillAllocation(array $allocations): int
    {
        $catalog = $this->players->laboratoryCatalog();
        $spent = 0;
        foreach ($allocations as $nodeKey => $allocation) {
            $entry = $catalog->node($nodeKey);
            $node = $entry['node'];
            $rank = $allocation['rank'];
            $cost = $node['point_cost_per_rank'];
            $prerequisite = $node['prerequisite'] ?? null;
            $required = $node['invested_points_required'];
            if ($rank < 1 || $rank > $node['max_rank']
                || (is_string($prerequisite) && ! isset($allocations[$prerequisite]))
                || $this->players->investedBelowGate($catalog, $allocations, $entry['tree'], $required) < $required) {
                throw new InvalidArgumentException("Trial build skill allocation [{$nodeKey}] is not legally acquirable.");
            }
            $spent += $rank * $cost;
        }
        if ($spent > $this->players->initialSkillPoints()) {
            throw new InvalidArgumentException('Trial build exceeds the initial 20 SP contract.');
        }

        return $spent;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function normalizeManifest(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== 1
            || ($manifest['simulation_type'] ?? null) !== self::SIMULATION_TYPE
            || ($manifest['combat_identity'] ?? null) !== AlphaV1CombatRules::IDENTITY) {
            throw new InvalidArgumentException('Trial simulation manifest identity is invalid.');
        }
        $trialIdentity = $this->requiredString($manifest, 'trial_identity');
        $seedIdentity = $this->requiredString($manifest, 'seed_strategy_identity');
        $seedRange = $manifest['seed_range'] ?? null;
        $checkpoints = $manifest['checkpoints'] ?? null;
        $comparison = $manifest['interbattle_healing'] ?? null;
        $sequence = $manifest['battle_sequence'] ?? null;
        $builds = $manifest['builds'] ?? null;
        $skills = $manifest['skills'] ?? [];
        $statuses = $manifest['statuses'] ?? [];
        $enemies = $manifest['enemies'] ?? null;
        $maxRounds = $manifest['max_rounds'] ?? null;
        $naturalRecovery = $manifest['mp_natural_recovery'] ?? null;
        if (! is_array($seedRange) || ! is_int($seedRange['start'] ?? null) || ! is_int($seedRange['count'] ?? null)
            || ! is_array($checkpoints) || ! array_is_list($checkpoints)
            || ! is_array($comparison) || ! is_array($sequence) || ! array_is_list($sequence)
            || count($sequence) !== 10 || ! is_array($builds) || ! is_array($skills)
            || ! is_array($statuses) || ! is_array($enemies) || $maxRounds !== 100
            || $naturalRecovery !== 300) {
            throw new InvalidArgumentException('Trial simulation manifest contract is invalid.');
        }
        foreach ([20, 25, 30, 35] as $checkpoint) {
            if (! in_array($checkpoint, $checkpoints, true)) {
                throw new InvalidArgumentException('Trial simulation must include Lv20, Lv25, Lv30, and Lv35.');
            }
        }
        $primaryHeal = $comparison['primary_bps'] ?? null;
        $comparisonBps = $comparison['comparison_bps'] ?? null;
        $comparisonLevels = $comparison['comparison_levels'] ?? null;
        if ($primaryHeal !== 2000 || $comparisonBps !== [1000, 2000, 3000]
            || ! is_array($comparisonLevels) || ! in_array(30, $comparisonLevels, true)) {
            throw new InvalidArgumentException('Trial healing comparison contract is invalid.');
        }
        $expectedBuilds = [...self::PRIMARY_BUILD_KEYS, ...self::STP_COMPARISON_BUILD_KEYS];
        if (array_keys($builds) !== $expectedBuilds) {
            throw new InvalidArgumentException('Trial simulation must define the four representative, six matched-STP, and owner baseline builds in stable order.');
        }
        foreach ($builds as $key => &$build) {
            $growthPath = $build['growth_path'] ?? null;
            if (! is_array($build) || ! in_array($growthPath, self::PRIMARY_BUILD_KEYS, true)
                || ! is_string($build['label'] ?? null) || $build['label'] === ''
                || ! is_array($build['stp_weights_bps'] ?? null)
                || array_keys($build['stp_weights_bps']) !== AlphaV1CombatRules::STATS
                || array_sum($build['stp_weights_bps']) !== 10_000
                || array_filter($build['stp_weights_bps'], static fn (mixed $value): bool => ! is_int($value) || $value < 0) !== []
                || ! is_array($build['skill_allocations'] ?? null)
                || ! is_array($build['equipment_keys'] ?? null)
                || count($build['equipment_keys']) !== 3
                || array_filter($build['equipment_keys'], 'is_string') !== $build['equipment_keys']) {
                throw new InvalidArgumentException("Trial build [{$key}] is invalid.");
            }
            if (in_array($key, self::ZERO_AGILITY_BUILD_KEYS, true)
                && $build['stp_weights_bps']['agility'] !== 0) {
                throw new InvalidArgumentException("Trial zero-agility build [{$key}] must allocate no STP to agility.");
            }
            if (in_array($key, ['matched_might_agility', 'matched_spirit_agility'], true)
                && $build['stp_weights_bps']['agility'] !== 1000) {
                throw new InvalidArgumentException("Trial agility comparison build [{$key}] must allocate 10 percent of STP to agility.");
            }
        }
        unset($build);
        foreach ($sequence as $key) {
            if (! is_string($key) || ! is_array($enemies[$key] ?? null)) {
                throw new InvalidArgumentException('Trial battle sequence references an unknown enemy.');
            }
        }
        $bossKey = $sequence[9];
        if (($enemies[$bossKey]['boss'] ?? null) !== true || ($enemies[$bossKey]['label'] ?? null) !== 'ワイバーン') {
            throw new InvalidArgumentException('Trial battle 10 must be the Wyvern boss.');
        }
        $this->assertSeedRange($seedRange['start'], $seedRange['count']);

        return [
            'trial_identity' => $trialIdentity,
            'seed_strategy_identity' => $seedIdentity,
            'seed_start' => $seedRange['start'],
            'seed_count' => $seedRange['count'],
            'checkpoints' => $checkpoints,
            'primary_heal_bps' => $primaryHeal,
            'recovery_comparison_bps' => $comparisonBps,
            'recovery_comparison_levels' => $comparisonLevels,
            'max_rounds' => $maxRounds,
            'natural_recovery' => $naturalRecovery,
            'builds' => $builds,
            'sequence' => $sequence,
            'skills' => $skills,
            'statuses' => $statuses,
            'enemies' => $enemies,
            'tuning_parameters' => $manifest['tuning_parameters'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<array{id: string, build_key: string, level: int, heal_bps: int}>
     */
    private function scenarioDefinitions(array $normalized, ?string $filter): array
    {
        $scenarios = [];
        foreach (array_keys($normalized['builds']) as $buildKey) {
            foreach ($normalized['checkpoints'] as $level) {
                $scenario = $this->scenario($buildKey, $level, $normalized['primary_heal_bps']);
                $scenarios[$scenario['id']] = $scenario;
            }
            if (! in_array($buildKey, self::PRIMARY_BUILD_KEYS, true)) {
                continue;
            }
            foreach ($normalized['recovery_comparison_levels'] as $level) {
                foreach ($normalized['recovery_comparison_bps'] as $healBps) {
                    $scenario = $this->scenario($buildKey, $level, $healBps);
                    $scenarios[$scenario['id']] = $scenario;
                }
            }
        }
        if ($filter !== null) {
            $selected = $scenarios[$filter] ?? null;
            if (! is_array($selected)) {
                throw new InvalidArgumentException("Unknown Trial simulation scenario [{$filter}].");
            }

            return [$selected];
        }

        return array_values($scenarios);
    }

    /** @return array{id: string, build_key: string, level: int, heal_bps: int} */
    private function scenario(string $buildKey, int $level, int $healBps): array
    {
        return [
            'id' => "{$buildKey}:lv{$level}:heal{$healBps}",
            'build_key' => $buildKey,
            'level' => $level,
            'heal_bps' => $healBps,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function buildReport(array $normalized): array
    {
        $report = [];
        foreach (array_keys($normalized['builds']) as $buildKey) {
            $report[$buildKey] = [];
            foreach ($normalized['checkpoints'] as $level) {
                $report[$buildKey]["lv{$level}"] = $this->publicBuildContext(
                    $this->buildContext($normalized, $buildKey, $level),
                );
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<array<string, mixed>>
     */
    private function sequenceReport(array $normalized): array
    {
        $report = [];
        foreach ($normalized['sequence'] as $index => $key) {
            $enemy = $normalized['enemies'][$key];
            $report[] = [
                'index' => $index + 1,
                'key' => $key,
                'label' => $enemy['label'],
                'boss' => ($enemy['boss'] ?? false) === true,
                'max_hp' => $enemy['max_hp'],
                'physical_defense' => $enemy['physical_defense'],
                'magical_defense' => $enemy['magical_defense'],
                'weapon_power' => $enemy['weapon_power'],
                'agility' => $enemy['base_stats']['agility'],
                'skills' => $enemy['skills'],
                'ai_rules' => $enemy['ai_rules'],
                'modifiers' => $enemy['modifiers'],
                'phase_transition' => $enemy['phase_transition'] ?? null,
            ];
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function ownerTargetObservation(array $reports, array $normalized): array
    {
        $byId = array_column($reports, null, 'id');
        $builds = [];
        foreach (self::PRIMARY_BUILD_KEYS as $buildKey) {
            $lv25 = $byId[$this->scenario($buildKey, 25, 2000)['id']]['clear_rate'] ?? null;
            $lv30 = $byId[$this->scenario($buildKey, 30, 2000)['id']]['clear_rate'] ?? null;
            $lv35 = $byId[$this->scenario($buildKey, 35, 2000)['id']]['clear_rate'] ?? null;
            if (! is_float($lv25) || ! is_float($lv30) || ! is_float($lv35)) {
                continue;
            }
            $builds[$buildKey] = [
                'lv25_clear_rate' => $lv25,
                'lv30_clear_rate' => $lv30,
                'lv35_clear_rate' => $lv35,
                'lv30_in_advisory_30_to_70_percent_band' => $lv30 >= 0.3 && $lv30 <= 0.7,
                'progression_curve_strictly_increases' => $lv25 < $lv30 && $lv30 < $lv35,
            ];
        }

        return [
            'advisory_not_acceptance_gate' => true,
            'builds' => $builds,
            'all_lv30_builds_in_band' => $builds !== []
                && array_reduce($builds, static fn (bool $all, array $row): bool => $all
                    && $row['lv30_in_advisory_30_to_70_percent_band'], true),
            'all_progression_curves_increase' => $builds !== []
                && array_reduce($builds, static fn (bool $all, array $row): bool => $all
                    && $row['progression_curve_strictly_increases'], true),
        ];
    }

    private function battleSeed(string $trialIdentity, int $trialSeed, int $battleIndex): int
    {
        $hex = substr(hash('sha256', "{$trialIdentity}|{$trialSeed}|{$battleIndex}"), 0, 8);

        return (int) (hexdec($hex) & 0x7FFFFFFF);
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestContents(array $manifest, string $contents, string $hash): void
    {
        if (hash('sha256', $contents) !== $hash) {
            throw new InvalidArgumentException('Trial simulation manifest hash does not match its contents.');
        }
        $decoded = json_decode($contents, true);
        if (! is_array($decoded) || $decoded !== $manifest) {
            throw new InvalidArgumentException('Trial simulation manifest contents do not match the decoded input.');
        }
    }

    private function assertSeedRange(int $start, int $count): void
    {
        if ($start < 0 || $count < 1 || $count > 10_000 || $start + $count - 1 > 2_147_483_647) {
            throw new InvalidArgumentException('Trial simulation seed range is invalid.');
        }
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : throw new InvalidArgumentException("Trial simulation [{$key}] is invalid.");
    }

    private function average(int $sum, int $count): float
    {
        return $count === 0 ? 0.0 : round($sum / $count, 3);
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator, 6);
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, int $percentile): int
    {
        if ($sorted === []) {
            return 0;
        }

        return $sorted[(int) floor((count($sorted) - 1) * $percentile / 100)];
    }

    /** @return list<string> */
    private function reproductionArguments(
        string $manifestPath,
        int $seedStart,
        int $count,
        string $commitSha,
        ?string $scenario,
    ): array {
        $arguments = [
            'php',
            'artisan',
            'underground:balance',
            '--manifest='.$manifestPath,
            '--seed-start='.$seedStart,
            '--count='.$count,
            '--commit-sha='.$commitSha,
        ];
        if ($scenario !== null) {
            $arguments[] = '--scenario='.$scenario;
        }

        return $arguments;
    }
}
