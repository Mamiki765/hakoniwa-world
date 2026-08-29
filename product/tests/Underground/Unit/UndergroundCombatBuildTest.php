<?php

namespace Tests\Underground\Unit;

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
