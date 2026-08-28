<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatEngine;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use InvalidArgumentException;

final readonly class UndergroundBalanceSimulator
{
    public function __construct(
        private UndergroundCombatRules $rules,
        private UndergroundCombatEngine $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function run(
        array $manifest,
        string $manifestHash,
        string $manifestPath,
        string $commitSha,
        ?bool $workingTreeDirty,
        ?int $seedStartOverride = null,
        ?int $countOverride = null,
        ?string $scenarioFilter = null,
    ): array {
        $normalized = $this->normalizeManifest($manifest);
        $seedStart = $seedStartOverride ?? $normalized['seed_start'];
        $count = $countOverride ?? $normalized['count'];
        $this->assertSeedRange($seedStart, $count);
        $scenarios = $normalized['scenarios'];
        if ($scenarioFilter !== null) {
            $scenarios = array_values(array_filter(
                $scenarios,
                static fn (array $scenario): bool => $scenario['id'] === $scenarioFilter,
            ));
            if ($scenarios === []) {
                throw new InvalidArgumentException("Unknown Underground scenario [{$scenarioFilter}].");
            }
        }

        $scenarioReports = [];
        $experimentThresholdResults = [];
        foreach ($scenarios as $scenario) {
            $report = $this->simulateScenario(
                $scenario,
                $seedStart,
                $count,
                $normalized['max_rounds'],
                $manifestPath,
            );
            $scenarioReports[] = $report;
            if ($report['experiment_thresholds_passed'] !== null) {
                $experimentThresholdResults[] = $report['experiment_thresholds_passed'];
            }
        }
        $semanticObservations = $this->semanticObservations($scenarioReports);
        $semanticContractPassed = $semanticObservations === []
            ? null
            : ! in_array(false, array_column($semanticObservations, 'passed'), true);
        $abnormalContractPassed = ! in_array(
            false,
            array_map(
                static fn (array $report): bool => $report['metrics']['abnormal_rate'] === 0.0,
                $scenarioReports,
            ),
            true,
        );

        return [
            'schema_version' => 1,
            'simulator_version' => UndergroundCombatRules::SIMULATOR_VERSION,
            'rules_identity' => UndergroundCombatRules::IDENTITY,
            'commit_sha' => $commitSha,
            'working_tree_dirty' => $workingTreeDirty,
            'manifest_path' => $manifestPath,
            'manifest_hash' => $manifestHash,
            'seed_range' => [
                'start' => $seedStart,
                'count' => $count,
                'end' => $seedStart + $count - 1,
            ],
            'max_rounds' => $normalized['max_rounds'],
            'laboratory_contract_passed' => $abnormalContractPassed
                && $semanticContractPassed !== false,
            'semantic_contract_passed' => $semanticContractPassed,
            'semantic_observations' => $semanticObservations,
            'experiment_thresholds_passed' => $experimentThresholdResults === []
                ? null
                : ! in_array(false, $experimentThresholdResults, true),
            'scenarios' => $scenarioReports,
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
        foreach ($normalized['scenarios'] as $scenario) {
            if ($scenario['id'] !== $scenarioId) {
                continue;
            }

            return [
                'schema_version' => 1,
                'simulator_version' => UndergroundCombatRules::SIMULATOR_VERSION,
                'scenario_id' => $scenarioId,
                'result' => $this->fight($scenario, $seed, $normalized['max_rounds'])->toArray(),
            ];
        }

        throw new InvalidArgumentException("Unknown Underground scenario [{$scenarioId}].");
    }

    /**
     * @param array{
     *     id: string,
     *     actor_key: string,
     *     weapon_key: string,
     *     skill_keys: list<string>,
     *     enemy_key: string,
     *     ai_preset: string,
     *     experiment_thresholds: array<string, float|int>|null,
     *     laboratory_observation: array<string, mixed>|null
     * } $scenario
     * @return array<string, mixed>
     */
    private function simulateScenario(
        array $scenario,
        int $seedStart,
        int $count,
        int $maxRounds,
        string $manifestPath,
    ): array {
        $wins = 0;
        $losses = 0;
        $stalemates = 0;
        $damageDealt = 0;
        $damageReceived = 0;
        $normalAttackUsage = 0;
        $defendUsage = 0;
        $fallbackUsage = 0;
        $enemyFirstActions = 0;
        $enemyTelegraphs = 0;
        $enemyHeavyStrikes = 0;
        $guardedEnemyHeavyStrikes = 0;
        $resourceOverflow = 0;
        $resourceOverflowFights = 0;
        $unusedResourceFights = 0;
        $rounds = [];
        $skillUsage = array_fill_keys($scenario['skill_keys'], 0);
        $abnormalSeeds = [];
        $abnormalCount = 0;

        for ($offset = 0; $offset < $count; $offset++) {
            $seed = $seedStart + $offset;
            $result = $this->fight($scenario, $seed, $maxRounds);
            match ($result->winner) {
                'player' => $wins++,
                'enemy' => $losses++,
                default => $stalemates++,
            };
            $rounds[] = $result->rounds;
            $damageDealt += $result->damageDealt;
            $damageReceived += $result->damageReceived;
            $normalAttackUsage += $result->normalAttackUsage;
            $defendUsage += $result->defendUsage;
            $fallbackUsage += $result->aiFallbackUsage;
            $resourceOverflow += $result->resourceOverflow;
            $resourceOverflowFights += $result->resourceOverflow > 0 ? 1 : 0;
            $unusedResourceFights += $result->finalResource >= UndergroundCombatRules::RESOURCE_CAP ? 1 : 0;
            foreach ($result->skillUsage as $skillKey => $usage) {
                $skillUsage[$skillKey] += $usage;
            }
            if (($result->actionLog[0]['side'] ?? null) === 'enemy') {
                $enemyFirstActions++;
            }
            foreach ($result->actionLog as $row) {
                if ($row['side'] !== 'enemy') {
                    continue;
                }
                if ($row['action'] === 'telegraph') {
                    $enemyTelegraphs++;
                }
                if ($row['action'] === 'enemy_heavy_strike') {
                    $enemyHeavyStrikes++;
                    $guardedEnemyHeavyStrikes += $row['guarded'] === true ? 1 : 0;
                }
            }
            if ($result->abnormalState !== []) {
                $abnormalCount++;
                if (count($abnormalSeeds) < 10) {
                    $abnormalSeeds[] = [
                        'seed' => $seed,
                        'states' => $result->abnormalState,
                        'reproduction_command' => $this->reproductionCommand($manifestPath, $scenario['id'], $seed),
                    ];
                }
            }
        }

        sort($rounds, SORT_NUMERIC);
        $metrics = [
            'win_rate' => $this->ratio($wins, $count),
            'loss_rate' => $this->ratio($losses, $count),
            'stalemate_rate' => $this->ratio($stalemates, $count),
            'median_rounds' => $this->percentile($rounds, 50),
            'p90_rounds' => $this->percentile($rounds, 90),
            'p99_rounds' => $this->percentile($rounds, 99),
            'average_damage_dealt' => round($damageDealt / $count, 3),
            'average_damage_received' => round($damageReceived / $count, 3),
            'skill_usage' => $skillUsage,
            'normal_attack_usage' => $normalAttackUsage,
            'defend_usage' => $defendUsage,
            'ai_fallback_usage' => $fallbackUsage,
            'enemy_first_action_rate' => $this->ratio($enemyFirstActions, $count),
            'enemy_telegraph_usage' => $enemyTelegraphs,
            'enemy_heavy_strike_usage' => $enemyHeavyStrikes,
            'guarded_enemy_heavy_strike_usage' => $guardedEnemyHeavyStrikes,
            'resource_overflow_units' => $resourceOverflow,
            'resource_overflow_fight_rate' => $this->ratio($resourceOverflowFights, $count),
            'unused_resource_rate' => $this->ratio($unusedResourceFights, $count),
            'unused_skills' => array_keys(array_filter($skillUsage, static fn (int $usage): bool => $usage === 0)),
            'abnormal_rate' => $this->ratio($abnormalCount, $count),
        ];
        $violations = $scenario['experiment_thresholds'] === null
            ? []
            : $this->thresholdViolations($metrics, $scenario['experiment_thresholds']);

        return [
            'id' => $scenario['id'],
            'party' => [[
                'actor_key' => $scenario['actor_key'],
                'weapon_key' => $scenario['weapon_key'],
                'skill_keys' => $scenario['skill_keys'],
            ]],
            'enemy_composition' => [['enemy_key' => $scenario['enemy_key']]],
            'ai_preset' => $scenario['ai_preset'],
            'iterations' => $count,
            'laboratory_observation' => $scenario['laboratory_observation'],
            'experiment_thresholds' => $scenario['experiment_thresholds'],
            'experiment_thresholds_passed' => $scenario['experiment_thresholds'] === null
                ? null
                : $violations === [],
            'threshold_violations' => $violations,
            'metrics' => $metrics,
            'abnormal_seeds' => $abnormalSeeds,
            'reproduction_command_template' => $this->reproductionCommand(
                $manifestPath,
                $scenario['id'],
                $seedStart,
            ),
        ];
    }

    /**
     * @param array{
     *     id: string,
     *     actor_key: string,
     *     weapon_key: string,
     *     skill_keys: list<string>,
     *     enemy_key: string,
     *     ai_preset: string,
     *     experiment_thresholds: array<string, float|int>|null,
     *     laboratory_observation: array<string, mixed>|null
     * } $scenario
     */
    private function fight(array $scenario, int $seed, int $maxRounds): CombatResult
    {
        return $this->engine->fight(
            $scenario['actor_key'],
            $scenario['skill_keys'],
            $scenario['enemy_key'],
            $scenario['ai_preset'],
            $seed,
            $maxRounds,
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     seed_start: int,
     *     count: int,
     *     max_rounds: int,
     *     scenarios: list<array{
     *         id: string,
     *         actor_key: string,
     *         weapon_key: string,
     *         skill_keys: list<string>,
     *         enemy_key: string,
     *         ai_preset: string,
     *         experiment_thresholds: array<string, float|int>|null,
     *         laboratory_observation: array<string, mixed>|null
     *     }>
     * }
     */
    private function normalizeManifest(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Underground balance manifest schema_version must be 1.');
        }
        if (($manifest['rules_identity'] ?? null) !== UndergroundCombatRules::IDENTITY) {
            throw new InvalidArgumentException('Underground balance manifest rules identity is unsupported.');
        }
        $seedRange = $manifest['seed_range'] ?? null;
        if (! is_array($seedRange)) {
            throw new InvalidArgumentException('Underground balance manifest requires seed_range.');
        }
        $seedStart = $seedRange['start'] ?? null;
        $count = $seedRange['count'] ?? null;
        $maxRounds = $manifest['max_rounds'] ?? null;
        if (! is_int($seedStart) || ! is_int($count) || ! is_int($maxRounds)) {
            throw new InvalidArgumentException('Underground seed range and max rounds must be integers.');
        }
        $this->assertSeedRange($seedStart, $count);
        $this->rules->assertMaxRounds($maxRounds);
        $rawScenarios = $manifest['scenarios'] ?? null;
        if (! is_array($rawScenarios) || ! array_is_list($rawScenarios) || $rawScenarios === []) {
            throw new InvalidArgumentException('Underground balance manifest requires a non-empty scenario list.');
        }

        $scenarios = [];
        $ids = [];
        foreach ($rawScenarios as $rawScenario) {
            if (! is_array($rawScenario)) {
                throw new InvalidArgumentException('Underground scenario must be an object.');
            }
            $id = $this->requiredString($rawScenario, 'id');
            if (isset($ids[$id])) {
                throw new InvalidArgumentException("Duplicate Underground scenario [{$id}].");
            }
            $ids[$id] = true;
            $party = $rawScenario['party'] ?? null;
            $enemies = $rawScenario['enemy_composition'] ?? null;
            if (! is_array($party) || ! array_is_list($party) || count($party) !== 1 || ! is_array($party[0] ?? null)) {
                throw new InvalidArgumentException("Scenario [{$id}] must use exactly one Secretary actor in alpha-v0.");
            }
            if (! is_array($enemies) || ! array_is_list($enemies) || count($enemies) !== 1 || ! is_array($enemies[0] ?? null)) {
                throw new InvalidArgumentException("Scenario [{$id}] must use exactly one enemy in alpha-v0.");
            }
            $actorKey = $this->requiredString($party[0], 'actor_key');
            $weaponKey = $this->requiredString($party[0], 'weapon_key');
            $skillKeys = $party[0]['skill_keys'] ?? null;
            if (! is_array($skillKeys) || ! array_is_list($skillKeys)) {
                throw new InvalidArgumentException("Scenario [{$id}] skill_keys must be a list.");
            }
            $this->rules->assertLoadout($skillKeys);
            $actor = $this->rules->actor($actorKey);
            if ($weaponKey !== $actor['weapon_key']) {
                throw new InvalidArgumentException("Scenario [{$id}] must use the alpha-v0 starter knife.");
            }
            $enemyKey = $this->requiredString($enemies[0], 'enemy_key');
            $this->rules->enemy($enemyKey);
            $aiPreset = $this->requiredString($rawScenario, 'ai_preset');
            if ($aiPreset !== UndergroundCombatRules::AI_PRESET) {
                throw new InvalidArgumentException("Scenario [{$id}] AI preset is unsupported.");
            }
            $experimentThresholds = $this->experimentThresholds($rawScenario['acceptance'] ?? null, $id);
            $laboratoryObservation = $this->laboratoryObservation(
                $rawScenario['laboratory_observation'] ?? null,
                $id,
            );
            $scenarios[] = [
                'id' => $id,
                'actor_key' => $actorKey,
                'weapon_key' => $weaponKey,
                'skill_keys' => $skillKeys,
                'enemy_key' => $enemyKey,
                'ai_preset' => $aiPreset,
                'experiment_thresholds' => $experimentThresholds,
                'laboratory_observation' => $laboratoryObservation,
            ];
        }

        return [
            'seed_start' => $seedStart,
            'count' => $count,
            'max_rounds' => $maxRounds,
            'scenarios' => $scenarios,
        ];
    }

    /** @return array<string, float|int>|null */
    private function experimentThresholds(mixed $value, string $scenarioId): ?array
    {
        if ($value === null) {
            return null;
        }
        $keys = [
            'win_rate_min',
            'win_rate_max',
            'median_rounds_min',
            'median_rounds_max',
            'stalemate_rate_max',
            'abnormal_rate_max',
        ];
        if (! is_array($value)
            || array_diff($keys, array_keys($value)) !== []
            || array_diff(array_keys($value), $keys) !== []) {
            throw new InvalidArgumentException("Scenario [{$scenarioId}] experiment threshold keys are invalid.");
        }
        foreach ($value as $key => $threshold) {
            if (! is_int($threshold) && ! is_float($threshold)) {
                throw new InvalidArgumentException("Scenario [{$scenarioId}] threshold [{$key}] must be numeric.");
            }
        }
        if ($value['win_rate_min'] < 0 || $value['win_rate_max'] > 1
            || $value['win_rate_min'] > $value['win_rate_max']
            || $value['stalemate_rate_max'] < 0 || $value['stalemate_rate_max'] > 1
            || $value['abnormal_rate_max'] < 0 || $value['abnormal_rate_max'] > 1
            || $value['median_rounds_min'] < 1
            || $value['median_rounds_min'] > $value['median_rounds_max']) {
            throw new InvalidArgumentException("Scenario [{$scenarioId}] experiment thresholds are inconsistent.");
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function laboratoryObservation(mixed $value, string $scenarioId): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)
            || ($value['classification'] ?? null) !== 'alpha_v0_initial_observation'
            || ! is_int($value['seed_start'] ?? null)
            || ! is_int($value['seed_count'] ?? null)
            || ! is_float($value['observed_win_rate'] ?? null)
            || ! is_array($value['provisional_win_rate_range'] ?? null)
            || ($value['is_player_facing_balance_target'] ?? null) !== false
            || ($value['future_retuning_allowed'] ?? null) !== true) {
            throw new InvalidArgumentException("Scenario [{$scenarioId}] laboratory observation is invalid.");
        }
        $range = $value['provisional_win_rate_range'];
        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;
        if ((! is_int($min) && ! is_float($min))
            || (! is_int($max) && ! is_float($max))
            || $min < 0
            || $max > 1
            || $min > $max
            || $value['observed_win_rate'] < 0
            || $value['observed_win_rate'] > 1
            || $value['seed_start'] < 0
            || $value['seed_count'] < 1) {
            throw new InvalidArgumentException("Scenario [{$scenarioId}] laboratory observation values are inconsistent.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || $value === '' || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException("Underground manifest [{$key}] must be a stable key.");
        }

        return $value;
    }

    private function assertSeedRange(int $seedStart, int $count): void
    {
        if ($seedStart < 0 || $count < 1 || $seedStart > 2_147_483_647 - ($count - 1)) {
            throw new InvalidArgumentException('Underground simulation seed range must fit non-negative signed 32-bit integers.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $scenarioReports
     * @return list<array<string, mixed>>
     */
    private function semanticObservations(array $scenarioReports): array
    {
        $byId = [];
        foreach ($scenarioReports as $report) {
            $byId[$report['id']] = $report;
        }

        $observations = [];
        if (isset($byId['standard_enemy'], $byId['fast_enemy'])) {
            $standardRate = $byId['standard_enemy']['metrics']['enemy_first_action_rate'];
            $fastRate = $byId['fast_enemy']['metrics']['enemy_first_action_rate'];
            $observations[] = [
                'id' => 'fast_has_initiative_advantage_over_standard',
                'passed' => $fastRate > $standardRate,
                'standard_enemy_first_action_rate' => $standardRate,
                'fast_enemy_first_action_rate' => $fastRate,
            ];
        }
        if (isset($byId['standard_enemy'], $byId['armored_enemy'])) {
            $standardRounds = $byId['standard_enemy']['metrics']['median_rounds'];
            $armoredRounds = $byId['armored_enemy']['metrics']['median_rounds'];
            $observations[] = [
                'id' => 'armored_is_more_durable_than_standard',
                'passed' => $armoredRounds > $standardRounds,
                'standard_median_rounds' => $standardRounds,
                'armored_median_rounds' => $armoredRounds,
            ];
        }
        if (isset($byId['telegraphed_threat'])) {
            $metrics = $byId['telegraphed_threat']['metrics'];
            $heavyStrikes = $metrics['enemy_heavy_strike_usage'];
            $guardedHeavyStrikes = $metrics['guarded_enemy_heavy_strike_usage'];
            $observations[] = [
                'id' => 'telegraphed_heavy_strikes_are_observed_and_guarded',
                'passed' => $metrics['enemy_telegraph_usage'] > 0
                    && $heavyStrikes > 0
                    && $guardedHeavyStrikes === $heavyStrikes,
                'telegraph_usage' => $metrics['enemy_telegraph_usage'],
                'heavy_strike_usage' => $heavyStrikes,
                'guarded_heavy_strike_usage' => $guardedHeavyStrikes,
            ];
        }

        return $observations;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, float|int>  $acceptance
     * @return list<string>
     */
    private function thresholdViolations(array $metrics, array $acceptance): array
    {
        $violations = [];
        $minimums = [
            'win_rate' => 'win_rate_min',
            'median_rounds' => 'median_rounds_min',
        ];
        foreach ($minimums as $metricKey => $thresholdKey) {
            $metric = $metrics[$metricKey];
            $threshold = $acceptance[$thresholdKey];
            if ($metric < $threshold) {
                $violations[] = "{$metricKey}={$metric} violates {$thresholdKey}={$threshold}";
            }
        }
        $maximums = [
            'win_rate' => 'win_rate_max',
            'median_rounds' => 'median_rounds_max',
            'stalemate_rate' => 'stalemate_rate_max',
            'abnormal_rate' => 'abnormal_rate_max',
        ];
        foreach ($maximums as $metricKey => $thresholdKey) {
            $metric = $metrics[$metricKey];
            $threshold = $acceptance[$thresholdKey];
            if ($metric > $threshold) {
                $violations[] = "{$metricKey}={$metric} violates {$thresholdKey}={$threshold}";
            }
        }

        return $violations;
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, int $percentile): int
    {
        $index = max(0, (int) ceil(($percentile / 100) * count($sorted)) - 1);

        return $sorted[$index];
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return round($numerator / $denominator, 6);
    }

    private function reproductionCommand(string $manifestPath, string $scenarioId, int $seed): string
    {
        return "php artisan underground:balance --manifest={$manifestPath} --scenario={$scenarioId} --replay-seed={$seed}";
    }
}
