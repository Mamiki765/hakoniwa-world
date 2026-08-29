<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
use InvalidArgumentException;
use JsonException;

final readonly class UndergroundBuildBalanceSimulator
{
    public function __construct(
        private AlphaV1CombatModel $model,
        private UndergroundBuildValidator $validator,
        private DeterministicEquipmentGenerator $equipmentGenerator,
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
        ?int $seedStartOverride = null,
        ?int $countOverride = null,
        ?string $scenarioFilter = null,
    ): array {
        $this->assertManifestContents($manifest, $manifestContents, $manifestHash);
        $catalog = new AlphaV1BuildCatalog($manifest);
        $seedRange = $manifest['seed_range'] ?? null;
        $seedStart = $seedStartOverride ?? (is_array($seedRange) ? ($seedRange['start'] ?? null) : null);
        $count = $countOverride ?? (is_array($seedRange) ? ($seedRange['count'] ?? null) : null);
        $maxRounds = $manifest['max_rounds'] ?? null;
        if (! is_int($seedStart) || ! is_int($count) || ! is_int($maxRounds)) {
            throw new InvalidArgumentException('Underground alpha-v1 simulation range is invalid.');
        }
        $this->assertSeedRange($seedStart, $count);
        $experiments = $catalog->experiments();
        $allowedFilters = [null, 'pressure', 'appropriate', 'scale', 'mp', 'sidegrade'];
        if (! in_array($scenarioFilter, $allowedFilters, true)) {
            throw new InvalidArgumentException("Unknown Underground alpha-v1 experiment [{$scenarioFilter}].");
        }
        $buildDefinitions = [];
        foreach ($catalog->buildKeys() as $buildKey) {
            $build = $this->validator->validate($catalog, $buildKey);
            $buildDefinitions[$buildKey] = [
                'label' => $build['label'],
                'base_stats' => $build['base_stats'],
                'weapon_style' => $build['weapon_style'],
                'points_spent' => $build['points_spent'],
                'tree_points' => $build['tree_points'],
                'active_skills' => $build['active_skills'],
                'ai_rules' => $build['ai_rules'],
                'equipment' => $build['equipment'],
            ];
        }

        $pressure = null;
        $appropriate = null;
        $scale = null;
        $mpSweep = null;
        $mpAbnormalReports = [];
        $sidegrade = null;
        if ($scenarioFilter === null || $scenarioFilter === 'pressure') {
            $pressure = $this->simulateExperiment(
                $catalog, $experiments, 'pressure', $seedStart, $count, $manifestPath,
            );
        }
        if ($scenarioFilter === null || $scenarioFilter === 'appropriate') {
            $appropriate = $this->simulateExperiment(
                $catalog, $experiments, 'appropriate', $seedStart, $count, $manifestPath,
            );
        }
        if ($scenarioFilter === null || $scenarioFilter === 'scale') {
            $scaleCount = min($count, $this->requiredInt($manifest, 'scale_seed_count'));
            $scale = [];
            foreach ($catalog->tierKeys() as $tierKey) {
                $scale[$tierKey] = $this->simulateBuilds(
                    $catalog,
                    (string) $experiments['pressure']['enemy_key'],
                    $tierKey,
                    (int) $experiments['pressure']['max_rounds'],
                    $seedStart,
                    $scaleCount,
                    null,
                    $manifestPath,
                    'pressure',
                );
            }
        }
        if ($scenarioFilter === null || $scenarioFilter === 'mp') {
            $sweepCount = min($count, $this->requiredInt($manifest, 'scale_seed_count'));
            $candidates = $manifest['mp_recovery_candidates'] ?? null;
            if (! is_array($candidates) || ! array_is_list($candidates)) {
                throw new InvalidArgumentException('Underground alpha-v1 MP recovery candidates are invalid.');
            }
            $mpExperiment = $experiments['mp'] ?? null;
            if (! is_array($mpExperiment)) {
                throw new InvalidArgumentException('Underground alpha-v1 MP experiment is invalid.');
            }
            $mpSweep = [];
            foreach ($candidates as $candidate) {
                if (! is_int($candidate) || $candidate < 0) {
                    throw new InvalidArgumentException('Underground alpha-v1 MP recovery candidate is invalid.');
                }
                $reports = $this->simulateBuilds(
                    $catalog,
                    (string) ($mpExperiment['enemy_key'] ?? ''),
                    (string) ($mpExperiment['tier_key'] ?? ''),
                    (int) ($mpExperiment['max_rounds'] ?? 0),
                    $seedStart,
                    $sweepCount,
                    $candidate,
                    $manifestPath,
                    'mp',
                );
                $mpAbnormalReports[] = $reports;
                $mpSweep[(string) $candidate] = array_map(static fn (array $report): array => [
                    'median_rounds' => $report['round_distribution']['median'],
                    'survival_rate' => $report['survival_rate'],
                    'stalemate_rate' => $report['outcomes']['stalemate_rate'],
                    'average_mp_end' => $report['mp_economy']['average_end'],
                    'exhaustion_rate' => $report['mp_economy']['exhaustion_rate'],
                    'median_exhaustion_round' => $report['mp_economy']['median_exhaustion_round'],
                    'skill_unavailable_due_to_mp' => $report['mp_economy']['skill_unavailable_due_to_mp'],
                    'normal_attack_rate' => $report['action_rates']['normal_attack'],
                    'mp_spent' => $report['mp_economy']['average_spent'],
                    'natural_recovery' => $report['mp_economy']['average_natural_recovery'],
                    'skill_recovery' => $report['mp_economy']['average_skill_recovery'],
                    'overflow' => $report['mp_economy']['average_overflow'],
                ], $reports);
            }
        }
        if ($scenarioFilter === null || $scenarioFilter === 'sidegrade') {
            $sidegrade = $this->sidegradeObservation(
                $catalog,
                $experiments['sidegrade'] ?? null,
                $seedStart,
                min($count, $this->requiredInt($manifest, 'scale_seed_count')),
            );
        }

        $abnormalSeeds = $this->collectAbnormalSeeds([
            $pressure, $appropriate, $scale, $mpAbnormalReports, $sidegrade,
        ]);
        $roleRatios = is_array($pressure) ? $this->roleDamageRatios($pressure) : null;
        $skillCosts = [];
        foreach ($manifest['skills'] as $key => $skill) {
            if (is_string($key) && is_array($skill) && is_string($skill['node_key'] ?? null)) {
                $skillCosts[$key] = $skill['mp_cost'];
            }
        }
        $reproductionArguments = [
            'php', 'artisan', 'underground:balance', "--manifest={$manifestPath}",
            "--seed-start={$seedStart}", "--count={$count}", "--commit-sha={$commitSha}",
        ];
        if ($scenarioFilter !== null) {
            $reproductionArguments[] = "--scenario={$scenarioFilter}";
        }

        return [
            'schema_version' => 2,
            'simulator_version' => AlphaV1CombatRules::SIMULATOR_VERSION,
            'combat_identity' => AlphaV1CombatRules::IDENTITY,
            'generator_identity' => AlphaV1CombatRules::GENERATOR_IDENTITY,
            'commit_sha' => $commitSha,
            'working_tree_dirty' => $workingTreeDirty,
            'manifest_path' => $manifestPath,
            'manifest_hash' => $manifestHash,
            'manifest_contents' => $manifestContents,
            'seed_range' => ['start' => $seedStart, 'count' => $count, 'end' => $seedStart + $count - 1],
            'build_definitions' => $buildDefinitions,
            'item_level_and_point_budget' => [
                'tiers' => $manifest['tiers'],
                'point_budget' => $catalog->balanceInt('build_point_budget'),
                'full_tree_points' => $catalog->balanceInt('full_tree_points'),
                'all_tree_points' => $catalog->balanceInt('all_tree_points'),
            ],
            'selected_mp_natural_recovery' => $catalog->balanceInt('mp_natural_recovery'),
            'skill_costs' => $skillCosts,
            'role_damage_ratios' => $roleRatios,
            'pressure_benchmark' => $pressure,
            'appropriate_encounter' => $appropriate,
            'scale_invariance' => $scale,
            'mp_economy_sweep' => $mpSweep,
            'sidegrade_observation' => $sidegrade,
            'abnormal_seeds' => $abnormalSeeds,
            'laboratory_contract_passed' => $abnormalSeeds === [],
            'experiment_thresholds_passed' => null,
            'balance_targets_are_observations' => true,
            'reproduction_arguments' => $reproductionArguments,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function replay(array $manifest, string $scenarioId, int $seed): array
    {
        $catalog = new AlphaV1BuildCatalog($manifest);
        $this->assertSeedRange($seed, 1);
        $parts = explode(':', $scenarioId);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Alpha-v1 replay scenario must be experiment:build:tier.');
        }
        [$experimentKey, $buildKey, $tierKey] = $parts;
        $experiment = $catalog->experiments()[$experimentKey] ?? null;
        if (! is_array($experiment)) {
            throw new InvalidArgumentException("Unknown Underground alpha-v1 experiment [{$experimentKey}].");
        }
        $result = $this->model->fight(
            $catalog,
            $buildKey,
            (string) $experiment['enemy_key'],
            $tierKey,
            $seed,
            (int) $experiment['max_rounds'],
        );

        return [
            'schema_version' => 2,
            'simulator_version' => AlphaV1CombatRules::SIMULATOR_VERSION,
            'scenario_id' => $scenarioId,
            'result' => $result->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $experiments
     * @return array<string, mixed>
     */
    private function simulateExperiment(
        AlphaV1BuildCatalog $catalog,
        array $experiments,
        string $key,
        int $seedStart,
        int $count,
        string $manifestPath,
    ): array {
        $experiment = $experiments[$key] ?? null;
        if (! is_array($experiment)) {
            throw new InvalidArgumentException("Underground alpha-v1 experiment [{$key}] is invalid.");
        }

        return $this->simulateBuilds(
            $catalog,
            (string) ($experiment['enemy_key'] ?? ''),
            (string) ($experiment['tier_key'] ?? ''),
            (int) ($experiment['max_rounds'] ?? 0),
            $seedStart,
            $count,
            null,
            $manifestPath,
            $key,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function simulateBuilds(
        AlphaV1BuildCatalog $catalog,
        string $enemyKey,
        string $tierKey,
        int $maxRounds,
        int $seedStart,
        int $count,
        ?int $naturalRecovery,
        string $manifestPath,
        string $experimentKey,
    ): array {
        $reports = [];
        foreach ($catalog->buildKeys() as $buildKey) {
            $reports[$buildKey] = $this->simulateBuild(
                $catalog,
                $buildKey,
                $enemyKey,
                $tierKey,
                $maxRounds,
                $seedStart,
                $count,
                $naturalRecovery,
                $manifestPath,
                $experimentKey,
            );
        }

        return $reports;
    }

    /** @return array<string, mixed> */
    private function simulateBuild(
        AlphaV1BuildCatalog $catalog,
        string $buildKey,
        string $enemyKey,
        string $tierKey,
        int $maxRounds,
        int $seedStart,
        int $count,
        ?int $naturalRecovery,
        string $manifestPath,
        string $experimentKey,
    ): array {
        $wins = $losses = $stalemates = $survived = 0;
        $damagePerRound = [];
        $rounds = [];
        $healing = $prevented = $mpEnd = $mpSpent = $mpNatural = $mpSkill = $mpOverflow = 0;
        $skillUnavailable = 0;
        $exhaustions = [];
        $actions = [];
        $statusUptime = [];
        $mpByRound = [];
        $mpByRoundCounts = [];
        $abnormalSeeds = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $seed = $seedStart + $offset;
            $result = $this->model->fight(
                $catalog,
                $buildKey,
                $enemyKey,
                $tierKey,
                $seed,
                $maxRounds,
                $naturalRecovery,
            );
            match ($result->winner) {
                'player' => $wins++,
                'enemy' => $losses++,
                default => $stalemates++,
            };
            $survived += $result->playerRemainingHp > 0 ? 1 : 0;
            $damagePerRound[] = $result->damageDealt / max(1, $result->rounds);
            $rounds[] = $result->rounds;
            $healing += $result->effectiveHealing;
            $prevented += $result->damagePrevented;
            $mpEnd += $result->finalMp;
            $mpSpent += $result->mpSpent;
            $mpNatural += $result->mpNaturalRecovery;
            $mpSkill += $result->mpSkillRecovery;
            $mpOverflow += $result->mpOverflow;
            $skillUnavailable += $result->skillUnavailableDueToMp;
            if ($result->mpExhaustionRound !== null) {
                $exhaustions[] = $result->mpExhaustionRound;
            }
            foreach ($result->actionUsage as $key => $value) {
                $actions[$key] = ($actions[$key] ?? 0) + $value;
            }
            foreach ($result->statusUptime as $key => $value) {
                $statusUptime[$key] = ($statusUptime[$key] ?? 0) + $value;
            }
            $lastMpByRound = [];
            foreach ($result->mpHistory as $row) {
                $lastMpByRound[$row['round']] = $row['after'];
            }
            foreach ($lastMpByRound as $round => $value) {
                $mpByRound[$round] = ($mpByRound[$round] ?? 0) + $value;
                $mpByRoundCounts[$round] = ($mpByRoundCounts[$round] ?? 0) + 1;
            }
            if ($result->abnormalState !== [] && count($abnormalSeeds) < 10) {
                $abnormalSeeds[] = [
                    'seed' => $seed,
                    'states' => $result->abnormalState,
                    'reproduction_arguments' => [
                        'php', 'artisan', 'underground:balance', "--manifest={$manifestPath}",
                        "--scenario={$experimentKey}:{$buildKey}:{$tierKey}", "--replay-seed={$seed}",
                    ],
                ];
            }
        }
        sort($damagePerRound, SORT_NUMERIC);
        sort($rounds, SORT_NUMERIC);
        sort($exhaustions, SORT_NUMERIC);
        $totalActions = max(1, array_sum($actions) - ($actions['counter'] ?? 0));
        $actionRates = [];
        foreach ($actions as $key => $value) {
            $actionRates[$key] = $this->ratio($value, $totalActions);
        }
        $statusRates = [];
        $totalRounds = max(1, array_sum($rounds));
        foreach ($statusUptime as $key => $value) {
            $statusRates[$key] = $this->ratio($value, $totalRounds);
        }
        $averageMpByRound = [];
        foreach ($mpByRound as $round => $value) {
            $averageMpByRound[(string) $round] = round($value / $mpByRoundCounts[$round], 3);
        }

        return [
            'iterations' => $count,
            'enemy_key' => $enemyKey,
            'tier_key' => $tierKey,
            'mean_damage_per_round' => round(array_sum($damagePerRound) / $count, 3),
            'median_damage_per_round' => round($this->percentile($damagePerRound, 50), 3),
            'survival_rate' => $this->ratio($survived, $count),
            'effective_healing_average' => round($healing / $count, 3),
            'damage_prevented_average' => round($prevented / $count, 3),
            'round_distribution' => [
                'median' => (int) $this->percentile($rounds, 50),
                'p90' => (int) $this->percentile($rounds, 90),
                'p99' => (int) $this->percentile($rounds, 99),
            ],
            'outcomes' => [
                'wins' => $wins,
                'losses' => $losses,
                'stalemates' => $stalemates,
                'win_rate' => $this->ratio($wins, $count),
                'loss_rate' => $this->ratio($losses, $count),
                'stalemate_rate' => $this->ratio($stalemates, $count),
            ],
            'mp_economy' => [
                'start' => AlphaV1CombatRules::MAX_MP,
                'average_end' => round($mpEnd / $count, 3),
                'average_spent' => round($mpSpent / $count, 3),
                'average_natural_recovery' => round($mpNatural / $count, 3),
                'average_skill_recovery' => round($mpSkill / $count, 3),
                'average_overflow' => round($mpOverflow / $count, 3),
                'exhaustion_rate' => $this->ratio(count($exhaustions), $count),
                'median_exhaustion_round' => $exhaustions === [] ? null : (int) $this->percentile($exhaustions, 50),
                'skill_unavailable_due_to_mp' => $skillUnavailable,
                'average_by_round' => $averageMpByRound,
            ],
            'action_usage' => $actions,
            'action_rates' => $actionRates,
            'status_uptime' => $statusRates,
            'abnormal_seeds' => $abnormalSeeds,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $pressure
     * @return array<string, float>
     */
    private function roleDamageRatios(array $pressure): array
    {
        $baseline = (float) ($pressure['pure_attacker']['mean_damage_per_round'] ?? 0);
        if ($baseline <= 0) {
            throw new InvalidArgumentException('Underground alpha-v1 attacker benchmark produced no damage.');
        }
        $ratios = [];
        foreach ($pressure as $key => $report) {
            $ratios[$key] = round(((float) $report['mean_damage_per_round'] / $baseline) * 100, 3);
        }

        return $ratios;
    }

    /**
     * @return array<string, mixed>
     */
    private function sidegradeObservation(
        AlphaV1BuildCatalog $catalog,
        mixed $definition,
        int $seedStart,
        int $count,
    ): array {
        if (! is_array($definition)) {
            throw new InvalidArgumentException('Underground alpha-v1 sidegrade experiment is invalid.');
        }
        $request = [
            'slot' => (string) ($definition['slot'] ?? ''),
            'weapon_style' => (string) ($definition['weapon_style'] ?? ''),
            'rarity' => 'unique',
            'seed' => (int) ($definition['seed'] ?? -1),
        ];
        $low = $this->equipmentGenerator->generate($catalog, (int) $definition['low_item_level'], $request);
        $request['rarity'] = 'epic';
        $high = $this->equipmentGenerator->generate($catalog, (int) $definition['high_item_level'], $request);
        $lowScore = $this->equipmentScore($this->equipmentGenerator->aggregate([$low]));
        $highScore = $this->equipmentScore($this->equipmentGenerator->aggregate([$high]));
        $lowCombat = $this->simulateSidegradeVariant(
            $catalog,
            $definition,
            $seedStart,
            $count,
            [
                'weapon' => [
                    'item_level' => (int) $definition['low_item_level'],
                    'request' => [
                        ...$request,
                        'rarity' => 'unique',
                    ],
                ],
            ],
        );
        $highCombat = $this->simulateSidegradeVariant(
            $catalog,
            $definition,
            $seedStart,
            $count,
            [
                'weapon' => [
                    'item_level' => (int) $definition['high_item_level'],
                    'request' => $request,
                ],
            ],
        );

        return [
            'low_item_level_unique' => $low,
            'higher_item_level_epic' => $high,
            'numeric_power_ratio' => $highScore === 0 ? null : round($lowScore / $highScore, 6),
            'combat_observation' => [
                'low_item_level_unique' => $lowCombat,
                'higher_item_level_epic' => $highCombat,
                'damage_ratio' => $highCombat['mean_damage_per_round'] <= 0
                    ? null
                    : round($lowCombat['mean_damage_per_round'] / $highCombat['mean_damage_per_round'], 6),
            ],
            'classification' => 'sidegrade_observation_not_absolute_acceptance',
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array{item_level: int, request: array<string, mixed>}>  $equipmentOverrides
     * @return array<string, mixed>
     */
    private function simulateSidegradeVariant(
        AlphaV1BuildCatalog $catalog,
        array $definition,
        int $seedStart,
        int $count,
        array $equipmentOverrides,
    ): array {
        $damagePerRound = [];
        $rounds = [];
        $healing = 0;
        $survived = 0;
        $abnormalSeeds = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $seed = $seedStart + $offset;
            $result = $this->model->fight(
                $catalog,
                (string) ($definition['build_key'] ?? ''),
                (string) ($definition['enemy_key'] ?? ''),
                (string) ($definition['tier_key'] ?? ''),
                $seed,
                (int) ($definition['max_rounds'] ?? 0),
                null,
                $equipmentOverrides,
            );
            $damagePerRound[] = $result->damageDealt / max(1, $result->rounds);
            $rounds[] = $result->rounds;
            $healing += $result->effectiveHealing;
            $survived += $result->playerRemainingHp > 0 ? 1 : 0;
            if ($result->abnormalState !== [] && count($abnormalSeeds) < 10) {
                $abnormalSeeds[] = ['seed' => $seed, 'states' => $result->abnormalState];
            }
        }
        sort($damagePerRound, SORT_NUMERIC);
        sort($rounds, SORT_NUMERIC);

        return [
            'iterations' => $count,
            'mean_damage_per_round' => round(array_sum($damagePerRound) / $count, 3),
            'median_damage_per_round' => round($this->percentile($damagePerRound, 50), 3),
            'median_rounds' => (int) $this->percentile($rounds, 50),
            'survival_rate' => $this->ratio($survived, $count),
            'effective_healing_average' => round($healing / $count, 3),
            'abnormal_seeds' => $abnormalSeeds,
        ];
    }

    /** @param array<string, mixed> $aggregate */
    private function equipmentScore(array $aggregate): int
    {
        return ((int) $aggregate['weapon_power'] * 10)
            + ((int) $aggregate['physical_defense'] * 4)
            + ((int) $aggregate['magical_defense'] * 4)
            + (int) $aggregate['max_hp']
            + (array_sum($aggregate['stats']) * 8)
            + array_sum(array_filter($aggregate['modifiers'], 'is_int'));
    }

    /**
     * @param  list<mixed>  $groups
     * @return list<array<string, mixed>>
     */
    private function collectAbnormalSeeds(array $groups): array
    {
        $result = [];
        $walk = function (mixed $value) use (&$walk, &$result): void {
            if (count($result) >= 10 || ! is_array($value)) {
                return;
            }
            if (isset($value['abnormal_seeds']) && is_array($value['abnormal_seeds'])) {
                foreach ($value['abnormal_seeds'] as $row) {
                    if (count($result) >= 10) {
                        return;
                    }
                    $result[] = $row;
                }

                return;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        foreach ($groups as $group) {
            $walk($group);
        }

        return $result;
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestContents(array $manifest, string $contents, string $hash): void
    {
        if (! hash_equals($hash, hash('sha256', $contents))) {
            throw new InvalidArgumentException('Underground alpha-v1 manifest hash does not match its contents.');
        }
        try {
            $embedded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Embedded Underground alpha-v1 manifest is invalid JSON.', 0, $exception);
        }
        if ($embedded !== $manifest) {
            throw new InvalidArgumentException('Embedded Underground alpha-v1 manifest does not match the simulation input.');
        }
    }

    private function assertSeedRange(int $start, int $count): void
    {
        if ($start < 0 || $count < 1 || $start > 2_147_483_647 - ($count - 1)) {
            throw new InvalidArgumentException('Underground alpha-v1 seed range is invalid.');
        }
    }

    /** @param array<string, mixed> $values */
    private function requiredInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("Underground alpha-v1 manifest [{$key}] must be positive.");
        }

        return $value;
    }

    /** @param list<int|float> $sorted */
    private function percentile(array $sorted, int $percentile): int|float
    {
        $index = max(0, (int) ceil(($percentile / 100) * count($sorted)) - 1);

        return $sorted[$index];
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return round($numerator / $denominator, 6);
    }
}
