<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1BattleProjector;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\CanonicalCombatOrchestrator;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\PriorityCombatAi;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
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
        foreach ($catalog->buildKeys() as $buildKey) {
            $build = $validator->validate($catalog, $buildKey);
            $this->assertSame(120, $build['points_spent']);
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
            $this->assertSame(100, $points);
            $allTreePoints += $points;
        }
        $this->assertSame(300, $allTreePoints);
        $this->assertGreaterThan($manifest['balance']['build_point_budget'], $allTreePoints);
        $this->assertSame('rapier', $validator->validate($catalog, 'balanced')['weapon_style']);
        $this->assertContains('mending_prayer', $validator->validate($catalog, 'balanced')['active_skills']);

        $mutualTierBootstrap = $manifest;
        $mutualTierBootstrap['builds']['pure_attacker']['allocations'] = [
            'martial_precision_cut' => 1,
            'martial_weapon_mastery' => 5,
            'martial_critical_training' => 5,
            'martial_dagger_flurry' => 1,
            'martial_armor_break' => 1,
            'martial_blood_return' => 3,
        ];
        try {
            $validator->validate(new AlphaV1BuildCatalog($mutualTierBootstrap), 'pure_attacker');
            $this->fail('Nodes behind one tier gate must not unlock each other.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('tier gate is not met', $exception->getMessage());
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
        $this->assertSame($nonlethal->damagePrevented, $overkill->damagePrevented);

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

    public function test_heal_barrier_and_source_capped_periodic_damage_use_deterministic_status_timing(): void
    {
        [$manifest, $catalog] = $this->catalog();
        $tank = $this->model()->fight($catalog, 'pure_tank', 'pressure_construct', 'early', 4, 20);
        $this->assertGreaterThan(0, $tank->effectiveHealing);
        $this->assertGreaterThan(0, $tank->damagePrevented);
        $this->assertGreaterThan(0, $tank->actionUsage['unbroken_retort']);

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

        $withPeriodicAffix = $this->model()->fight($catalog, 'balanced', 'crystal_warden', 'early', 2, 30);
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
        $barrierSettlement['builds']['pure_tank']['base_stats'] = [
            'vitality' => 1, 'might' => 33, 'finesse' => 32, 'spirit' => 1, 'agility' => 33,
        ];
        $barrierSettlement['builds']['pure_tank']['equipment'] = [];
        $barrierSettlement['builds']['pure_tank']['ai_rules'] = [[
            'conditions' => [['type' => 'always']], 'action' => 'skill:counter_stance',
        ]];
        $barrierSettlement['skills']['counter_stance']['effects'][0]['fixed'] = 10_000;
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
                'fixed' => 400,
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
        $this->assertNotEmpty($periodicRows, json_encode($settled->actionLog, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $periodicRows[0]['amount']);
        $this->assertGreaterThan($periodicRows[0]['actor_hp'], $periodicRows[0]['barrier_absorbed']);
        $projected = (new UndergroundAlphaV1BattleProjector)->project($settled, $barrierCatalog);
        $projectedTypes = collect($projected['rounds'])
            ->flatMap(static fn (array $round): array => $round['actions'])
            ->pluck('type')
            ->all();
        $this->assertContains('barrier', $projectedTypes);
        $this->assertContains('status_applied', $projectedTypes);
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
        $this->assertSame(1, $fallback->actionUsage['normal_attack']);
        $this->assertSame(1, $fallback->actionUsage['ai_fallback']);
        $this->assertSame(1, $fallback->skillUnavailableDueToMp);

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
        $this->assertSame(4, $first->damageDealt);
        $this->assertSame(500, $first->damageReceived);
        $actions = array_column($first->actionLog, 'action');
        $this->assertContains('counter_stance', $actions);
        $this->assertContains('counter', $actions);
        $this->assertContains('round_end', $actions);
        $counter = $first->actionLog[array_key_last($first->actionLog) - 1];
        $this->assertSame('counter', $counter['action']);
        $this->assertGreaterThan(500, $counter['amount']);
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

    private function model(): AlphaV1CombatModel
    {
        $rules = new AlphaV1CombatRules;

        return new AlphaV1CombatModel(
            $rules,
            new UndergroundBuildValidator($rules),
            new DeterministicEquipmentGenerator($rules),
            new PriorityCombatAi,
            new CanonicalCombatOrchestrator,
        );
    }
}
