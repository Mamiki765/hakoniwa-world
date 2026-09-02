<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\CanonicalUndergroundExplorationCombat;
use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundBalanceSimulator;
use App\Application\Underground\UndergroundBuildBalanceSimulator;
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundReportSourceIdentity;
use App\Application\Underground\UndergroundTrialBalanceSimulator;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuiltInCombatAi;
use App\Domain\Underground\Combat\CanonicalCombatOrchestrator;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\PriorityCombatAi;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
use App\Domain\Underground\Combat\UndergroundCombatEngine;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundBalanceSimulatorTest extends TestCase
{
    public function test_trial_sequence_replays_the_same_ten_battles_with_current_build_and_recovery_contracts(): void
    {
        [$contents, $manifest] = $this->trialManifest();
        foreach ($manifest['enemies'] as &$enemy) {
            $enemy['max_hp'] = 1;
            $enemy['physical_defense'] = 0;
            $enemy['magical_defense'] = 0;
            $enemy['weapon_power'] = 1;
            $enemy['skills'] = [];
            $enemy['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']];
            $enemy['modifiers'] = [];
        }
        unset($enemy);
        $contents = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $simulator = $this->trialSimulator();
        $first = $simulator->replay($manifest, 'free_black:lv30:heal3000', 41);
        $second = $simulator->replay($manifest, 'free_black:lv30:heal3000', 41);

        $this->assertSame($first, $second);
        $this->assertTrue($first['result']['cleared']);
        $this->assertCount(10, $first['result']['battles']);
        $this->assertSame($first['result']['battles'][9]['remaining_hp'], $first['result']['final_hp']);
        $maxHp = $first['result']['build']['max_hp'];
        $nominalHeal = intdiv($maxHp * 3000, 10_000);
        foreach ($first['result']['battles'] as $index => $battle) {
            $expectedPostHeal = $index < 9
                ? min($maxHp, $battle['remaining_hp'] + $nominalHeal)
                : $battle['remaining_hp'];
            $this->assertSame(AlphaV1CombatRules::MAX_MP, $battle['starting_mp']);
            $this->assertSame($expectedPostHeal, $battle['post_heal_hp']);
            $this->assertLessThanOrEqual($maxHp, $battle['post_heal_hp']);
        }

        $report = $simulator->run(
            $manifest,
            $contents,
            hash('sha256', $contents),
            'config/underground/balance/trial1-v1.json',
            str_repeat('c', 40),
            false,
            41,
            2,
            'free_black:lv30:heal3000',
        );
        $this->assertTrue($report['trial_contract_passed']);
        $this->assertSame(AlphaV1CombatRules::MAX_MP, $report['mp_contract']['starts_each_battle_at']);
        $this->assertFalse($report['mp_contract']['persists_between_battles']);
        $this->assertSame([], $report['abnormal_seeds']);
        $this->assertSame(10, $report['scenarios'][0]['max_battles_observed']);
        $this->assertSame([20, 25, 30, 35], $report['checkpoints']);
        $this->assertSame([1.0, 1.2, 1.5, 2.0, 2.5, 3.0], array_column(
            $report['agility_balance']['ratio_curve'],
            'ratio',
        ));
        $this->assertSame([10_000, 10_063, 10_152, 10_278, 10_383, 10_467], array_column(
            $report['agility_balance']['ratio_curve'],
            'expected_damage_multiplier_bps',
        ));
        $this->assertSame([10_000, 9_928, 9_840, 9_734, 9_658, 9_600], array_column(
            $report['agility_balance']['ratio_curve'],
            'expected_incoming_damage_multiplier_bps',
        ));
        $this->assertCount(280, $report['agility_balance']['trial_one_ratios']);
        $this->assertSame([
            'trial_rat_vanguard' => 30,
            'trial_cave_hunter' => 22,
            'trial_corrosive_guard' => 10,
            'trial_regenerating_hulk' => 10,
            'trial_crystal_adept' => 18,
            'trial_fanatic_captain' => 20,
            'trial_razor_bat' => 30,
            'trial_ash_knight' => 16,
            'trial_gate_golem' => 10,
            'trial_wyvern' => 26,
        ], array_column($report['battle_sequence'], 'agility', 'key'));
        foreach ($report['builds'] as $levels) {
            foreach ($levels as $build) {
                $this->assertLessThanOrEqual(20, $build['skill_points_spent']);
                $this->assertSame([3, 3, 3], array_column($build['equipment']['items'], 'rank'));
            }
        }
        $vitalityBuild = $report['builds']['blessing_vitality_zero_agility']['lv20'];
        $this->assertSame(0, $vitalityBuild['allocated_stp']['agility']);
        $this->assertSame(964, $vitalityBuild['max_hp']);
        $this->assertSame([
            'iron_core_crystal_staff',
            'iron_breastplate',
            'spirit_accessory_rank_3',
        ], $vitalityBuild['equipment_keys']);
        $this->assertSame(0, $report['builds']['blessing_spirit_zero_agility']['lv20']['allocated_stp']['agility']);
        $this->assertSame(0, $report['builds']['martial_attack_zero_agility']['lv20']['allocated_stp']['agility']);
        $this->assertArrayNotHasKey('battles', $report['scenarios'][0]);
    }

    public function test_trial_wyvern_enters_its_healer_pressure_phase_at_round_40_without_losing_its_action(): void
    {
        [, $manifest] = $this->trialManifest();
        $this->assertSame(229, $manifest['enemies']['trial_wyvern']['magical_defense']);
        $this->assertSame(26, $manifest['enemies']['trial_wyvern']['base_stats']['agility']);
        $this->assertSame(10_000, $manifest['statuses']['wyvern_airborne']['effects'][0]['value_bps']);
        foreach ($manifest['enemies'] as &$enemy) {
            $enemy['max_hp'] = 1;
            $enemy['physical_defense'] = 0;
            $enemy['magical_defense'] = 0;
            $enemy['weapon_power'] = 1;
            $enemy['skills'] = [];
            $enemy['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']];
            $enemy['modifiers'] = [];
        }
        unset($enemy);
        $wyvern = &$manifest['enemies']['trial_wyvern'];
        $wyvern['base_stats'] = ['vitality' => 96, 'might' => 1, 'finesse' => 1, 'spirit' => 1, 'agility' => 1];
        $wyvern['max_hp'] = 1_000_000;
        $wyvern['normal_attack'] = [
            'type' => 'damage', 'category' => 'physical', 'potency_bps' => 0,
            'stat_coefficients' => [], 'weapon_coefficient_bps' => 0, 'fixed' => 1,
            'target_max_hp_bps' => 0, 'can_crit' => false, 'dodgeable' => false, 'hits' => 1,
        ];
        unset($wyvern);

        $result = $this->trialSimulator()
            ->replay($manifest, 'martial_red:lv30:heal2000', 41)['result'];
        $boss = $result['battles'][9];
        $transitionRows = array_values(array_filter(
            $boss['action_log'],
            static fn (array $row): bool => ($row['effect_type'] ?? null) === 'phase_transition',
        ));
        $enemyDecisions = array_values(array_filter(
            $boss['action_log'],
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['side'] ?? null) === 'enemy'
                && ($row['round'] ?? null) === 40,
        ));
        $round39 = array_values(array_filter(
            $boss['action_log'],
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end'
                && ($row['round'] ?? null) === 39,
        ))[0];
        $round40 = array_values(array_filter(
            $boss['action_log'],
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end'
                && ($row['round'] ?? null) === 40,
        ))[0];

        $this->assertSame('stalemate', $boss['winner']);
        $this->assertSame(1, $boss['phase_transition_count']);
        $this->assertCount(1, $transitionRows);
        $this->assertSame(40, $transitionRows[0]['round']);
        $this->assertSame('wyvern_airborne', $transitionRows[0]['status']);
        $this->assertSame(
            '天井が崩落し、ワイバーンは宙に舞い上がる……！',
            $transitionRows[0]['message'],
        );
        $this->assertCount(1, $enemyDecisions);
        $this->assertNotContains('wyvern_airborne', array_column($round39['enemy']['statuses'], 'key'));
        $this->assertContains('wyvern_airborne', array_column($round40['enemy']['statuses'], 'key'));
    }

    public function test_trial_sequence_stops_immediately_after_the_first_failed_battle(): void
    {
        [, $manifest] = $this->trialManifest();
        $firstEnemyKey = $manifest['battle_sequence'][0];
        $manifest['enemies'][$firstEnemyKey]['base_stats'] = [
            'vitality' => 1,
            'might' => 1,
            'finesse' => 1,
            'spirit' => 1,
            'agility' => 96,
        ];
        $manifest['enemies'][$firstEnemyKey]['max_hp'] = 1_000_000;
        $manifest['enemies'][$firstEnemyKey]['weapon_power'] = 100_000;
        $manifest['enemies'][$firstEnemyKey]['normal_attack']['fixed'] = 100_000;
        $manifest['enemies'][$firstEnemyKey]['skills'] = [];
        $manifest['enemies'][$firstEnemyKey]['ai_rules'] = [
            ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
        ];
        $result = $this->trialSimulator()
            ->replay($manifest, 'martial_red:lv30:heal2000', 7)['result'];

        $this->assertFalse($result['cleared']);
        $this->assertSame(1, $result['failed_battle']);
        $this->assertCount(1, $result['battles']);
        $this->assertFalse($result['continued_after_failure']);
        $this->assertSame(0, $result['battles'][0]['interbattle_heal']);
        $this->assertSame('defeat', $result['failure_result']);
        $this->assertSame(0, $result['final_hp']);
    }

    public function test_trial_withdrawal_preserves_remaining_hp_in_the_all_trial_average(): void
    {
        [, $manifest] = $this->trialManifest();
        $firstEnemyKey = $manifest['battle_sequence'][0];
        $manifest['enemies'][$firstEnemyKey]['max_hp'] = 1_000_000;
        $manifest['enemies'][$firstEnemyKey]['physical_defense'] = 0;
        $manifest['enemies'][$firstEnemyKey]['magical_defense'] = 0;
        $manifest['enemies'][$firstEnemyKey]['weapon_power'] = 1;
        $manifest['enemies'][$firstEnemyKey]['normal_attack'] = [
            'type' => 'damage', 'category' => 'physical', 'potency_bps' => 0,
            'stat_coefficients' => [], 'weapon_coefficient_bps' => 0, 'fixed' => 1,
            'target_max_hp_bps' => 0, 'can_crit' => false, 'dodgeable' => false, 'hits' => 1,
        ];
        $manifest['enemies'][$firstEnemyKey]['skills'] = [];
        $manifest['enemies'][$firstEnemyKey]['ai_rules'] = [
            ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
        ];
        $manifest['enemies'][$firstEnemyKey]['modifiers'] = [];
        $contents = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $simulator = $this->trialSimulator();
        $result = $simulator->replay($manifest, 'martial_red:lv30:heal2000', 41)['result'];

        $this->assertFalse($result['cleared']);
        $this->assertSame('stalemate', $result['failure_result']);
        $this->assertSame(1, $result['stalemate_count']);
        $this->assertGreaterThan(0, $result['battles'][0]['remaining_hp']);
        $this->assertSame($result['battles'][0]['remaining_hp'], $result['final_hp']);

        $scenario = $simulator->run(
            $manifest,
            $contents,
            hash('sha256', $contents),
            'config/underground/balance/trial1-v1.json',
            str_repeat('d', 40),
            false,
            41,
            1,
            'martial_red:lv30:heal2000',
        )['scenarios'][0];

        $this->assertSame(0, $scenario['clear_count']);
        $this->assertSame(1, $scenario['stalemate_count']);
        $this->assertSame((float) $result['final_hp'], $scenario['trial_end_average_hp_all_trials']);
    }

    public function test_alpha_v1_small_smoke_reports_build_damage_mp_scale_and_zero_abnormal_states(): void
    {
        $path = dirname(__DIR__, 3).'/config/underground/balance/foundation-v1.json';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $rules = new AlphaV1CombatRules;
        $generator = new DeterministicEquipmentGenerator($rules);
        $simulator = new UndergroundBuildBalanceSimulator(
            new AlphaV1CombatModel(
                $rules,
                new UndergroundBuildValidator($rules),
                $generator,
                new PriorityCombatAi,
                new CanonicalCombatOrchestrator,
                new UndergroundAwakening,
            ),
            new UndergroundBuildValidator($rules),
            $generator,
        );
        $report = $simulator->run(
            $manifest,
            $contents,
            hash('sha256', $contents),
            'config/underground/balance/foundation-v1.json',
            str_repeat('b', 40),
            false,
            0,
            8,
            'pressure',
        );

        $this->assertSame(AlphaV1CombatRules::IDENTITY, $report['combat_identity']);
        $this->assertSame(AlphaV1CombatRules::GENERATOR_IDENTITY, $report['generator_identity']);
        $this->assertSame(300, $report['selected_mp_natural_recovery']);
        $this->assertTrue($report['laboratory_contract_passed']);
        $this->assertNull($report['experiment_thresholds_passed']);
        $this->assertSame([], $report['abnormal_seeds']);
        $this->assertSame(100.0, $report['role_damage_ratios']['pure_attacker']);
        $this->assertCount(4, $report['pressure_benchmark']);
        $this->assertArrayHasKey('emergency_heal_available_rate', $report['pressure_benchmark']['pure_healer']['healer_mp_observation']);
        $this->assertArrayHasKey('crystal_cycle_restored_mp', $report['pressure_benchmark']['pure_healer']['healer_mp_observation']);
        $this->assertNull($report['appropriate_encounter']);
        $this->assertNull($report['mp_economy_sweep']);
        $this->assertSame([
            'php',
            'artisan',
            'underground:balance',
            '--manifest=config/underground/balance/foundation-v1.json',
            '--seed-start=0',
            '--count=8',
            '--commit-sha='.str_repeat('b', 40),
            '--scenario=pressure',
        ], $report['reproduction_arguments']);

        $sidegrade = $simulator->run(
            $manifest,
            $contents,
            hash('sha256', $contents),
            'config/underground/balance/foundation-v1.json',
            str_repeat('b', 40),
            false,
            0,
            8,
            'sidegrade',
        )['sidegrade_observation'];
        $this->assertSame(8, $sidegrade['combat_observation']['low_item_level_unique']['iterations']);
        $this->assertGreaterThan(
            $sidegrade['combat_observation']['low_item_level_unique']['mean_damage_per_round'],
            $sidegrade['combat_observation']['higher_item_level_epic']['mean_damage_per_round'],
        );
        $this->assertGreaterThan(
            $sidegrade['combat_observation']['higher_item_level_epic']['effective_healing_average'],
            $sidegrade['combat_observation']['low_item_level_unique']['effective_healing_average'],
        );
        $this->assertSame([], $sidegrade['combat_observation']['low_item_level_unique']['abnormal_seeds']);
    }

    public function test_small_smoke_generates_a_reproducible_laboratory_summary_without_raw_logs(): void
    {
        [$contents, $manifest] = $this->manifest();
        $manifestPath = 'C:/tmp/Underground experiment;$seed.json';
        $report = $this->simulator()->run(
            $manifest,
            $contents,
            hash('sha256', $contents),
            $manifestPath,
            str_repeat('a', 40),
            false,
            0,
            32,
        );
        $replay = $this->simulator()->run(
            $manifest,
            $contents,
            hash('sha256', $contents),
            $manifestPath,
            str_repeat('a', 40),
            false,
            0,
            32,
        );

        $this->assertSame($report, $replay);
        $this->assertTrue($report['laboratory_contract_passed']);
        $this->assertTrue($report['semantic_contract_passed']);
        $this->assertNull($report['experiment_thresholds_passed']);
        $this->assertSame(hash('sha256', $contents), $report['manifest_hash']);
        $this->assertSame($contents, $report['manifest_contents']);
        $this->assertSame($manifest, json_decode($report['manifest_contents'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(UndergroundCombatRules::SIMULATOR_VERSION, $report['simulator_version']);
        $this->assertCount(4, $report['scenarios']);
        $this->assertCount(3, $report['semantic_observations']);
        foreach ($report['scenarios'] as $scenario) {
            $this->assertSame(32, $scenario['iterations']);
            $this->assertSame([], $scenario['abnormal_seeds']);
            $this->assertSame(0.0, $scenario['metrics']['abnormal_rate']);
            $this->assertNull($scenario['experiment_thresholds']);
            $this->assertNull($scenario['experiment_thresholds_passed']);
            $this->assertArrayNotHasKey('action_log', $scenario);
            $this->assertSame('--manifest='.$manifestPath, $scenario['reproduction_arguments'][3]);
            $this->assertStringStartsWith('--replay-seed=', $scenario['reproduction_arguments'][5]);
            $this->assertArrayNotHasKey('reproduction_command_template', $scenario);
        }

        $byId = array_column($report['scenarios'], null, 'id');
        $this->assertGreaterThan(
            $byId['standard_enemy']['metrics']['enemy_first_action_rate'],
            $byId['fast_enemy']['metrics']['enemy_first_action_rate'],
        );
        $this->assertGreaterThan(
            $byId['standard_enemy']['metrics']['median_rounds'],
            $byId['armored_enemy']['metrics']['median_rounds'],
        );
        $this->assertGreaterThan(0, $byId['telegraphed_threat']['metrics']['enemy_telegraph_usage']);
        $this->assertSame(
            $byId['telegraphed_threat']['metrics']['enemy_heavy_strike_usage'],
            $byId['telegraphed_threat']['metrics']['guarded_enemy_heavy_strike_usage'],
        );
    }

    public function test_manifest_labels_initial_win_rates_as_observations_not_balance_targets(): void
    {
        [, $manifest] = $this->manifest();

        $this->assertFalse($manifest['laboratory_scope']['win_rate_observations_are_balance_targets']);
        $this->assertFalse($manifest['laboratory_scope']['future_balance_must_preserve_observed_win_rates']);
        $this->assertTrue($manifest['laboratory_scope']['retuning_before_first_playable_is_allowed']);
        $this->assertSame(
            [0.7924, 0.561, 0.7538, 0.6783],
            array_map(
                static fn (array $scenario): float => $scenario['laboratory_observation']['observed_win_rate'],
                $manifest['scenarios'],
            ),
        );
        foreach ($manifest['scenarios'] as $scenario) {
            $this->assertArrayNotHasKey('acceptance', $scenario);
            $this->assertFalse($scenario['laboratory_observation']['is_player_facing_balance_target']);
            $this->assertTrue($scenario['laboratory_observation']['future_retuning_allowed']);
        }
    }

    public function test_replay_seed_returns_the_same_detailed_action_log(): void
    {
        [, $manifest] = $this->manifest();
        $first = $this->simulator()->replay($manifest, 'telegraphed_threat', 41);
        $second = $this->simulator()->replay($manifest, 'telegraphed_threat', 41);

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first['result']['action_log']);
        $this->assertSame(41, $first['result']['seed']);
    }

    public function test_threshold_failure_is_reported_without_deleting_the_scenario(): void
    {
        [, $manifest] = $this->manifest();
        $manifest['scenarios'] = [$manifest['scenarios'][0]];
        $manifest['scenarios'][0]['id'] = 'synthetic_stress';
        unset($manifest['scenarios'][0]['laboratory_observation']);
        $manifest['scenarios'][0]['acceptance'] = [
            'win_rate_min' => 0,
            'win_rate_max' => 0,
            'median_rounds_min' => 1,
            'median_rounds_max' => 30,
            'stalemate_rate_max' => 1,
            'abnormal_rate_max' => 0,
        ];
        $manifestContents = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $report = $this->simulator()->run(
            $manifest,
            $manifestContents,
            hash('sha256', $manifestContents),
            'manifest.json',
            str_repeat('a', 40),
            false,
            0,
            16,
            'synthetic_stress',
        );

        $this->assertTrue($report['laboratory_contract_passed']);
        $this->assertFalse($report['experiment_thresholds_passed']);
        $this->assertSame('synthetic_stress', $report['scenarios'][0]['id']);
        $this->assertFalse($report['scenarios'][0]['experiment_thresholds_passed']);
        $this->assertNotEmpty($report['scenarios'][0]['threshold_violations']);
    }

    public function test_manifest_rejects_a_surface_identity_or_multi_actor_party(): void
    {
        [, $manifest] = $this->manifest();
        $manifest['rules_identity'] = 'hakoniwa-2s-plus-v18';

        try {
            $this->simulator()->replay($manifest, 'standard_enemy', 0);
            $this->fail('Expected the surface Ruleset identity to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('rules identity', $exception->getMessage());
        }

        [, $manifest] = $this->manifest();
        $manifest['scenarios'][0]['party'][] = $manifest['scenarios'][0]['party'][0];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one Secretary actor');
        $this->simulator()->replay($manifest, 'standard_enemy', 0);
    }

    public function test_report_source_identity_accepts_override_only_without_detectable_git_metadata(): void
    {
        $identity = new UndergroundReportSourceIdentity;
        $sha = str_repeat('a', 40);

        $this->assertSame($sha, $identity->resolve($sha, $sha, false));
        $this->assertSame($sha, $identity->resolve($sha, null, null));
        $this->assertSame($sha, $identity->resolve(null, $sha, false));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provide --commit-sha when Git metadata is unavailable');
        $identity->resolve(null, null, null);
    }

    public function test_report_source_identity_rejects_explicit_sha_that_differs_from_detected_head(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match the detected Git HEAD');

        (new UndergroundReportSourceIdentity)->resolve(str_repeat('a', 40), str_repeat('b', 40), false);
    }

    public function test_report_source_identity_rejects_dirty_or_unverifiable_detected_git_state(): void
    {
        $identity = new UndergroundReportSourceIdentity;
        $sha = str_repeat('a', 40);

        foreach ([true, null] as $dirty) {
            try {
                $identity->resolve(null, $sha, $dirty);
                $this->fail('Detected Git metadata must have a verified clean worktree.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Git worktree', $exception->getMessage());
            }
        }
    }

    /** @return array{string, array<string, mixed>} */
    private function manifest(): array
    {
        $path = dirname(__DIR__, 3).'/config/underground/balance/foundation-v0.json';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return [$contents, $manifest];
    }

    private function simulator(): UndergroundBalanceSimulator
    {
        $rules = new UndergroundCombatRules;

        return new UndergroundBalanceSimulator(
            $rules,
            new UndergroundCombatEngine($rules, new BuiltInCombatAi),
        );
    }

    /** @return array{string, array<string, mixed>} */
    private function trialManifest(): array
    {
        $path = dirname(__DIR__, 3).'/config/underground/balance/trial1-v1.json';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return [$contents, $manifest];
    }

    private function trialSimulator(): UndergroundTrialBalanceSimulator
    {
        $rules = new AlphaV1CombatRules;
        $validator = new UndergroundBuildValidator($rules);
        $model = new AlphaV1CombatModel(
            $rules,
            $validator,
            new DeterministicEquipmentGenerator($rules),
            new PriorityCombatAi,
            new CanonicalCombatOrchestrator,
            new UndergroundAwakening,
        );

        return new UndergroundTrialBalanceSimulator(
            new CanonicalUndergroundExplorationCombat($model),
            new UndergroundAlphaV1PlayerCatalog($rules, $validator),
            new UndergroundEquipmentCatalog,
            $rules,
        );
    }
}
