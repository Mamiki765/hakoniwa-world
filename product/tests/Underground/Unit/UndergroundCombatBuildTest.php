<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1BattleProjector;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\BuildCombatState;
use App\Domain\Underground\Combat\CanonicalCombatOrchestrator;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\PriorityCombatAi;
use App\Domain\Underground\Combat\PriorityCombatAiConfiguration;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
use App\Domain\Underground\Combat\UndergroundRandom;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UndergroundCombatBuildTest extends TestCase
{
    public function test_manifest_fixes_five_stats_tree_budget_active_slots_and_cross_tree_weapon_builds(): void
    {
        [$manifest, $catalog, $validator] = $this->catalog();

        $this->assertSame(AlphaV1CombatRules::STATS, $manifest['base_stats']);
        $this->assertSame(AlphaV1CombatRules::TREES, $manifest['skill_tree_keys']);
        $this->assertSame(120, $manifest['balance']['build_point_budget']);
        $this->assertSame(5, $manifest['balance']['active_skill_limit']);
        $this->assertSame(AlphaV1CombatRules::TARGETING_IDENTITY, $manifest['targeting_contract']['identity']);
        $this->assertSame([
            'key' => 'taunt',
            'label' => '挑発',
            'duration' => 'battle',
            'targeting_scope' => 'default_hostile_single_target',
            'overrides_explicit_targeting' => false,
            'latest_source_wins' => true,
            'invalid_source_fallback' => 'normal_target_selection',
        ], $manifest['targeting_contract']['taunt']);
        foreach (['shield_bash', 'bulwark_strike', 'unbroken_retort'] as $skillKey) {
            $this->assertSame(
                ['type' => 'taunt', 'target' => 'enemy', 'target_scope' => 'single_enemy'],
                $manifest['skills'][$skillKey]['effects'][0],
            );
        }
        foreach ($catalog->buildKeys() as $buildKey) {
            $build = $validator->validate($catalog, $buildKey);
            $this->assertLessThanOrEqual($manifest['balance']['build_point_budget'], $build['points_spent']);
            $this->assertCount(5, $build['active_skills']);
        }

        $allTreePoints = 0;
        foreach (AlphaV1CombatRules::TREES as $treeKey) {
            $allocation = $validator->fullTreeAllocation($catalog, $treeKey);
            $points = 0;
            foreach ($allocation as $nodeKey => $rank) {
                $node = $catalog->node($nodeKey)['node'];
                $points += $rank * $node['point_cost_per_rank'];
            }
            $this->assertGreaterThan(100, $points);
            $allTreePoints += $points;
        }
        $this->assertSame($manifest['balance']['all_tree_points'], $allTreePoints);
        $this->assertGreaterThan($manifest['balance']['build_point_budget'], $allTreePoints);
        $this->assertSame('rapier', $validator->validate($catalog, 'balanced')['weapon_style']);
        $this->assertContains('mending_prayer', $validator->validate($catalog, 'balanced')['active_skills']);

        $missingPrerequisite = $manifest;
        unset($missingPrerequisite['builds']['pure_attacker']['allocations']['martial_precision_cut']);
        try {
            $validator->validate(new AlphaV1BuildCatalog($missingPrerequisite), 'pure_attacker');
            $this->fail('A child skill requires its prerequisite.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('prerequisite', $exception->getMessage());
        }

        $duplicateEquipment = $manifest;
        $duplicateEquipment['builds']['pure_attacker']['equipment'][] =
            $duplicateEquipment['builds']['pure_attacker']['equipment'][0];
        try {
            $validator->validate(new AlphaV1BuildCatalog($duplicateEquipment), 'pure_attacker');
            $this->fail('One build must not equip the same single-capacity slot twice.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('equipment loadout is invalid', $exception->getMessage());
        }
    }

    public function test_same_build_equipment_and_seed_replay_exactly(): void
    {
        [$manifest, $catalog] = $this->catalog();
        $first = $this->model()->fight($catalog, 'balanced', 'pressure_construct', 'early', 37, 12);
        $retry = $this->model()->fight($catalog, 'balanced', 'pressure_construct', 'early', 37, 12);
        $different = $this->model()->fight($catalog, 'balanced', 'pressure_construct', 'early', 38, 12);

        $this->assertSame($first->toArray(), $retry->toArray());
        $this->assertNotSame($first->actionLog, $different->actionLog);
        $this->assertSame(AlphaV1CombatRules::IDENTITY, $first->rulesIdentity);
        $this->assertSame(AlphaV1CombatRules::GENERATOR_IDENTITY, $first->generatorIdentity);
        $this->assertSame($manifest['generator_identity'], $first->generatedEquipment[0]['generator_identity']);
        $this->assertSame($first->initialState, $retry->initialState);
        $this->assertSame(10_000, $first->initialState['player']['mp']);
        $this->assertSame(0, $first->initialState['player']['awakening_gauge']);

        $projection = (new UndergroundAlphaV1BattleProjector)->project($first, $catalog);
        $this->assertSame($projection['initial_state'], $projection['rounds'][0]['start_state']);
        foreach (array_slice($projection['rounds'], 1) as $index => $round) {
            $this->assertSame($projection['rounds'][$index]['end_state'], $round['start_state']);
        }
    }

    public function test_level_one_standard_hp_is_500_and_mp_never_scales_or_leaves_fixed_bounds(): void
    {
        $rules = new AlphaV1CombatRules;
        $standard = array_combine(AlphaV1CombatRules::STATS, array_fill(0, 5, 20));
        $this->assertIsArray($standard);
        $this->assertSame(500, $rules->maxHp($standard, $rules->progressionScaleBps(1, 1)));
        $this->assertSame(10_000, AlphaV1CombatRules::MAX_MP);

        [, $catalog] = $this->catalog();
        foreach (['early', 'mid', 'late'] as $tier) {
            $result = $this->model()->fight($catalog, 'pure_attacker', 'pressure_construct', $tier, 5, 8);
            foreach ($result->mpHistory as $row) {
                $this->assertGreaterThanOrEqual(0, $row['after']);
                $this->assertLessThanOrEqual(10_000, $row['after']);
            }
        }
    }

    public function test_level_scaling_rejects_only_non_positive_or_unrepresentable_values(): void
    {
        $rules = new AlphaV1CombatRules;

        $this->assertSame(1_137_700, $rules->progressionScaleBps(1_254, 90));
        $this->assertSame(11_295_100, $rules->storyBenchmarkScaleBps(12_540));

        $invalidCalculations = [
            fn () => $rules->progressionScaleBps(0, 1),
            fn () => $rules->progressionScaleBps(10_248_191_152_060_851, 1),
            fn () => $rules->progressionScaleBps(PHP_INT_MAX, 1),
            fn () => $rules->storyBenchmarkScaleBps(0),
            fn () => $rules->storyBenchmarkScaleBps(PHP_INT_MAX),
        ];

        foreach ($invalidCalculations as $calculate) {
            try {
                $calculate();
                $this->fail('An invalid or unrepresentable level should be rejected.');
            } catch (InvalidArgumentException) {
                // Expected invalid input.
            }
        }
    }

    public function test_combat_value_scaling_rejects_an_actual_operand_product_that_cannot_fit_an_integer(): void
    {
        $rules = new AlphaV1CombatRules;
        $scaleBps = $rules->progressionScaleBps(2_147_483_647, 1);

        $this->assertSame(1_932_735_291_400, $scaleBps);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scaled combat value exceeds the supported integer range');

        $rules->scaledCombatValue(100_000_000, $scaleBps);
    }

    public function test_weighted_multi_stat_power_and_ratio_defense_keep_damage_legal_under_inflation(): void
    {
        $rules = new AlphaV1CombatRules;
        $this->assertSame(130, $rules->weightedStats(
            ['vitality' => 100, 'might' => 200, 'finesse' => 50, 'spirit' => 40, 'agility' => 20],
            ['might' => 6000, 'finesse' => 2000],
        ));

        [$manifest] = $this->catalog();
        $lowDefense = $manifest;
        $lowDefense['enemies']['pressure_construct']['physical_defense'] = 0;
        $highDefense = $manifest;
        $highDefense['enemies']['pressure_construct']['physical_defense'] = 1_000_000;
        $low = $this->model()->fight(new AlphaV1BuildCatalog($lowDefense), 'pure_attacker', 'pressure_construct', 'early', 11, 1);
        $high = $this->model()->fight(new AlphaV1BuildCatalog($highDefense), 'pure_attacker', 'pressure_construct', 'early', 11, 1);

        $this->assertGreaterThan($high->damageDealt, $low->damageDealt);
        $this->assertGreaterThanOrEqual((int) floor($low->damageDealt * 0.24), $high->damageDealt);
        $this->assertSame([], $high->abnormalState);

        $unreducedCounter = $manifest;
        $unreducedCounter['enemies']['crystal_warden']['modifiers']['damage_taken_reduction_bps'] = 0;
        $reducedCounter = $manifest;
        $reducedCounter['enemies']['crystal_warden']['modifiers']['damage_taken_reduction_bps'] = 5_000;
        $counterDamage = function (array $candidate): int {
            $result = $this->model()->fight(
                new AlphaV1BuildCatalog($candidate), 'pure_tank', 'crystal_warden', 'early', 6, 30,
            );
            $counters = array_values(array_filter(
                $result->actionLog,
                static fn (array $row): bool => $row['action'] === 'counter',
            ));
            $this->assertNotEmpty($counters);

            return $counters[0]['amount'];
        };
        $this->assertGreaterThan($counterDamage($reducedCounter), $counterDamage($unreducedCounter));

        $lethal = $manifest;
        $lethal['builds']['pure_attacker']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'normal_attack',
        ]];
        $lethal['skills']['pressure_strike']['effects'] = [[
            'type' => 'damage',
            'target' => 'enemy',
            'category' => 'physical',
            'potency_bps' => 10_000,
            'stat_coefficients' => [],
            'weapon_coefficient_bps' => 0,
            'fixed' => 10_000,
            'target_max_hp_bps' => 0,
            'can_crit' => false,
            'dodgeable' => false,
            'hits' => 1,
        ]];
        $lethal['enemies']['pressure_construct']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:pressure_strike',
        ]];
        $survivable = $lethal;
        $survivable['equipment']['slots']['armor']['max_hp'] = 20_000;
        $overkill = $this->model()->fight(
            new AlphaV1BuildCatalog($lethal), 'pure_attacker', 'pressure_construct', 'early', 7, 1,
        );
        $nonlethal = $this->model()->fight(
            new AlphaV1BuildCatalog($survivable), 'pure_attacker', 'pressure_construct', 'early', 7, 1,
        );
        $this->assertSame(0, $overkill->playerRemainingHp);
        $this->assertGreaterThan(0, $nonlethal->playerRemainingHp);
        $this->assertSame(0, $overkill->damagePrevented);
        $this->assertGreaterThan($overkill->damagePrevented, $nonlethal->damagePrevented);

        $retaliation = $manifest;
        $retaliation['builds']['pure_tank']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'defend',
        ]];
        $retaliation['enemies']['pressure_construct']['max_hp'] = 1;
        $retaliation['enemies']['pressure_construct']['base_stats'] = [
            'vitality' => 25, 'might' => 25, 'finesse' => 24, 'spirit' => 25, 'agility' => 1,
        ];
        $retaliation['enemies']['pressure_construct']['skills'] = [];
        $retaliation['enemies']['pressure_construct']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'normal_attack',
        ]];
        $retaliation['enemies']['pressure_construct']['normal_attack']['fixed'] = 1;
        $retaliation['enemies']['pressure_construct']['normal_attack']['dodgeable'] = false;
        $retaliation['enemies']['pressure_construct']['normal_attack']['hits'] = 3;
        $retaliated = $this->model()->fight(
            new AlphaV1BuildCatalog($retaliation), 'pure_tank', 'pressure_construct', 'early', 9, 1,
        );
        $enemyHits = array_values(array_filter(
            $retaliated->actionLog,
            static fn (array $row): bool => $row['side'] === 'enemy' && $row['action'] === 'normal_attack',
        ));
        $this->assertSame('player', $retaliated->winner);
        $this->assertCount(1, $enemyHits);
    }

    public function test_relative_agility_curve_naturally_approaches_combo_and_evasion_limits_without_replacing_modifier_cap(): void
    {
        $rules = new AlphaV1CombatRules;
        $expected = [
            100 => [0, 0, 0, 0, 10_000, 10_000],
            120 => [145, 45, 0, 0, 10_045, 9_855],
            150 => [320, 88, 12, 0, 10_112, 9_680],
            200 => [533, 121, 45, 0, 10_211, 9_467],
            250 => [685, 145, 54, 15, 10_298, 9_315],
            300 => [800, 163, 57, 30, 10_367, 9_200],
        ];
        foreach ($expected as $selfAgility => $values) {
            $profile = $rules->agilityProfile($selfAgility, 100);
            $this->assertSame($values, [
                $profile['evasion_bonus_bps'],
                $profile['two_hit_rate_bps'],
                $profile['three_hit_rate_bps'],
                $profile['four_hit_rate_bps'],
                $profile['expected_damage_multiplier_bps'],
                $profile['expected_incoming_damage_multiplier_bps'],
            ]);
        }

        $extremeProfile = $rules->agilityProfile(1_000_000, 1);
        $this->assertSame([9_999, 1_599, 287, 83, 129, 10_840, 8_401], [
            $extremeProfile['relative_advantage_bps'],
            $extremeProfile['evasion_bonus_bps'],
            $extremeProfile['two_hit_rate_bps'],
            $extremeProfile['three_hit_rate_bps'],
            $extremeProfile['four_hit_rate_bps'],
            $extremeProfile['expected_damage_multiplier_bps'],
            $extremeProfile['expected_incoming_damage_multiplier_bps'],
        ]);
        $this->assertGreaterThan(
            $rules->agilityProfile(4, 1)['expected_damage_multiplier_bps'],
            $extremeProfile['expected_damage_multiplier_bps'],
        );
        $this->assertSame(500, $rules->evasionChanceBps(50, 100, 500));
        $this->assertSame(1_100, $rules->evasionChanceBps(300, 100, 300));
        $this->assertSame(0, $rules->evasionChanceBps(300, 100, -1_000));
        $this->assertSame(AlphaV1CombatRules::EVASION_CAP_BPS, $rules->evasionChanceBps(1_000_000, 1, 3_100));
    }

    public function test_agility_combo_multiplies_one_native_multi_hit_action_without_extra_events(): void
    {
        [$manifest] = $this->catalog();
        $manifest['normal_attack'] = [
            'type' => 'damage',
            'category' => 'physical',
            'potency_bps' => 10_000,
            'stat_coefficients' => ['might' => 10_000],
            'weapon_coefficient_bps' => 0,
            'fixed' => 0,
            'target_max_hp_bps' => 0,
            'can_crit' => false,
            'dodgeable' => false,
            'hits' => 3,
        ];
        $manifest['enemies']['agility_target'] = [
            'label' => '敏捷試験体',
            'boss' => false,
            'base_stats' => ['vitality' => 1, 'might' => 1, 'finesse' => 1, 'spirit' => 1, 'agility' => 100],
            'max_hp' => 1_000_000,
            'physical_defense' => 137,
            'magical_defense' => 0,
            'weapon_power' => 1,
            'normal_attack' => $manifest['normal_attack'],
            'skills' => [],
            'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'defend']],
            'modifiers' => [],
        ];
        $catalog = new AlphaV1BuildCatalog($manifest);
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $snapshot = [
            'key' => 'agility_secretary',
            'label' => '敏捷秘書',
            'stats' => ['vitality' => 20, 'might' => 40, 'finesse' => 20, 'spirit' => 20, 'agility' => 300],
            'active_skills' => [],
            'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']],
            'modifiers' => [],
            'equipment' => $configuration['exploration']['starter_weapon'],
        ];
        $baselineSnapshot = $snapshot;
        $baselineSnapshot['stats']['agility'] = 101;
        $combo = null;
        $baseline = null;
        foreach (range(0, 500) as $seed) {
            $candidate = $this->model()->fightPlayerSnapshot($catalog, $snapshot, 'agility_target', $seed, 1, 0);
            $candidateBaseline = $this->model()->fightPlayerSnapshot(
                $catalog,
                $baselineSnapshot,
                'agility_target',
                $seed,
                1,
                0,
            );
            if (collect($candidate->actionLog)->contains(fn (array $row): bool => isset($row['agility_combo_hits']))
                && ! collect($candidateBaseline->actionLog)->contains(fn (array $row): bool => isset($row['agility_combo_hits']))) {
                $combo = $candidate;
                $baseline = $candidateBaseline;
                break;
            }
        }
        $this->assertInstanceOf(BuildCombatResult::class, $combo);
        $this->assertInstanceOf(BuildCombatResult::class, $baseline);

        $comboRows = array_values(array_filter(
            $combo->actionLog,
            static fn (array $row): bool => ($row['side'] ?? null) === 'player'
                && ($row['effect_type'] ?? null) === 'damage',
        ));
        $baselineRows = array_values(array_filter(
            $baseline->actionLog,
            static fn (array $row): bool => ($row['side'] ?? null) === 'player'
                && ($row['effect_type'] ?? null) === 'damage',
        ));
        $comboNotices = array_values(array_filter(
            $comboRows,
            static fn (array $row): bool => isset($row['agility_combo_hits']),
        ));
        $comboHits = $comboNotices[0]['agility_combo_hits'];
        $this->assertContains($comboHits, [2, 3, 4]);
        $this->assertCount(3, $comboRows);
        $this->assertCount(3, $baselineRows);
        $this->assertCount(1, $comboNotices);
        $this->assertSame(1, $combo->actionUsage['normal_attack']);
        $this->assertSame(0, $combo->awakening['gauge_gained']);
        foreach ($comboRows as $index => $row) {
            $this->assertSame($baselineRows[$index]['amount'] * $comboHits, $row['amount']);
        }
        $projected = (new UndergroundAlphaV1BattleProjector)->project($combo, $catalog);
        $projectedComboNotices = collect($projected['rounds'])
            ->flatMap(static fn (array $round): array => $round['actions'])
            ->filter(static fn (array $action): bool => is_int($action['agility_combo_hits'] ?? null));
        $this->assertCount(1, $projectedComboNotices);
        $this->assertSame($comboHits, $projectedComboNotices->first()['agility_combo_hits']);

        $dodgeable = $manifest;
        $dodgeable['normal_attack']['dodgeable'] = true;
        $dodgeable['normal_attack']['hits'] = 1;
        $dodgeable['enemies']['agility_target']['modifiers']['evasion_bps'] = 3_500;
        $comboRateBps = array_sum(array_intersect_key(
            (new AlphaV1CombatRules)->agilityProfile(300, 100),
            array_flip(['two_hit_rate_bps', 'three_hit_rate_bps', 'four_hit_rate_bps']),
        ));
        $evadedComboSeed = null;
        foreach (range(0, 5_000) as $seed) {
            $random = new UndergroundRandom($seed);
            $comboRoll = $random->integer(
                'alpha-v1:agility-combo:agility_secretary:normal_attack:round:1',
                1,
                10_000,
            );
            $evasionRoll = $random->integer(
                'alpha-v1:evasion:agility_target:normal_attack:1',
                1,
                10_000,
            );
            if ($comboRoll <= $comboRateBps && $evasionRoll <= 3_500) {
                $evadedComboSeed = $seed;
                break;
            }
        }
        $this->assertIsInt($evadedComboSeed);
        $evadedCombo = $this->model()->fightPlayerSnapshot(
            new AlphaV1BuildCatalog($dodgeable),
            $snapshot,
            'agility_target',
            $evadedComboSeed,
            1,
            0,
        );
        $evadedRow = collect($evadedCombo->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'player'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($evadedRow);
        $this->assertTrue($evadedRow['evaded']);
        $this->assertArrayNotHasKey('agility_combo_hits', $evadedRow);

        $completeGuard = $dodgeable;
        $completeGuard['enemies']['agility_target']['modifiers'] = [
            'complete_guard_chance_bps' => 10_000,
        ];
        $completeGuardedCombo = $this->model()->fightPlayerSnapshot(
            new AlphaV1BuildCatalog($completeGuard),
            $snapshot,
            'agility_target',
            $evadedComboSeed,
            1,
            0,
        );
        $completeGuardedRow = collect($completeGuardedCombo->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'player'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($completeGuardedRow);
        $this->assertTrue($completeGuardedRow['complete_guarded']);
        $this->assertArrayNotHasKey('agility_combo_hits', $completeGuardedRow);
    }

    public function test_terminal_agility_combo_caps_mitigation_and_evasion_prevention_at_absorbable_damage(): void
    {
        [$manifest] = $this->catalog();
        $terminalAttack = [
            'type' => 'damage',
            'category' => 'physical',
            'potency_bps' => 10_000,
            'stat_coefficients' => [],
            'weapon_coefficient_bps' => 0,
            'fixed' => 500,
            'target_max_hp_bps' => 0,
            'can_crit' => false,
            'dodgeable' => false,
            'hits' => 1,
        ];
        $manifest['enemies']['terminal_combo_enemy'] = [
            'label' => '終端combo試験体',
            'boss' => false,
            'base_stats' => ['vitality' => 1, 'might' => 1, 'finesse' => 1, 'spirit' => 1, 'agility' => 300],
            'max_hp' => 1_000_000,
            'physical_defense' => 0,
            'magical_defense' => 0,
            'weapon_power' => 1,
            'normal_attack' => $terminalAttack,
            'skills' => [],
            'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']],
            'modifiers' => [],
        ];
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $snapshot = [
            'key' => 'terminal_combo_player',
            'label' => '低HP秘書',
            'stats' => ['vitality' => 20, 'might' => 20, 'finesse' => 20, 'spirit' => 20, 'agility' => 1],
            'current_hp' => 5,
            'active_skills' => [],
            'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']],
            'modifiers' => ['evasion_bps' => AlphaV1CombatRules::EVASION_CAP_BPS],
            'equipment' => $configuration['exploration']['starter_weapon'],
        ];
        $rules = new AlphaV1CombatRules;
        $profile = $rules->agilityProfile(300, 2);
        $comboRateBps = $profile['two_hit_rate_bps']
            + $profile['three_hit_rate_bps']
            + $profile['four_hit_rate_bps'];
        $comboEvasionSeed = null;
        foreach (range(0, 5_000) as $seed) {
            $random = new UndergroundRandom($seed);
            $comboRoll = $random->integer(
                'alpha-v1:agility-combo:terminal_combo_enemy:normal_attack:round:1',
                1,
                10_000,
            );
            $evasionRoll = $random->integer(
                'alpha-v1:evasion:terminal_combo_player:normal_attack:1',
                1,
                10_000,
            );
            if ($comboRoll <= $comboRateBps && $evasionRoll <= AlphaV1CombatRules::EVASION_CAP_BPS) {
                $comboEvasionSeed = $seed;

                break;
            }
        }
        $this->assertIsInt($comboEvasionSeed);

        $terminal = $this->model()->fightPlayerSnapshot(
            new AlphaV1BuildCatalog($manifest),
            $snapshot,
            'terminal_combo_enemy',
            $comboEvasionSeed,
            1,
            0,
        );
        $terminalRow = collect($terminal->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($terminalRow);
        $this->assertGreaterThan(1, $terminalRow['agility_combo_hits']);
        $this->assertSame('enemy', $terminal->winner);
        $this->assertSame(0, $terminal->playerRemainingHp);
        $this->assertSame(5, $terminal->damageReceived);
        $this->assertSame(0, $terminal->damagePrevented);

        $evasive = $manifest;
        $evasive['enemies']['terminal_combo_enemy']['normal_attack']['dodgeable'] = true;
        $evaded = $this->model()->fightPlayerSnapshot(
            new AlphaV1BuildCatalog($evasive),
            $snapshot,
            'terminal_combo_enemy',
            $comboEvasionSeed,
            1,
            0,
        );
        $evadedRow = collect($evaded->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($evadedRow);
        $this->assertTrue($evadedRow['evaded']);
        $this->assertArrayNotHasKey('agility_combo_hits', $evadedRow);
        $this->assertSame(5, $evaded->playerRemainingHp);
        $this->assertSame(0, $evaded->damageReceived);
        $this->assertSame(5, $evaded->damagePrevented);
    }

    public function test_shining_court_noble_defends_unless_its_one_percent_outrage_triggers(): void
    {
        [$manifest] = $this->catalog();
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $noble = $configuration['exploration']['grounds']['shining_kingdom']['rare_encounter']['encounter']['enemy'];
        $manifest['enemies']['shining_court_noble'] = $noble;
        $catalog = new AlphaV1BuildCatalog($manifest);
        $outrageSeed = null;
        $calmSeed = null;
        foreach (range(0, 10_000) as $seed) {
            $roll = (new UndergroundRandom($seed))->integer(
                'alpha-v1:outrage:shining_court_noble:1',
                1,
                10_000,
            );
            $outrageSeed ??= $roll <= 100 ? $seed : null;
            $calmSeed ??= $roll > 100 ? $seed : null;
            if ($outrageSeed !== null && $calmSeed !== null) {
                break;
            }
        }
        $this->assertIsInt($outrageSeed);
        $this->assertIsInt($calmSeed);

        $outrage = $this->model()->fight(
            $catalog,
            'balanced',
            'shining_court_noble',
            'early',
            $outrageSeed,
            1,
        );
        $calm = $this->model()->fight(
            $catalog,
            'balanced',
            'shining_court_noble',
            'early',
            $calmSeed,
            1,
        );
        $outrageAction = collect($outrage->actionLog)->firstWhere('side', 'enemy');
        $calmAction = collect($calm->actionLog)->firstWhere('side', 'enemy');
        $this->assertSame(['pressure_heavy', 'outrage_chance'], [
            $outrageAction['action_key'] ?? null,
            $outrageAction['reason'] ?? null,
        ]);
        $this->assertSame('defend', $calmAction['action_key'] ?? null);
    }

    public function test_heal_barrier_and_source_capped_periodic_damage_use_deterministic_status_timing(): void
    {
        [$manifest, $catalog] = $this->catalog();
        $manifest['enemies']['pressure_construct']['max_hp'] = 100_000;
        $manifest['enemies']['pressure_construct']['weapon_power'] = 500;
        $catalog = new AlphaV1BuildCatalog($manifest);
        $tank = $this->model()->fight($catalog, 'pure_tank', 'pressure_construct', 'early', 4, 20);
        $this->assertGreaterThan(0, $tank->effectiveHealing);
        $this->assertGreaterThan(0, $tank->damagePrevented);
        $this->assertGreaterThan(0, $tank->actionUsage['unbroken_retort']);
        $taunts = array_values(array_filter(
            $tank->actionLog,
            static fn (array $row): bool => $row['action'] === 'taunt',
        ));
        $this->assertNotEmpty($taunts);
        $this->assertSame(AlphaV1CombatRules::TARGETING_IDENTITY, $taunts[0]['targeting_identity']);
        $this->assertSame('default_hostile_single_target', $taunts[0]['targeting_scope']);
        $this->assertSame('battle', $taunts[0]['duration']);
        $this->assertFalse($taunts[0]['overrides_explicit_targeting']);
        $this->assertSame('pure_tank', $taunts[0]['source_actor_key']);
        $this->assertSame('pressure_construct', $taunts[0]['target_actor_key']);
        $this->assertNotEmpty(array_intersect(
            ['shield_bash', 'bulwark_strike', 'unbroken_retort', 'counter'],
            array_column($taunts, 'source_action'),
        ));

        $withoutSkillTaunt = $manifest;
        foreach (['shield_bash', 'bulwark_strike', 'unbroken_retort'] as $skillKey) {
            $withoutSkillTaunt['skills'][$skillKey]['effects'] = array_values(array_filter(
                $withoutSkillTaunt['skills'][$skillKey]['effects'],
                static fn (array $effect): bool => ($effect['type'] ?? null) !== 'taunt',
            ));
        }
        $withoutSkillTaunt = $this->model()->fight(
            new AlphaV1BuildCatalog($withoutSkillTaunt),
            'pure_tank',
            'pressure_construct',
            'early',
            4,
            20,
        );
        $this->assertSame([
            $withoutSkillTaunt->winner,
            $withoutSkillTaunt->rounds,
            $withoutSkillTaunt->playerRemainingHp,
            $withoutSkillTaunt->enemyRemainingHp,
            $withoutSkillTaunt->damageDealt,
            $withoutSkillTaunt->damageReceived,
            $withoutSkillTaunt->effectiveHealing,
            $withoutSkillTaunt->damagePrevented,
            $withoutSkillTaunt->finalMp,
        ], [
            $tank->winner,
            $tank->rounds,
            $tank->playerRemainingHp,
            $tank->enemyRemainingHp,
            $tank->damageDealt,
            $tank->damageReceived,
            $tank->effectiveHealing,
            $tank->damagePrevented,
            $tank->finalMp,
        ]);

        $attacker = $this->model()->fight($catalog, 'pure_attacker', 'crystal_warden', 'early', 2, 30);
        $appliedRounds = array_column(array_filter(
            $attacker->actionLog,
            static fn (array $row): bool => $row['action'] === 'status:bleed',
        ), 'round');
        $tickRows = array_values(array_filter(
            $attacker->actionLog,
            static fn (array $row): bool => $row['action'] === 'periodic_damage:bleed',
        ));
        $this->assertNotEmpty($appliedRounds);
        $this->assertNotEmpty($tickRows);
        $this->assertGreaterThan(min($appliedRounds), $tickRows[0]['round']);
        $this->assertLessThanOrEqual(60, max(array_column($tickRows, 'amount')));

        // Use a visible affix magnitude so integer rounding on a small early-game
        // bleed tick cannot hide the modifier this test is exercising.
        $manifest['equipment']['affixes']['periodic_effect']['minimum'] = 4000;
        $manifest['equipment']['affixes']['periodic_effect']['maximum'] = 4000;
        $manifest['equipment']['affixes']['periodic_effect']['cap'] = 4000;
        $withPeriodicAffix = $this->model()->fight(new AlphaV1BuildCatalog($manifest), 'balanced', 'crystal_warden', 'early', 2, 30);
        $withoutPeriodicAffix = $manifest;
        $withoutPeriodicAffix['equipment']['affixes']['periodic_effect']['minimum'] = 0;
        $withoutPeriodicAffix['equipment']['affixes']['periodic_effect']['maximum'] = 0;
        $withoutPeriodicAffix = $this->model()->fight(
            new AlphaV1BuildCatalog($withoutPeriodicAffix),
            'balanced',
            'crystal_warden',
            'early',
            2,
            30,
        );
        $periodicAffixes = array_merge(...array_map(
            static fn (array $item): array => array_values(array_filter(
                $item['affixes'],
                static fn (array $affix): bool => ($affix['target'] ?? null) === 'periodic_bps',
            )),
            $withPeriodicAffix->generatedEquipment,
        ));
        $periodicDamage = static fn (BuildCombatResult $result): int => array_sum(array_column(array_filter(
            $result->actionLog,
            static fn (array $row): bool => $row['action'] === 'periodic_damage:bleed',
        ), 'amount'));
        $this->assertNotEmpty($periodicAffixes);
        $this->assertGreaterThan(
            $periodicDamage($withoutPeriodicAffix),
            $periodicDamage($withPeriodicAffix),
        );

        $barrierSettlement = $manifest;
        $barrierSettlement['enemies']['pressure_construct']['weapon_power'] = 50;
        $barrierSettlement['builds']['pure_tank']['base_stats'] = [
            'vitality' => 1, 'might' => 33, 'finesse' => 32, 'spirit' => 1, 'agility' => 33,
        ];
        $barrierSettlement['builds']['pure_tank']['equipment'] = [];
        $barrierSettlement['builds']['pure_tank']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:counter_stance',
        ]];
        foreach ($barrierSettlement['skills']['counter_stance']['effects'] as &$effect) {
            if ($effect['type'] === 'barrier') {
                $effect['fixed'] = 10_000;
            }
        }
        unset($effect);
        $barrierSettlement['statuses']['settlement_dot'] = [
            'label' => '決済試験',
            'disposition' => 'debuff',
            'duration_rounds' => 2,
            'stack_policy' => 'refresh',
            'max_stacks' => 1,
            'application_chance_bps' => 10_000,
            'effects' => [[
                'type' => 'periodic_damage',
                'target_max_hp_bps' => 3_000,
                'source_stat_coefficients' => ['might' => 10_000],
                'source_cap_multiplier_bps' => 1_000_000,
            ]],
        ];
        $barrierSettlement['skills']['barrier_dot_strike'] = [
            'label' => '障壁継続試験',
            'node_key' => null,
            'mp_cost' => 0,
            'cooldown' => 100,
            'required_weapon_styles' => [],
            'effects' => [[
                'type' => 'barrier',
                'target' => 'self',
                'source_stat_coefficients' => [],
                'target_max_hp_bps' => 0,
                'fixed' => 10_000,
            ], [
                'type' => 'damage',
                'target' => 'enemy',
                'category' => 'physical',
                'potency_bps' => 10_000,
                'stat_coefficients' => [],
                'weapon_coefficient_bps' => 0,
                'fixed' => 300,
                'target_max_hp_bps' => 0,
                'can_crit' => false,
                'dodgeable' => false,
                'hits' => 1,
            ], [
                'type' => 'apply_status',
                'target' => 'enemy',
                'status' => 'settlement_dot',
            ]],
        ];
        $barrierSettlement['enemies']['pressure_construct']['max_hp'] = 100_000;
        $barrierSettlement['enemies']['pressure_construct']['base_stats'] = [
            'vitality' => 1, 'might' => 10, 'finesse' => 1, 'spirit' => 1, 'agility' => 87,
        ];
        $barrierSettlement['enemies']['pressure_construct']['skills'] = ['barrier_dot_strike'];
        $barrierSettlement['enemies']['pressure_construct']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:barrier_dot_strike',
        ]];
        $barrierSettlement['enemies']['pressure_construct']['normal_attack'] = [
            'type' => 'damage',
            'category' => 'physical',
            'potency_bps' => 10_000,
            'stat_coefficients' => [],
            'weapon_coefficient_bps' => 0,
            'fixed' => 1,
            'target_max_hp_bps' => 0,
            'can_crit' => false,
            'dodgeable' => false,
            'hits' => 1,
        ];
        $barrierCatalog = new AlphaV1BuildCatalog($barrierSettlement);
        $settled = $this->model()->fight($barrierCatalog, 'pure_tank', 'pressure_construct', 'early', 1, 2);
        $counterRows = array_values(array_filter(
            $settled->actionLog,
            static fn (array $row): bool => $row['action'] === 'counter',
        ));
        $periodicRows = array_values(array_filter(
            $settled->actionLog,
            static fn (array $row): bool => $row['action'] === 'periodic_damage:settlement_dot',
        ));
        $this->assertNotEmpty($counterRows, json_encode($settled->actionLog, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $counterRows[0]['amount']);
        $this->assertGreaterThan(0, $counterRows[0]['barrier_absorbed']);
        $counterTaunts = array_values(array_filter(
            $settled->actionLog,
            static fn (array $row): bool => $row['action'] === 'taunt'
                && ($row['source_action'] ?? null) === 'counter',
        ));
        $this->assertNotEmpty($counterTaunts);
        $this->assertSame('pure_tank', $counterTaunts[0]['source_actor_key']);
        $this->assertSame('pressure_construct', $counterTaunts[0]['target_actor_key']);
        $this->assertNotEmpty($periodicRows, json_encode($settled->actionLog, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $periodicRows[0]['amount']);
        $this->assertGreaterThan($periodicRows[0]['actor_hp'], $periodicRows[0]['barrier_absorbed']);
        $projected = (new UndergroundAlphaV1BattleProjector)->project($settled, $barrierCatalog);
        $projectedActions = collect($projected['rounds'])
            ->flatMap(static fn (array $round): array => $round['actions'])
            ->values();
        $this->assertContains('barrier', $projectedActions->pluck('type')->all());
        $this->assertContains('status_applied', $projectedActions->pluck('type')->all());
        $this->assertContains('taunt_applied', $projectedActions->pluck('type')->all());
        $this->assertContains('挑発', $projectedActions->pluck('label')->all());
    }

    public function test_status_stack_refresh_cleanse_and_boss_control_conversion_never_create_permanent_lock(): void
    {
        [$manifest, $catalog] = $this->catalog();
        $attacker = $this->model()->fight($catalog, 'pure_attacker', 'pressure_construct', 'early', 3, 20);
        $bleedApplications = array_values(array_filter(
            $attacker->actionLog,
            static fn (array $row): bool => $row['action'] === 'status:bleed',
        ));
        $this->assertNotEmpty($bleedApplications);
        $this->assertSame('status_applied', $bleedApplications[0]['effect_type']);
        $this->assertLessThanOrEqual(3, max(array_column($bleedApplications, 'amount')));

        $manifest['skills']['enemy_break'] = [
            'label' => '破甲試験', 'node_key' => null, 'mp_cost' => 0, 'cooldown' => 0,
            'required_weapon_styles' => [],
            'effects' => [['type' => 'apply_status', 'target' => 'enemy', 'status' => 'armor_break']],
        ];
        $manifest['enemies']['pressure_construct']['skills'] = ['enemy_break'];
        $manifest['enemies']['pressure_construct']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:enemy_break',
        ]];
        $manifest['builds']['pure_healer']['active_skills'] = [
            'cleansing_wave', 'mending_prayer', 'holy_bolt',
        ];
        $manifest['builds']['pure_healer']['allocations']['miracle_cleansing_wave'] = 1;
        $manifest['builds']['pure_healer']['ai_rules'] = [[
            'conditions' => [
                ['type' => 'self_has_status', 'status' => 'armor_break'],
                ['type' => 'skill_ready', 'skill' => 'cleansing_wave'],
            ],
            'action' => 'skill:cleansing_wave',
        ], [
            'conditions' => [['type' => 'always']], 'action' => 'normal_attack',
        ]];
        $cleansed = $this->model()->fight(
            new AlphaV1BuildCatalog($manifest),
            'pure_healer',
            'pressure_construct',
            'early',
            8,
            8,
        );
        $this->assertGreaterThan(0, $cleansed->actionUsage['cleansing_wave']);

        $boss = $this->model()->fight($catalog, 'pure_tank', 'crystal_warden', 'early', 6, 30);
        $converted = array_values(array_filter(
            $boss->actionLog,
            static fn (array $row): bool => $row['action'] === 'boss_status:stagger',
        ));
        $bossSkips = array_values(array_filter(
            $boss->actionLog,
            static fn (array $row): bool => $row['side'] === 'enemy' && $row['action'] === 'action_impaired',
        ));
        $this->assertNotEmpty($converted);
        $this->assertSame([], $bossSkips);
        $this->assertGreaterThan(0, min(array_column($converted, 'amount')));
        $this->assertLessThanOrEqual(10_000, max(array_column($converted, 'amount')));

        $lowAgility = $manifest;
        $lowAgility['builds']['pure_tank']['base_stats'] = [
            'vitality' => 40, 'might' => 33, 'finesse' => 10, 'spirit' => 16, 'agility' => 1,
        ];
        $highAgility = $manifest;
        $highAgility['builds']['pure_tank']['base_stats'] = [
            'vitality' => 40, 'might' => 1, 'finesse' => 10, 'spirit' => 16, 'agility' => 33,
        ];
        $skips = function (array $candidate): int {
            $catalog = new AlphaV1BuildCatalog($candidate);

            return array_sum(array_map(
                fn (int $seed): int => $this->model()->fight(
                    $catalog, 'pure_tank', 'crystal_warden', 'early', $seed, 12,
                )->actionUsage['action_skipped'],
                range(0, 199),
            ));
        };
        $this->assertGreaterThan($skips($highAgility), $skips($lowAgility));
    }

    public function test_crystal_cycle_spends_an_action_restores_mp_and_caps_overflow(): void
    {
        [$manifest] = $this->catalog();
        $skill = $manifest['skills']['crystal_cycle'];
        $this->assertSame(0, $skill['mp_cost']);
        $this->assertGreaterThan(0, $skill['cooldown']);
        $this->assertGreaterThan(0, $skill['effects'][0]['amount']);

        $manifest['builds']['pure_healer']['ai_rules'] = [[
            'conditions' => [
                ['type' => 'round_modulo', 'modulo' => 2, 'equals' => 1],
                ['type' => 'skill_ready', 'skill' => 'mending_prayer'],
            ],
            'action' => 'skill:mending_prayer',
        ], [
            'conditions' => [['type' => 'skill_ready', 'skill' => 'crystal_cycle']],
            'action' => 'skill:crystal_cycle',
        ], [
            'conditions' => [['type' => 'always']], 'action' => 'normal_attack',
        ]];
        $manifest['enemies']['endurance_construct']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'defend',
        ]];

        $result = $this->model()->fight(
            new AlphaV1BuildCatalog($manifest), 'pure_healer', 'endurance_construct', 'early', 11, 2,
        );
        $retry = $this->model()->fight(
            new AlphaV1BuildCatalog($manifest), 'pure_healer', 'endurance_construct', 'early', 11, 2,
        );

        $this->assertSame($result->toArray(), $retry->toArray());
        $this->assertSame(1, $result->actionUsage['crystal_cycle']);
        $this->assertGreaterThan(0, $result->mpSkillRecovery);
        $this->assertSame($result->mpSkillRecovery, $result->crystalCycleRecovery);
        $this->assertGreaterThan(0, $result->mpOverflow);
        $this->assertLessThanOrEqual(AlphaV1CombatRules::MAX_MP, $result->finalMp);
    }

    public function test_fighting_spirit_and_grace_require_effective_defense_or_recovery(): void
    {
        [$manifest] = $this->catalog();
        $defendingTank = $manifest;
        $defendingTank['builds']['pure_tank']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'defend',
        ]];
        $effectiveDefense = $this->model()->fight(
            new AlphaV1BuildCatalog($defendingTank), 'pure_tank', 'pressure_construct', 'early', 1, 6,
        );
        $this->assertGreaterThan(0, $effectiveDefense->finalRoleStacks['fighting_spirit']);

        $noIncoming = $defendingTank;
        $noIncoming['enemies']['pressure_construct']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'defend',
        ]];
        $ineffectiveDefense = $this->model()->fight(
            new AlphaV1BuildCatalog($noIncoming), 'pure_tank', 'pressure_construct', 'early', 1, 6,
        );
        $this->assertSame(0, $ineffectiveDefense->finalRoleStacks['fighting_spirit']);

        $overheal = $manifest;
        $overheal['builds']['pure_healer']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:mending_prayer',
        ], [
            'conditions' => [['type' => 'always']], 'action' => 'normal_attack',
        ]];
        $overheal['enemies']['pressure_construct']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'defend',
        ]];
        $ineffectiveHealing = $this->model()->fight(
            new AlphaV1BuildCatalog($overheal), 'pure_healer', 'pressure_construct', 'early', 1, 3,
        );
        $this->assertSame(0, $ineffectiveHealing->effectiveHealing);
        $this->assertSame(0, $ineffectiveHealing->finalRoleStacks['grace']);
    }

    public function test_priority_ai_is_top_down_bounded_and_falls_back_from_unavailable_skill(): void
    {
        [$manifest] = $this->catalog();
        $manifest['skills']['executioner_cut']['mp_cost'] = 20_000;
        $manifest['builds']['pure_attacker']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:executioner_cut',
        ], [
            'conditions' => [['type' => 'always']], 'action' => 'skill:precision_cut',
        ]];
        $lowerRule = $this->model()->fight(
            new AlphaV1BuildCatalog($manifest), 'pure_attacker', 'pressure_construct', 'early', 2, 1,
        );
        $this->assertSame(1, $lowerRule->actionUsage['precision_cut']);
        $this->assertSame(0, $lowerRule->actionUsage['normal_attack']);
        $this->assertSame(0, $lowerRule->actionUsage['ai_fallback']);
        $this->assertSame(1, $lowerRule->skillUnavailableDueToMp);

        $manifest['builds']['pure_attacker']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:executioner_cut',
        ]];
        $fallback = $this->model()->fight(
            new AlphaV1BuildCatalog($manifest), 'pure_attacker', 'pressure_construct', 'early', 2, 1,
        );
        $this->assertSame(1, $fallback->actionUsage['precision_cut']);
        $this->assertSame(0, $fallback->actionUsage['normal_attack']);
        $this->assertSame(1, $fallback->actionUsage['ai_fallback']);
        $this->assertSame(1, $fallback->skillUnavailableDueToMp);

        foreach ($manifest['builds']['pure_attacker']['active_skills'] as $skillKey) {
            $manifest['skills'][$skillKey]['mp_cost'] = 20_000;
        }
        $normalFallback = $this->model()->fight(
            new AlphaV1BuildCatalog($manifest), 'pure_attacker', 'pressure_construct', 'early', 2, 1,
        );
        $this->assertSame(1, $normalFallback->actionUsage['normal_attack']);
        $this->assertSame(1, $normalFallback->actionUsage['ai_fallback']);

        $manifest['builds']['pure_attacker']['ai_rules'] = array_fill(0, 17, [
            'conditions' => [['type' => 'always']], 'action' => 'normal_attack',
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too many AI rules');
        (new UndergroundBuildValidator(new AlphaV1CombatRules))->validate(
            new AlphaV1BuildCatalog($manifest),
            'pure_attacker',
        );
    }

    public function test_custom_ai_skips_unavailable_actions_follows_forward_jump_and_uses_deterministic_fallback(): void
    {
        $catalog = $this->awakeningCatalog(enemyDefends: true);
        $configuration = new PriorityCombatAiConfiguration;
        $snapshot = $this->awakeningPlayerSnapshot(
            'free_black',
            gauge: 0,
            currentHp: null,
            unlocked: false,
            skills: ['dagger_flurry', 'precision_cut'],
        );
        $snapshot['ai_rules'] = $configuration->normalizeRules([
            ['conditions' => [], 'action' => 'skill:executioner_cut'],
            ['conditions' => [], 'action' => 'jump', 'jump_to' => 4],
            ['conditions' => [], 'action' => 'defend'],
            ['conditions' => [], 'action' => 'normal_attack'],
        ], $catalog);

        $jumped = $this->model()->fightPlayerSnapshot($catalog, $snapshot, 'awakening_target', 113, 1, 0);
        $this->assertSame(1, $jumped->actionUsage['normal_attack']);
        $this->assertSame(0, $jumped->actionUsage['defend']);
        $this->assertSame(0, $jumped->actionUsage['ai_fallback']);

        $snapshot['ai_rules'] = [];
        $canonicalFallback = $this->model()->fightPlayerSnapshot(
            $catalog,
            $snapshot,
            'awakening_target',
            113,
            1,
            0,
        );
        $this->assertSame(1, $canonicalFallback->actionUsage['precision_cut']);
        $this->assertSame(0, $canonicalFallback->actionUsage['dagger_flurry']);
        $this->assertSame(0, $canonicalFallback->actionUsage['normal_attack']);
        $this->assertSame(1, $canonicalFallback->actionUsage['ai_fallback']);

        $snapshot['active_skills'] = [];
        $normalFallback = $this->model()->fightPlayerSnapshot(
            $catalog,
            $snapshot,
            'awakening_target',
            113,
            1,
            0,
        );
        $this->assertSame(1, $normalFallback->actionUsage['normal_attack']);
        $this->assertSame(1, $normalFallback->actionUsage['ai_fallback']);
        $this->assertSame(0, $normalFallback->actionUsage['action_skipped']);
    }

    public function test_awakening_rule_can_activate_above_default_threshold_without_consuming_the_turn(): void
    {
        $catalog = $this->awakeningCatalog(enemyDefends: true);
        $configuration = new PriorityCombatAiConfiguration;
        $custom = $this->awakeningPlayerSnapshot('free_black', currentHp: 300);
        $custom['ai_rules'] = $configuration->normalizeRules([
            ['conditions' => [], 'action' => 'awakening'],
            ['conditions' => [], 'action' => 'normal_attack'],
        ], $catalog);

        $customResult = $this->model()->fightPlayerSnapshot($catalog, $custom, 'awakening_target', 127, 1, 0);
        $this->assertGreaterThan(
            intdiv($customResult->awakening['normal_max_hp'] * UndergroundAwakening::ACTIVATION_HP_BPS, 10_000),
            300,
        );
        $this->assertTrue($customResult->awakening['triggered']);
        $this->assertSame(1, $customResult->actionUsage['normal_attack']);
        $this->assertFalse($customResult->awakening['technique']['used']);
        $playerDecisions = array_values(array_filter(
            $customResult->actionLog,
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['side'] ?? null) === 'player',
        ));
        $this->assertSame(['awakening', 'normal_attack'], array_column($playerDecisions, 'action_key'));
        $this->assertSame([1, 1], array_column($playerDecisions, 'round'));

        $default = $this->awakeningPlayerSnapshot('free_black', currentHp: 300);
        $defaultResult = $this->model()->fightPlayerSnapshot($catalog, $default, 'awakening_target', 127, 1, 0);
        $this->assertFalse($defaultResult->awakening['triggered']);
        $this->assertSame(1, $defaultResult->actionUsage['normal_attack']);
    }

    public function test_equipment_generation_is_deterministic_and_separates_item_level_rarity_and_caps(): void
    {
        [, $catalog] = $this->catalog();
        $generator = new DeterministicEquipmentGenerator(new AlphaV1CombatRules);
        $request = ['slot' => 'weapon', 'weapon_style' => 'dagger', 'rarity' => 'unique', 'seed' => 99];
        $first = $generator->generate($catalog, 40, $request);
        $retry = $generator->generate($catalog, 40, $request);
        $higher = $generator->generate($catalog, 45, $request);

        $this->assertSame($first, $retry);
        $this->assertNotSame($first['identity'], $higher['identity']);
        $this->assertSame(40, $first['item_level']);
        $this->assertSame('unique', $first['rarity']);
        $this->assertNotNull($first['unique_effect']);
        foreach ($first['affixes'] as $affix) {
            $this->assertLessThanOrEqual($affix['cap'], $affix['value']);
            $this->assertNotSame('max_mp', $affix['key']);
        }
    }

    public function test_player_shop_equipment_catalog_keeps_ranked_progression_and_unlock_rules(): void
    {
        $catalog = require dirname(__DIR__, 3).'/config/underground-equipment.php';
        $definitions = $catalog['definitions'];
        $shop = array_filter(
            $definitions,
            static fn (array $definition): bool => $definition['shop_sold'],
        );

        $this->assertSame('secretary-underground-shop-equipment-alpha-v2', $catalog['catalog_identity']);
        $starter = $definitions['starter_knife'];
        $this->assertSame(['weapon', 'dagger', 0, 1, false, false], [
            $starter['category'], $starter['weapon_style'], $starter['rank'], $starter['item_level'],
            $starter['shop_sold'], $starter['sellable'],
        ]);

        $weapons = array_filter($shop, static fn (array $item): bool => $item['category'] === 'weapon');
        $armors = array_values(array_filter($shop, static fn (array $item): bool => $item['category'] === 'armor'));
        $accessories = array_filter($shop, static fn (array $item): bool => $item['category'] === 'accessory');

        foreach (['dagger', 'rapier', 'longsword', 'crystal_staff'] as $style) {
            $series = array_values(array_filter(
                $weapons,
                static fn (array $item): bool => $item['weapon_style'] === $style
                    && $item['rank'] <= 3,
            ));
            $this->assertSame([1, 2, 3], array_column($series, 'rank'));
            $this->assertSame([1, 10, 20], array_column($series, 'item_level'));
            $this->assertLessThan($series[1]['buy_price'], $series[0]['buy_price']);
            $this->assertLessThan($series[2]['buy_price'], $series[1]['buy_price']);
            $this->assertLessThan($series[1]['weapon_power'], $series[0]['weapon_power']);
            $this->assertLessThan($series[2]['weapon_power'], $series[1]['weapon_power']);
        }
        $legacyArmors = array_values(array_filter(
            $armors,
            static fn (array $item): bool => $item['rank'] <= 3,
        ));
        $this->assertSame([1, 2, 3], array_column($legacyArmors, 'rank'));
        $this->assertLessThan($legacyArmors[1]['buy_price'], $legacyArmors[0]['buy_price']);
        $this->assertLessThan($legacyArmors[2]['buy_price'], $legacyArmors[1]['buy_price']);
        foreach (['physical_defense', 'magical_defense', 'max_hp'] as $field) {
            $this->assertLessThan($legacyArmors[1][$field], $legacyArmors[0][$field]);
            $this->assertLessThan($legacyArmors[2][$field], $legacyArmors[1][$field]);
        }

        foreach (AlphaV1CombatRules::STATS as $stat) {
            $series = array_values(array_filter(
                $accessories,
                static fn (array $item, string $key): bool => str_starts_with($key, $stat.'_'),
                ARRAY_FILTER_USE_BOTH,
            ));
            $this->assertSame([1, 2, 3], array_column($series, 'rank'));
            $this->assertLessThan($series[1]['buy_price'], $series[0]['buy_price']);
            $this->assertLessThan($series[2]['buy_price'], $series[1]['buy_price']);
            foreach ($series as $index => $item) {
                $expected = array_fill_keys(AlphaV1CombatRules::STATS, 0);
                $expected[$stat] = $index + 1;
                $this->assertSame($expected, $item['stats']);
            }
        }
        foreach ($shop as $item) {
            $this->assertSame(['common', [], [], null], [
                $item['rarity'], $item['modifiers'], $item['affixes'], $item['unique_effect'],
            ]);
        }
        $this->assertSame([3, 20, 'common'], [
            $definitions['polished_steel_dagger']['rank'],
            $definitions['polished_steel_dagger']['item_level'],
            $definitions['polished_steel_dagger']['rarity'],
        ]);
        $this->assertSame([4, 40, 3_000, 'trial_01', 'ノービス'], [
            $definitions['black_crystal_dagger']['rank'],
            $definitions['black_crystal_dagger']['item_level'],
            $definitions['black_crystal_dagger']['buy_price'],
            $definitions['black_crystal_dagger']['required_trial_key'],
            $definitions['black_crystal_dagger']['rarity_label'],
        ]);
    }

    public function test_representative_build_smoke_has_legal_state_and_no_surface_or_database_fixture(): void
    {
        [, $catalog] = $this->catalog();
        foreach ($catalog->buildKeys() as $buildKey) {
            foreach (range(0, 7) as $seed) {
                $result = $this->model()->fight($catalog, $buildKey, 'depth_stalker', 'early', $seed, 100);
                $this->assertSame([], $result->abnormalState);
                $this->assertGreaterThanOrEqual(0, $result->playerRemainingHp);
                $this->assertGreaterThanOrEqual(0, $result->finalMp);
                $this->assertLessThanOrEqual(10_000, $result->finalMp);
            }
        }
    }

    public function test_runtime_snapshot_is_unscaled_and_crystal_guard_rolls_independently_per_hit(): void
    {
        [$manifest] = $this->catalog();
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $weapon = $configuration['exploration']['starter_weapon'];
        $crystalBug = $configuration['exploration']['grounds']['shallow_caves']['encounters']['crystal_bug']['enemy'];
        $crystalBug['max_hp'] = 1_000_000;
        $crystalBug['base_stats'] = [
            'vitality' => 95, 'might' => 1, 'finesse' => 1, 'spirit' => 2, 'agility' => 1,
        ];
        $manifest['normal_attack']['hits'] = 100;
        $manifest['enemies']['crystal_bug'] = $crystalBug;
        $catalog = new AlphaV1BuildCatalog($manifest);
        $growthStats = ['vitality' => 22, 'might' => 42, 'finesse' => 34, 'spirit' => 12, 'agility' => 10];
        $playerSnapshot = [
            'key' => 'secretary_runtime',
            'label' => '成長中の秘書',
            'stats' => $growthStats,
            'active_skills' => [],
            'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']],
            'modifiers' => [],
            'equipment' => $weapon,
            'current_mp' => 1,
        ];
        $combatStats = array_map(
            static fn (int $value): int => $value + 1,
            $growthStats,
        );
        $expectedMaxHp = (new AlphaV1CombatRules)->maxHp($combatStats, 10_000);
        $completeGuards = 0;
        $passedHits = 0;

        foreach (range(0, 19) as $seed) {
            $result = $this->model()->fightPlayerSnapshot(
                $catalog,
                $playerSnapshot,
                'crystal_bug',
                $seed,
                1,
                300,
            );
            $hits = array_values(array_filter(
                $result->actionLog,
                static fn (array $row): bool => ($row['kind'] ?? null) === 'effect'
                    && ($row['side'] ?? null) === 'player'
                    && ($row['action'] ?? null) === 'normal_attack',
            ));
            $this->assertCount(100, $hits);
            $completeGuards += count(array_filter(
                $hits,
                static fn (array $row): bool => ($row['complete_guarded'] ?? false) === true,
            ));
            $unguardedHits = array_values(array_filter(
                $hits,
                static fn (array $row): bool => ($row['complete_guarded'] ?? false) === false,
            ));
            $passedHits += count($unguardedHits);
            foreach ($unguardedHits as $unguardedHit) {
                $this->assertGreaterThan(0, $unguardedHit['amount']);
            }
            $roundEnd = collect($result->actionLog)->firstWhere('kind', 'round_end');
            $this->assertIsArray($roundEnd);
            $this->assertSame($expectedMaxHp, $roundEnd['player']['max_hp']);
            $this->assertSame(AlphaV1CombatRules::MAX_MP, $result->finalMp);
        }

        $this->assertGreaterThan(1_900, $completeGuards);
        $this->assertGreaterThan(0, $passedHits);
        $this->assertSame(2_000, $completeGuards + $passedHits);

        [$skillManifest] = $this->catalog();
        $skillManifest['enemies']['crystal_bug'] = $configuration['exploration']['grounds']['shallow_caves']['encounters']['crystal_bug']['enemy'];
        $skillCatalog = new AlphaV1BuildCatalog($skillManifest);
        $skillSnapshot = [
            'key' => 'secretary_runtime',
            'label' => '輝石虫試験の秘書',
            'stats' => $growthStats,
            'active_skills' => ['precision_cut'],
            'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'skill:precision_cut']],
            'modifiers' => [],
            'equipment' => $weapon,
        ];
        $precisionWins = 0;
        $flurryWins = 0;
        foreach (range(0, 999) as $seed) {
            $precision = $this->model()->fightPlayerSnapshot(
                $skillCatalog, $skillSnapshot, 'crystal_bug', $seed, 1, 300,
            );
            $precisionWins += $precision->winner === 'player' ? 1 : 0;

            $skillSnapshot['active_skills'] = ['dagger_flurry'];
            $skillSnapshot['ai_rules'] = [[
                'conditions' => [['type' => 'always']], 'action' => 'skill:dagger_flurry',
            ]];
            $flurry = $this->model()->fightPlayerSnapshot(
                $skillCatalog, $skillSnapshot, 'crystal_bug', $seed, 1, 300,
            );
            $flurryWins += $flurry->winner === 'player' ? 1 : 0;
            $skillSnapshot['active_skills'] = ['precision_cut'];
            $skillSnapshot['ai_rules'] = [[
                'conditions' => [['type' => 'always']], 'action' => 'skill:precision_cut',
            ]];
        }
        $this->assertGreaterThan($precisionWins, $flurryWins);
    }

    public function test_awakening_core_applies_once_to_final_equipped_stats_without_cleansing_battle_state(): void
    {
        $rules = new AlphaV1CombatRules;
        $awakening = new UndergroundAwakening;
        $normalStats = [
            'vitality' => 21,
            'might' => 32,
            'finesse' => 43,
            'spirit' => 54,
            'agility' => 65,
        ];
        $state = new BuildCombatState(
            'player',
            'awakening_test',
            '覚醒試験',
            false,
            $rules->maxHp($normalStats, 10_000, 37),
            $normalStats,
            19 + ($normalStats['vitality'] * 4),
            23 + ($normalStats['spirit'] * 4),
            $rules->defenseReference(10_000),
            41,
            ['precision_cut'],
            [['conditions' => [['type' => 'always']], 'action' => 'skill:precision_cut']],
            [],
            null,
            [],
        );
        $state->equipmentMaxHp = 37;
        $state->equipmentPhysicalDefense = 19;
        $state->equipmentMagicalDefense = 23;
        $state->awakeningUnlocked = true;
        $state->awakeningGauge = UndergroundAwakening::GAUGE_MAX;
        $state->hp = intdiv($state->maxHp, 5);
        $state->mp = 123;
        $state->cooldowns['precision_cut'] = 2;
        $state->statuses['bleed'] = [
            'key' => 'bleed',
            'disposition' => 'debuff',
            'remaining' => 2,
            'applied_round' => 1,
            'stacks' => 2,
            'effects' => [],
            'control' => false,
        ];
        $state->roleStacks = ['fighting_spirit' => 3, 'grace' => 2];
        $state->barrier = 47;
        $state->flags['afterguard_focus'] = true;
        $preserved = [
            'cooldowns' => $state->cooldowns,
            'statuses' => $state->statuses,
            'role_stacks' => $state->roleStacks,
            'barrier' => $state->barrier,
            'flags' => $state->flags,
        ];

        $this->assertTrue($awakening->tryActivate($state, $rules));
        $expectedStats = array_map(
            static fn (int $value): int => $value + intdiv($value * 3_000, 10_000),
            $normalStats,
        );
        $this->assertSame($expectedStats, $state->stats);
        $this->assertSame($normalStats, $state->normalStats);
        $this->assertSame($rules->maxHp($expectedStats, 10_000, 37), $state->maxHp);
        $this->assertSame($state->maxHp, $state->hp);
        $this->assertSame(AlphaV1CombatRules::MAX_MP, $state->mp);
        $this->assertSame(19 + ($expectedStats['vitality'] * 4), $state->physicalDefense);
        $this->assertSame(23 + ($expectedStats['spirit'] * 4), $state->magicalDefense);
        $this->assertSame(0, $state->awakeningGauge);
        $this->assertTrue($state->awakened);
        $this->assertSame($preserved, [
            'cooldowns' => $state->cooldowns,
            'statuses' => $state->statuses,
            'role_stacks' => $state->roleStacks,
            'barrier' => $state->barrier,
            'flags' => $state->flags,
        ]);
        $activated = clone $state;
        $this->assertFalse($awakening->tryActivate($state, $rules));
        $this->assertEquals($activated, $state);
    }

    public function test_awakening_gauge_is_deterministic_capped_and_counts_one_damaging_enemy_action_not_hits(): void
    {
        $catalog = $this->awakeningCatalog(enemyHits: 10, enemyWeaponPower: 10);
        $snapshot = $this->awakeningPlayerSnapshot('martial_red', gauge: 0, currentHp: null);

        $first = $this->model()->fightPlayerSnapshot($catalog, $snapshot, 'awakening_target', 71, 1, 0);
        $retry = $this->model()->fightPlayerSnapshot($catalog, $snapshot, 'awakening_target', 71, 1, 0);
        $this->assertSame($first->toArray(), $retry->toArray());
        $this->assertFalse($first->awakening['triggered']);
        $this->assertSame(30, $first->awakening['gauge_after']);
        $this->assertSame(30, $first->awakening['gauge_gained']);
        $this->assertCount(10, array_filter(
            $first->actionLog,
            static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                && ($row['effect_type'] ?? null) === 'damage',
        ));

        $nearCap = $this->awakeningPlayerSnapshot('martial_red', gauge: 999, currentHp: null);
        $capped = $this->model()->fightPlayerSnapshot($catalog, $nearCap, 'awakening_target', 71, 1, 0);
        $this->assertSame(UndergroundAwakening::GAUGE_MAX, $capped->awakening['gauge_after']);
        $this->assertSame(1, $capped->awakening['gauge_gained']);

        $locked = $this->awakeningPlayerSnapshot('martial_red', gauge: 0, currentHp: null, unlocked: false);
        $lockedResult = $this->model()->fightPlayerSnapshot($catalog, $locked, 'awakening_target', 71, 1, 0);
        $this->assertSame(0, $lockedResult->awakening['gauge_after']);
        $this->assertFalse($lockedResult->awakening['triggered']);
    }

    public function test_fixed_awakening_techniques_cover_martial_two_round_guardianship_and_blessing_solo_targets(): void
    {
        $awakening = new UndergroundAwakening;
        $this->assertSame([
            'martial_red' => ['decisive_heavenrend', '天断一閃', true],
            'guardianship_blue' => ['absolute_aegis', '絶対護界', true],
            'blessing_green' => ['life_requiem', '生命讃歌', true],
            'free_black' => ['limitless_reprise', '無窮再演', false],
        ], array_map(
            static fn (array $technique): array => [
                $technique['key'], $technique['name'], $technique['consumes_action'],
            ],
            array_combine(
                ['martial_red', 'guardianship_blue', 'blessing_green', 'free_black'],
                array_map(
                    static fn (string $path): array => $awakening->technique($path),
                    ['martial_red', 'guardianship_blue', 'blessing_green', 'free_black'],
                ),
            ),
        ));

        $catalog = $this->awakeningCatalog(enemyWeaponPower: 2_500);
        $martial = $this->model()->fightPlayerSnapshot(
            $catalog,
            $this->awakeningPlayerSnapshot('martial_red'),
            'awakening_target',
            91,
            1,
            0,
        );
        $martialDamage = array_values(array_filter(
            $martial->actionLog,
            static fn (array $row): bool => ($row['action'] ?? null) === 'decisive_heavenrend'
                && ($row['effect_type'] ?? null) === 'damage',
        ));
        $this->assertCount(1, $martialDamage);
        $this->assertSame('enemy', $martialDamage[0]['target_side']);
        $this->assertGreaterThan(0, $martialDamage[0]['amount']);
        $this->assertSame(35_000, UndergroundAwakening::MARTIAL_POTENCY_BPS);
        $this->assertTrue($martial->awakening['technique']['used']);

        $guardian = $this->model()->fightPlayerSnapshot(
            $catalog,
            $this->awakeningPlayerSnapshot('guardianship_blue'),
            'awakening_target',
            93,
            4,
            0,
        );
        $unguarded = $this->model()->fightPlayerSnapshot(
            $catalog,
            $this->awakeningPlayerSnapshot('blessing_green'),
            'awakening_target',
            93,
            4,
            0,
        );
        $guardianHits = $this->enemyDamageAmounts($guardian);
        $unguardedHits = $this->enemyDamageAmounts($unguarded);
        $this->assertCount(4, $guardianHits);
        $this->assertCount(4, $unguardedHits);
        $this->assertLessThanOrEqual(1, abs($guardianHits[0] - max(1, intdiv($unguardedHits[0], 10))));
        $this->assertLessThanOrEqual(1, abs($guardianHits[1] - max(1, intdiv($unguardedHits[1], 10))));
        $this->assertLessThanOrEqual(1, abs($guardianHits[2] - max(1, intdiv($unguardedHits[2], 10))));
        $this->assertSame($unguardedHits[3], $guardianHits[3]);
        $this->assertSame(2, UndergroundAwakening::GUARDIAN_DURATION_ROUNDS);
        $guardianRoundOne = collect($guardian->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end' && ($row['round'] ?? null) === 1,
        );
        $guardianRoundTwo = collect($guardian->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end' && ($row['round'] ?? null) === 2,
        );
        $guardianRoundThree = collect($guardian->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end' && ($row['round'] ?? null) === 3,
        );
        $this->assertIsArray($guardianRoundOne);
        $this->assertIsArray($guardianRoundTwo);
        $this->assertIsArray($guardianRoundThree);
        $this->assertSame(2, $guardianRoundOne['player']['awakening_guard_rounds_remaining']);
        $this->assertSame(1, $guardianRoundTwo['player']['awakening_guard_rounds_remaining']);
        $this->assertSame(0, $guardianRoundThree['player']['awakening_guard_rounds_remaining']);
        $guardianExpiry = array_values(array_filter(
            $guardian->actionLog,
            static fn (array $row): bool => ($row['action'] ?? null) === 'absolute_aegis_expired',
        ));
        $this->assertCount(1, $guardianExpiry);
        $this->assertSame(3, $guardianExpiry[0]['round']);

        $enemyFirstCatalog = $this->awakeningCatalog(enemyWeaponPower: 1_000, enemyAgility: 75);
        $enemyFirstPlayer = $this->awakeningPlayerSnapshot('guardianship_blue', currentHp: 300);
        $enemyFirstPlayer['stats']['agility'] = 1;
        $enemyFirst = $this->model()->fightPlayerSnapshot(
            $enemyFirstCatalog,
            $enemyFirstPlayer,
            'awakening_target',
            95,
            4,
            0,
        );
        $enemyFirstBaselinePlayer = $this->awakeningPlayerSnapshot('blessing_green', currentHp: 300);
        $enemyFirstBaselinePlayer['stats']['agility'] = 1;
        $enemyFirstBaseline = $this->model()->fightPlayerSnapshot(
            $enemyFirstCatalog,
            $enemyFirstBaselinePlayer,
            'awakening_target',
            95,
            4,
            0,
        );
        $enemyFirstHits = $this->enemyDamageAmounts($enemyFirst);
        $enemyFirstBaselineHits = $this->enemyDamageAmounts($enemyFirstBaseline);
        $this->assertCount(4, $enemyFirstHits);
        $this->assertCount(4, $enemyFirstBaselineHits);
        $awakeningIndex = collect($enemyFirst->actionLog)->search(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'awakening',
        );
        $techniqueIndex = collect($enemyFirst->actionLog)->search(
            static fn (array $row): bool => ($row['action'] ?? null) === 'absolute_aegis',
        );
        $firstEnemyDamageIndex = collect($enemyFirst->actionLog)->search(
            static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsInt($awakeningIndex);
        $this->assertIsInt($techniqueIndex);
        $this->assertIsInt($firstEnemyDamageIndex);
        $this->assertLessThan($awakeningIndex, $firstEnemyDamageIndex);
        $this->assertLessThan($techniqueIndex, $awakeningIndex);
        $this->assertLessThanOrEqual(
            intdiv((int) $enemyFirst->awakening['normal_max_hp'] * UndergroundAwakening::ACTIVATION_HP_BPS, 10_000),
            300 - $enemyFirstHits[0],
        );
        $enemyFirstRoundOne = collect($enemyFirst->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end' && ($row['round'] ?? null) === 1,
        );
        $enemyFirstRoundTwo = collect($enemyFirst->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end' && ($row['round'] ?? null) === 2,
        );
        $enemyFirstRoundThree = collect($enemyFirst->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end' && ($row['round'] ?? null) === 3,
        );
        $this->assertIsArray($enemyFirstRoundOne);
        $this->assertIsArray($enemyFirstRoundTwo);
        $this->assertIsArray($enemyFirstRoundThree);
        $this->assertSame(2, $enemyFirstRoundOne['player']['awakening_guard_rounds_remaining']);
        $this->assertSame(1, $enemyFirstRoundTwo['player']['awakening_guard_rounds_remaining']);
        $this->assertSame(0, $enemyFirstRoundThree['player']['awakening_guard_rounds_remaining']);
        $this->assertSame(1, $enemyFirstRoundOne['player']['awakening_guard_applied_round']);
        $this->assertSame($enemyFirstBaselineHits[0], $enemyFirstHits[0]);
        $this->assertLessThanOrEqual(1, abs($enemyFirstHits[1] - max(1, intdiv($enemyFirstBaselineHits[1], 10))));
        $this->assertLessThanOrEqual(1, abs($enemyFirstHits[2] - max(1, intdiv($enemyFirstBaselineHits[2], 10))));
        $this->assertSame($enemyFirstBaselineHits[3], $enemyFirstHits[3]);
        $enemyFirstExpiry = array_values(array_filter(
            $enemyFirst->actionLog,
            static fn (array $row): bool => ($row['action'] ?? null) === 'absolute_aegis_expired',
        ));
        $this->assertCount(1, $enemyFirstExpiry);
        $this->assertSame(3, $enemyFirstExpiry[0]['round']);

        $blessingRows = array_values(array_filter(
            $unguarded->actionLog,
            static fn (array $row): bool => ($row['action'] ?? null) === 'life_requiem',
        ));
        $this->assertCount(2, $blessingRows);
        $this->assertSame('awakening_technique', $blessingRows[0]['kind']);
        $this->assertSame('player', $blessingRows[0]['target_side']);
        $this->assertSame('recovery', $blessingRows[1]['effect_type']);
        $this->assertLessThan(0, $blessingRows[1]['amount']);
        $roundTwo = collect($unguarded->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end' && ($row['round'] ?? null) === 2,
        );
        $this->assertIsArray($roundTwo);
        $this->assertSame(
            $unguarded->awakening['final_max_hp'] - $roundTwo['player']['hp'],
            -$blessingRows[1]['amount'],
        );
        $this->assertTrue($unguarded->awakening['technique']['used']);

        $normalBurst = collect($unguarded->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'player'
                && ($row['action'] ?? null) === 'normal_attack'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($normalBurst);
        $this->assertGreaterThan($normalBurst['amount'], $martialDamage[0]['amount']);

        $miracleCatalog = $this->awakeningCatalog(enemyWeaponPower: 2_500, enemyCategory: 'miracle');
        $miracleGuard = $this->model()->fightPlayerSnapshot(
            $miracleCatalog,
            $this->awakeningPlayerSnapshot('guardianship_blue'),
            'awakening_target',
            97,
            3,
            0,
        );
        $miracleBaseline = $this->model()->fightPlayerSnapshot(
            $miracleCatalog,
            $this->awakeningPlayerSnapshot('blessing_green'),
            'awakening_target',
            97,
            3,
            0,
        );
        $this->assertLessThanOrEqual(1, abs(
            $this->enemyDamageAmounts($miracleGuard)[0]
            - max(1, intdiv($this->enemyDamageAmounts($miracleBaseline)[0], 10)),
        ));
    }

    public function test_free_awakening_technique_resets_normal_rotation_and_continues_the_same_turn_once(): void
    {
        $catalog = $this->awakeningCatalog(enemyDefends: true);
        $snapshot = $this->awakeningPlayerSnapshot(
            'free_black',
            skills: ['holy_lance', 'severing_bleed'],
            aiRules: [
                ['conditions' => [['type' => 'skill_ready', 'skill' => 'holy_lance']], 'action' => 'skill:holy_lance'],
                ['conditions' => [['type' => 'skill_ready', 'skill' => 'severing_bleed']], 'action' => 'skill:severing_bleed'],
                ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
            ],
        );

        $result = $this->model()->fightPlayerSnapshot($catalog, $snapshot, 'awakening_target', 109, 3, 0);
        $retry = $this->model()->fightPlayerSnapshot($catalog, $snapshot, 'awakening_target', 109, 3, 0);
        $this->assertSame($result->toArray(), $retry->toArray());
        $techniques = array_values(array_filter(
            $result->actionLog,
            static fn (array $row): bool => ($row['kind'] ?? null) === 'awakening_technique',
        ));
        $this->assertCount(1, $techniques);
        $this->assertSame('limitless_reprise', $techniques[0]['action']);
        $techniqueIndex = array_search($techniques[0], $result->actionLog, true);
        $sameRoundLater = array_slice($result->actionLog, ((int) $techniqueIndex) + 1);
        $this->assertNotEmpty(array_filter(
            $sameRoundLater,
            static fn (array $row): bool => ($row['round'] ?? null) === $techniques[0]['round']
                && ($row['kind'] ?? null) === 'decision'
                && ($row['side'] ?? null) === 'player',
        ));
        $this->assertSame(2, $result->actionUsage['holy_lance']);
        $this->assertSame(1, $result->actionUsage['severing_bleed']);
        $this->assertSame(AlphaV1CombatRules::MAX_MP - $catalog->skill('holy_lance')['mp_cost'], $result->finalMp);
        $this->assertSame(0, $result->awakening['gauge_after']);
        $this->assertTrue($result->awakening['triggered']);
        $this->assertTrue($result->awakening['technique']['used']);
        $this->assertSame(1, $result->actionUsage['awakening_technique']);
        $lastRound = collect($result->actionLog)->last(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end',
        );
        $this->assertIsArray($lastRound);
        $this->assertSame($catalog->skill('holy_lance')['cooldown'], $lastRound['player']['cooldowns']['holy_lance']);
        $this->assertSame(0, $lastRound['player']['cooldowns']['severing_bleed']);
    }

    public function test_each_growth_path_exposes_its_existing_and_additional_awakening_techniques(): void
    {
        $awakening = new UndergroundAwakening;
        $expected = [
            'martial_red' => ['decisive_heavenrend', 'shura_bloodline'],
            'guardianship_blue' => ['absolute_aegis', 'fortress_strike'],
            'blessing_green' => ['life_requiem', 'judgment_light'],
            'free_black' => ['limitless_reprise', 'formless_strike'],
        ];

        foreach ($expected as $growthPath => $keys) {
            $this->assertSame($keys, array_column($awakening->techniques($growthPath), 'key'));
            $this->assertSame($keys[0], $awakening->technique($growthPath)['key']);
            $this->assertSame($keys[1], $awakening->technique($growthPath, $keys[1])['key']);
        }

        $this->expectException(InvalidArgumentException::class);
        $awakening->technique('martial_red', 'formless_strike');
    }

    public function test_shura_bloodline_strikes_on_activation_and_combines_direct_lifesteal_for_three_rounds(): void
    {
        $snapshot = $this->awakeningPlayerSnapshot(
            'martial_red',
            techniqueKey: 'shura_bloodline',
        );
        $snapshot['modifiers']['lifesteal_bps'] = 2_000;
        $result = $this->model()->fightPlayerSnapshot(
            $this->awakeningCatalog(enemyWeaponPower: 100),
            $snapshot,
            'awakening_target',
            211,
            5,
            0,
        );

        $damageByRound = collect($result->actionLog)
            ->filter(static fn (array $row): bool => ($row['side'] ?? null) === 'player'
                && ($row['effect_type'] ?? null) === 'damage')
            ->keyBy('round');
        $drains = collect($result->actionLog)
            ->filter(static fn (array $row): bool => ($row['action'] ?? null) === 'shura_bloodline_lifesteal')
            ->values();
        $openingStrike = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'shura_bloodline'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($openingStrike);
        $this->assertSame(1, $openingStrike['round']);
        $this->assertGreaterThan(0, $openingStrike['amount']);
        $this->assertSame(UndergroundAwakening::LIFESTEAL_CAP_BPS, 2_500);
        $this->assertSame(UndergroundAwakening::BLOODLINE_DURATION_ROUNDS - 1, $drains->count());
        $this->assertSame([2, 3], $drains->pluck('round')->all());
        foreach ($drains as $drain) {
            $damage = $damageByRound->get($drain['round']);
            $this->assertIsArray($damage);
            $this->assertSame(intdiv($damage['amount'] * 2_500, 10_000), -$drain['amount']);
        }
        $expiry = collect($result->actionLog)->firstWhere('action', 'shura_bloodline_expired');
        $this->assertIsArray($expiry);
        $this->assertSame(3, $expiry['round']);
        $this->assertSame('shura_bloodline', $result->awakening['technique']['key']);
        $this->assertTrue($result->awakening['technique']['used']);
    }

    public function test_enemy_lifesteal_recovers_from_actual_hp_damage_independently_of_regeneration(): void
    {
        $catalog = $this->awakeningCatalog(enemyWeaponPower: 5_000);
        $manifest = $catalog->manifest();
        $manifest['skills']['precision_cut']['effects'][] = [
            'type' => 'barrier',
            'target' => 'self',
            'source_stat_coefficients' => [],
            'target_max_hp_bps' => 0,
            'fixed' => 100,
        ];
        $manifest['enemies']['awakening_target']['modifiers'] = [
            'lifesteal_bps' => 400,
            'self_regeneration_target_hp_bps' => 0,
        ];
        $snapshot = $this->awakeningPlayerSnapshot(
            'martial_red',
            gauge: 0,
            currentHp: null,
            skills: ['precision_cut'],
            aiRules: [['conditions' => [['type' => 'always']], 'action' => 'skill:precision_cut']],
        );
        $catalog = new AlphaV1BuildCatalog($manifest);
        $result = $this->model()->fightPlayerSnapshot(
            $catalog,
            $snapshot,
            'awakening_target',
            293,
            1,
            0,
        );

        $playerDamage = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'precision_cut'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $enemyDamage = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($playerDamage);
        $this->assertIsArray($enemyDamage);
        $this->assertGreaterThan(0, $enemyDamage['amount']);
        $this->assertGreaterThan(0, $enemyDamage['barrier_absorbed']);
        $expectedLifesteal = min($playerDamage['amount'], intdiv($enemyDamage['amount'] * 400, 10_000));
        $this->assertGreaterThan(0, $expectedLifesteal);
        $this->assertSame(
            $manifest['enemies']['awakening_target']['max_hp'] - $playerDamage['amount'] + $expectedLifesteal,
            $result->enemyRemainingHp,
        );
        $lifestealRows = collect($result->actionLog)
            ->filter(static fn (array $row): bool => ($row['action'] ?? null) === 'lifesteal')
            ->values();
        $this->assertCount(1, $lifestealRows);
        $this->assertSame('enemy', $lifestealRows[0]['side']);
        $this->assertSame('enemy', $lifestealRows[0]['target_side']);
        $this->assertSame('recovery', $lifestealRows[0]['effect_type']);
        $this->assertSame(-$expectedLifesteal, $lifestealRows[0]['amount']);
        $projectedLifesteal = collect((new UndergroundAlphaV1BattleProjector)->project($result, $catalog)['rounds'])
            ->flatMap(static fn (array $round): array => $round['actions'])
            ->first(static fn (array $action): bool => ($action['label'] ?? null) === '吸血');
        $this->assertIsArray($projectedLifesteal);
        $this->assertSame('対戦相手', $projectedLifesteal['side']);
        $this->assertSame('対戦相手', $projectedLifesteal['actor_name']);
        $this->assertSame('対戦相手', $projectedLifesteal['target_name']);
        $this->assertSame($expectedLifesteal, $projectedLifesteal['amount']);
        $this->assertFalse(collect($result->actionLog)->contains(
            static fn (array $row): bool => ($row['action'] ?? null) === 'self_regeneration',
        ));
    }

    public function test_enemy_lifesteal_after_a_guarded_hit_requires_surviving_the_counter(): void
    {
        $manifest = $this->awakeningCatalog(enemyWeaponPower: 1_000)->manifest();
        $manifest['enemies']['awakening_target']['modifiers'] = [
            'lifesteal_bps' => 400,
            'self_regeneration_target_hp_bps' => 0,
        ];
        $snapshot = $this->awakeningPlayerSnapshot(
            'guardianship_blue',
            gauge: 0,
            currentHp: null,
            aiRules: [['conditions' => [['type' => 'always']], 'action' => 'defend']],
        );
        $snapshot['modifiers']['counter_power_bps'] = 2_500;
        $fight = function (int $enemyMaxHp) use ($manifest, $snapshot): BuildCombatResult {
            $candidate = $manifest;
            $candidate['enemies']['awakening_target']['max_hp'] = $enemyMaxHp;

            return $this->model()->fightPlayerSnapshot(
                new AlphaV1BuildCatalog($candidate),
                $snapshot,
                'awakening_target',
                307,
                1,
                0,
            );
        };

        $survived = $fight(10_000);
        $survivingAttack = collect($survived->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $survivingCounter = collect($survived->actionLog)->firstWhere('action', 'counter');
        $survivingLifesteal = collect($survived->actionLog)->firstWhere('action', 'lifesteal');
        $this->assertIsArray($survivingAttack);
        $this->assertIsArray($survivingCounter);
        $this->assertIsArray($survivingLifesteal);
        $expectedLifesteal = min(
            $survivingCounter['amount'],
            intdiv($survivingAttack['amount'] * 400, 10_000),
        );
        $this->assertGreaterThan(0, $expectedLifesteal);
        $this->assertSame(-$expectedLifesteal, $survivingLifesteal['amount']);
        $this->assertSame(
            10_000 - $survivingCounter['amount'] + $expectedLifesteal,
            $survived->enemyRemainingHp,
        );

        $defeated = $fight(1);
        $this->assertSame('player', $defeated->winner);
        $this->assertSame(0, $defeated->enemyRemainingHp);
        $this->assertNotNull(collect($defeated->actionLog)->firstWhere('action', 'counter'));
        $this->assertNull(collect($defeated->actionLog)->firstWhere('action', 'lifesteal'));
    }

    public function test_fortress_strike_deals_one_vitality_attack_and_guards_the_next_direct_hit(): void
    {
        $result = $this->model()->fightPlayerSnapshot(
            $this->awakeningCatalog(enemyWeaponPower: 100),
            $this->awakeningPlayerSnapshot('guardianship_blue', techniqueKey: 'fortress_strike'),
            'awakening_target',
            223,
            1,
            0,
        );
        $attack = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'fortress_strike'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $enemyHit = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                && ($row['effect_type'] ?? null) === 'damage',
        );

        $this->assertIsArray($attack);
        $this->assertGreaterThan(0, $attack['amount']);
        $this->assertFalse($attack['critical']);
        $this->assertIsArray($enemyHit);
        $this->assertTrue($enemyHit['guarded']);
        $this->assertSame(1, collect($result->actionLog)->where('action', 'fortress_strike_guard')->count());
    }

    public function test_judgment_light_deals_damage_then_removes_one_dispellable_buff_only(): void
    {
        $snapshot = $this->awakeningPlayerSnapshot('blessing_green', currentHp: 1, techniqueKey: 'judgment_light');
        $snapshot['stats']['agility'] = 1;
        $result = $this->model()->fightPlayerSnapshot(
            $this->awakeningCatalog(enemyAgility: 75, enemyBuffs: true),
            $snapshot,
            'awakening_target',
            227,
            1,
            0,
        );
        $damageIndex = collect($result->actionLog)->search(
            static fn (array $row): bool => ($row['action'] ?? null) === 'judgment_light'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $dispelIndex = collect($result->actionLog)->search(
            static fn (array $row): bool => ($row['action'] ?? null) === 'judgment_light'
                && ($row['effect_type'] ?? null) === 'status_removed',
        );
        $roundEnd = collect($result->actionLog)->firstWhere('kind', 'round_end');

        $this->assertIsInt($damageIndex);
        $this->assertIsInt($dispelIndex);
        $this->assertLessThan($dispelIndex, $damageIndex);
        $this->assertSame(-1, $result->actionLog[$dispelIndex]['amount']);
        $this->assertIsArray($roundEnd);
        $this->assertSame(['protected_aegis'], array_column($roundEnd['enemy']['statuses'], 'key'));
    }

    public function test_formless_strike_uses_the_lower_effective_defense_and_physical_on_a_tie_once(): void
    {
        $snapshot = $this->awakeningPlayerSnapshot('free_black', techniqueKey: 'formless_strike');
        $cases = [
            ['physical' => 50_000, 'magical' => 100, 'expected' => 'miracle'],
            ['physical' => 100, 'magical' => 50_000, 'expected' => 'physical'],
            ['physical' => 100, 'magical' => 100, 'expected' => 'physical'],
        ];

        foreach ($cases as $index => $case) {
            $result = $this->model()->fightPlayerSnapshot(
                $this->awakeningCatalog(
                    enemyPhysicalDefense: $case['physical'],
                    enemyMagicalDefense: $case['magical'],
                ),
                $snapshot,
                'awakening_target',
                229 + $index,
                1,
                0,
            );
            $hits = collect($result->actionLog)
                ->filter(static fn (array $row): bool => ($row['action'] ?? null) === 'formless_strike'
                    && ($row['effect_type'] ?? null) === 'damage')
                ->values();
            $this->assertCount(1, $hits);
            $this->assertSame($case['expected'], $hits[0]['damage_category']);
        }
    }

    public function test_additional_direct_awakening_techniques_share_one_agility_combo_across_one_native_hit(): void
    {
        $cases = [
            ['growth_path' => 'martial_red', 'technique' => 'shura_bloodline'],
            ['growth_path' => 'guardianship_blue', 'technique' => 'fortress_strike'],
            ['growth_path' => 'blessing_green', 'technique' => 'judgment_light'],
            ['growth_path' => 'free_black', 'technique' => 'formless_strike'],
        ];

        foreach ($cases as $case) {
            $snapshot = $this->awakeningPlayerSnapshot(
                $case['growth_path'],
                techniqueKey: $case['technique'],
            );
            $snapshot['stats']['agility'] = 1_000;
            $comboResult = null;
            foreach (range(0, 500) as $seed) {
                $result = $this->model()->fightPlayerSnapshot(
                    $this->awakeningCatalog(enemyWeaponPower: 100),
                    $snapshot,
                    'awakening_target',
                    $seed,
                    1,
                    0,
                );
                $damageRows = collect($result->actionLog)
                    ->filter(static fn (array $row): bool => ($row['action'] ?? null) === $case['technique']
                        && ($row['effect_type'] ?? null) === 'damage')
                    ->values();
                if ($damageRows->contains(static fn (array $row): bool => isset($row['agility_combo_hits']))) {
                    $comboResult = [$result, $damageRows];

                    break;
                }
            }

            $this->assertIsArray($comboResult, $case['technique'].' must use the agility combo roll.');
            [$result, $damageRows] = $comboResult;
            $this->assertCount(1, $damageRows);
            $this->assertContains($damageRows[0]['agility_combo_hits'], [2, 3, 4]);
            $this->assertSame(1, $result->actionUsage['awakening_technique']);
        }
    }

    public function test_dullahan_hatred_stacks_each_round_caps_at_fifty_and_judgment_can_reset_it(): void
    {
        [$manifest] = $this->catalog();
        $contents = file_get_contents(dirname(__DIR__, 3).'/config/underground/balance/trial2-v1.json');
        $this->assertIsString($contents);
        $trial = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($trial);
        foreach (['skills', 'statuses', 'enemies'] as $section) {
            $manifest[$section] = array_merge($manifest[$section], $trial[$section]);
        }
        $catalog = new AlphaV1BuildCatalog($manifest);
        $hatred = $catalog->status('trial2_hatred');
        $this->assertSame([
            'label' => '憎悪',
            'dispellable' => true,
            'max_stacks' => 50,
            'damage_bps_per_stack' => 50,
        ], [
            'label' => $hatred['label'],
            'dispellable' => $hatred['dispellable'],
            'max_stacks' => $hatred['max_stacks'],
            'damage_bps_per_stack' => $hatred['effects'][0]['value_bps'],
        ]);

        $durable = $this->awakeningPlayerSnapshot('guardianship_blue', gauge: 0, currentHp: null);
        $durable['stats'] = [
            'vitality' => 1_000_000,
            'might' => 1,
            'finesse' => 1,
            'spirit' => 1,
            'agility' => 1,
        ];
        $longFight = $this->model()->fightPlayerSnapshot(
            $catalog,
            $durable,
            'trial2_headless_lord_of_judgment',
            239,
            52,
            0,
        );
        $applications = collect($longFight->actionLog)
            ->filter(static fn (array $row): bool => ($row['action'] ?? null) === 'status:trial2_hatred'
                && ($row['effect_type'] ?? null) === 'status_applied')
            ->values();
        $this->assertCount(52, $applications);
        $this->assertSame([1, 2, 3], $applications->take(3)->pluck('amount')->all());
        $this->assertSame([50, 50, 50], $applications->slice(49)->pluck('amount')->all());

        $judgment = $this->awakeningPlayerSnapshot(
            'blessing_green',
            currentHp: 1_000,
            techniqueKey: 'judgment_light',
        );
        $judgment['stats'] = [
            'vitality' => 1_000_000,
            'might' => 1,
            'finesse' => 1,
            'spirit' => 1,
            'agility' => 1_000,
        ];
        $resetFight = $this->model()->fightPlayerSnapshot(
            $catalog,
            $judgment,
            'trial2_headless_lord_of_judgment',
            241,
            2,
            0,
        );
        $resetApplications = collect($resetFight->actionLog)
            ->filter(static fn (array $row): bool => ($row['action'] ?? null) === 'status:trial2_hatred'
                && ($row['effect_type'] ?? null) === 'status_applied')
            ->pluck('amount')
            ->all();
        $removed = collect($resetFight->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'judgment_light'
                && ($row['effect_type'] ?? null) === 'status_removed',
        );
        $this->assertSame([1, 1], $resetApplications);
        $this->assertIsArray($removed);
        $this->assertSame(-1, $removed['amount']);
    }

    public function test_true_name_story_profile_is_a_short_deterministic_alpha_v1_tank_defeat(): void
    {
        [$manifest] = $this->catalog();
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $definition = $configuration['true_name_story_battle'];
        $manifest['builds'][$definition['build_key']] = $definition['build'];
        $manifest['enemies'][$definition['enemy_key']] = $definition['enemy'];
        $catalog = new AlphaV1BuildCatalog($manifest);
        $storyScaleBps = (new AlphaV1CombatRules)->storyBenchmarkScaleBps($definition['combat_level_equivalent']);

        $first = $this->model()->fight(
            $catalog,
            $definition['build_key'],
            $definition['enemy_key'],
            $definition['tier_key'],
            $definition['seed'],
            $definition['max_rounds'],
            null,
            [],
            $storyScaleBps,
        );
        $retry = $this->model()->fight(
            $catalog,
            $definition['build_key'],
            $definition['enemy_key'],
            $definition['tier_key'],
            $definition['seed'],
            $definition['max_rounds'],
            null,
            [],
            $storyScaleBps,
        );

        $this->assertSame($first->toArray(), $retry->toArray());
        $this->assertSame(1254, $definition['combat_level_equivalent']);
        $this->assertSame(1_137_700, $storyScaleBps);
        $this->assertSame('enemy', $first->winner);
        $this->assertSame(1, $first->rounds);
        $this->assertSame(0, $first->playerRemainingHp);
        $this->assertSame(568_850, $first->enemyRemainingHp);
        $this->assertGreaterThan(0, $first->damageDealt);
        $this->assertSame(500, $first->damageReceived);
        $actions = array_column($first->actionLog, 'action');
        $this->assertContains('enemy_counter_stance', $actions);
        $this->assertContains('counter', $actions);
        $this->assertContains('round_end', $actions);
        $evadedRows = array_values(array_filter(
            $first->actionLog,
            static fn (array $row): bool => ($row['evaded'] ?? false) === true,
        ));
        $this->assertSame([], $evadedRows);
        $barrierDamage = collect($first->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'precision_cut'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        $this->assertIsArray($barrierDamage);
        $this->assertSame($first->damageDealt, $barrierDamage['barrier_absorbed']);
        $this->assertArrayNotHasKey('agility_combo_hits', $barrierDamage);
        $this->assertSame([], $first->abnormalState);
    }

    /** @return array{array<string, mixed>, AlphaV1BuildCatalog, UndergroundBuildValidator} */
    private function catalog(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/config/underground/balance/foundation-v1.json');
        $this->assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $catalog = new AlphaV1BuildCatalog($manifest);

        return [$manifest, $catalog, new UndergroundBuildValidator(new AlphaV1CombatRules)];
    }

    private function awakeningCatalog(
        int $enemyHits = 1,
        int $enemyWeaponPower = 3_000,
        bool $enemyDefends = false,
        string $enemyCategory = 'physical',
        int $enemyAgility = 10,
        int $enemyPhysicalDefense = 100,
        int $enemyMagicalDefense = 100,
        bool $enemyBuffs = false,
    ): AlphaV1BuildCatalog {
        [$manifest] = $this->catalog();
        if ($enemyBuffs) {
            $manifest['statuses']['protected_aegis'] = [
                'label' => '不可侵加護',
                'disposition' => 'buff',
                'dispellable' => false,
                'duration_rounds' => 3,
                'stack_policy' => 'refresh',
                'max_stacks' => 1,
                'application_chance_bps' => 10_000,
                'effects' => [],
            ];
            $manifest['skills']['awakening_test_buffs'] = [
                'label' => '試験加護',
                'node_key' => null,
                'mp_cost' => 0,
                'cooldown' => 0,
                'required_weapon_styles' => [],
                'effects' => [
                    ['type' => 'apply_status', 'target' => 'self', 'status' => 'protected_aegis'],
                    ['type' => 'apply_status', 'target' => 'self', 'status' => 'regeneration'],
                ],
            ];
        }
        $manifest['enemies']['awakening_target'] = [
            'label' => '覚醒試験体',
            'boss' => false,
            'base_stats' => [
                'vitality' => 10,
                'might' => 80 - $enemyAgility,
                'finesse' => 5,
                'spirit' => 5,
                'agility' => $enemyAgility,
            ],
            'max_hp' => 10_000_000,
            'physical_defense' => $enemyPhysicalDefense,
            'magical_defense' => $enemyMagicalDefense,
            'weapon_power' => $enemyWeaponPower,
            'normal_attack' => [
                'type' => 'damage',
                'category' => $enemyCategory,
                'potency_bps' => 10_000,
                'stat_coefficients' => ['might' => 10_000],
                'weapon_coefficient_bps' => 10_000,
                'fixed' => 0,
                'target_max_hp_bps' => 0,
                'can_crit' => false,
                'dodgeable' => false,
                'hits' => $enemyHits,
            ],
            'skills' => $enemyBuffs ? ['awakening_test_buffs'] : [],
            'ai_rules' => [[
                'conditions' => [['type' => 'always']],
                'action' => $enemyBuffs ? 'skill:awakening_test_buffs' : ($enemyDefends ? 'defend' : 'normal_attack'),
            ]],
            'modifiers' => [],
        ];

        return new AlphaV1BuildCatalog($manifest);
    }

    /** @param list<string> $skills
     * @param  list<array<string, mixed>>|null  $aiRules
     * @return array<string, mixed>
     */
    private function awakeningPlayerSnapshot(
        string $growthPath,
        int $gauge = UndergroundAwakening::GAUGE_MAX,
        ?int $currentHp = 1,
        bool $unlocked = true,
        array $skills = [],
        ?array $aiRules = null,
        ?string $techniqueKey = null,
    ): array {
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $snapshot = [
            'key' => 'awakening_secretary',
            'label' => '覚醒秘書',
            'stats' => ['vitality' => 100, 'might' => 40, 'finesse' => 30, 'spirit' => 40, 'agility' => 200],
            'active_skills' => $skills,
            'ai_rules' => [
                [
                    'conditions' => [['type' => 'own_hp_lte', 'percent' => 20]],
                    'action' => 'awakening',
                ],
                ...($aiRules ?? [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']]),
            ],
            'modifiers' => [],
            'equipment' => $configuration['exploration']['starter_weapon'],
            'awakening' => [
                'unlocked' => $unlocked,
                'gauge' => $gauge,
                'message' => '魔力が覚醒秘書の全身を駆け巡る――！',
                'growth_path' => $growthPath,
                'technique_key' => $techniqueKey,
            ],
        ];
        if ($currentHp !== null) {
            $snapshot['current_hp'] = $currentHp;
        }

        return $snapshot;
    }

    /** @return list<int> */
    private function enemyDamageAmounts(BuildCombatResult $result): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) $row['amount'],
            array_filter(
                $result->actionLog,
                static fn (array $row): bool => ($row['side'] ?? null) === 'enemy'
                    && ($row['target_side'] ?? null) === 'player'
                    && ($row['effect_type'] ?? null) === 'damage',
            ),
        ));
    }

    private function model(): AlphaV1CombatModel
    {
        $rules = new AlphaV1CombatRules;

        return new AlphaV1CombatModel(
            $rules,
            new UndergroundBuildValidator($rules),
            new DeterministicEquipmentGenerator($rules),
            new PriorityCombatAi,
            new CanonicalCombatOrchestrator,
            new UndergroundAwakening,
        );
    }
}
