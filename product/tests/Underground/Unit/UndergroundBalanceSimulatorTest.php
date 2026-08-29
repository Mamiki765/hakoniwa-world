<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundBalanceSimulator;
use App\Application\Underground\UndergroundBuildBalanceSimulator;
use App\Application\Underground\UndergroundReportSourceIdentity;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuiltInCombatAi;
use App\Domain\Underground\Combat\CanonicalCombatOrchestrator;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\PriorityCombatAi;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
use App\Domain\Underground\Combat\UndergroundCombatEngine;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UndergroundBalanceSimulatorTest extends TestCase
{
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
        $this->assertNull($report['appropriate_encounter']);
        $this->assertNull($report['mp_economy_sweep']);

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
}
