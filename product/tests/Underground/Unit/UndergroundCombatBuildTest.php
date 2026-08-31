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
use App\Domain\Underground\Combat\UndergroundAwakening;
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
                ['type' => 'taunt', 'target' => 'enemy'],
                $manifest['skills'][$skillKey]['effects'][0],
            );
        }
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
            'radiant_judgment', 'crystal_aegis', 'cleansing_wave', 'mending_prayer', 'holy_bolt',
        ];
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
        $this->assertSame(7, $skill['cooldown']);
        $this->assertSame(3000, $skill['effects'][0]['amount']);
        $this->assertSame('skill:mending_prayer', $manifest['builds']['pure_healer']['ai_rules'][0]['action']);
        $this->assertSame('skill:crystal_cycle', $manifest['builds']['pure_healer']['ai_rules'][1]['action']);

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

    public function test_player_shop_equipment_catalog_has_exact_stable_ranked_common_progression(): void
    {
        $catalog = require dirname(__DIR__, 3).'/config/underground-equipment.php';
        $definitions = $catalog['definitions'];
        $shop = array_filter(
            $definitions,
            static fn (array $definition): bool => $definition['shop_sold'],
        );

        $this->assertSame('secretary-underground-shop-equipment-alpha-v1', $catalog['catalog_identity']);
        $this->assertSame([500, 50, 31, 30], [
            $catalog['vault_capacity'],
            $catalog['page_size'],
            count($definitions),
            count($shop),
        ]);
        $this->assertSame([
            'starter_knife',
            'iron_dagger', 'steel_dagger', 'polished_steel_dagger',
            'bronze_rapier', 'iron_rapier', 'steel_rapier',
            'iron_longsword', 'steel_longsword', 'reinforced_longsword',
            'wood_crystal_staff', 'oak_crystal_staff', 'iron_core_crystal_staff',
            'leather_armor', 'reinforced_leather_armor', 'iron_breastplate',
            'vitality_accessory_rank_1', 'vitality_accessory_rank_2', 'vitality_accessory_rank_3',
            'might_accessory_rank_1', 'might_accessory_rank_2', 'might_accessory_rank_3',
            'finesse_accessory_rank_1', 'finesse_accessory_rank_2', 'finesse_accessory_rank_3',
            'spirit_accessory_rank_1', 'spirit_accessory_rank_2', 'spirit_accessory_rank_3',
            'agility_accessory_rank_1', 'agility_accessory_rank_2', 'agility_accessory_rank_3',
        ], array_keys($definitions));
        $starter = $definitions['starter_knife'];
        $this->assertSame([
            '護身用ナイフ', 'weapon', 'dagger', 0, 1, 'common', null,
            false, false, 24, 0, 0, 0,
            ['vitality' => 1, 'might' => 1, 'finesse' => 1, 'spirit' => 1, 'agility' => 1],
            [], [], null,
        ], [
            $starter['name'], $starter['category'], $starter['weapon_style'],
            $starter['rank'], $starter['item_level'], $starter['rarity'], $starter['buy_price'],
            $starter['shop_sold'], $starter['sellable'], $starter['weapon_power'],
            $starter['physical_defense'], $starter['magical_defense'], $starter['max_hp'],
            $starter['stats'], $starter['modifiers'], $starter['affixes'], $starter['unique_effect'],
        ]);

        $weapons = array_filter($shop, static fn (array $item): bool => $item['category'] === 'weapon');
        $armors = array_values(array_filter($shop, static fn (array $item): bool => $item['category'] === 'armor'));
        $accessories = array_filter($shop, static fn (array $item): bool => $item['category'] === 'accessory');
        $this->assertSame([12, 3, 15], [count($weapons), count($armors), count($accessories)]);
        $this->assertSame(
            ['dagger', 'rapier', 'longsword', 'crystal_staff'],
            array_values(array_unique(array_column($weapons, 'weapon_style'))),
        );
        $this->assertNotContains('shield', array_column($weapons, 'weapon_style'));

        foreach (['dagger', 'rapier', 'longsword', 'crystal_staff'] as $style) {
            $series = array_values(array_filter(
                $weapons,
                static fn (array $item): bool => $item['weapon_style'] === $style,
            ));
            $this->assertSame([1, 2, 3], array_column($series, 'rank'));
            $this->assertSame([1, 10, 20], array_column($series, 'item_level'));
            $this->assertSame([120, 360, 1_000], array_column($series, 'buy_price'));
            $this->assertLessThan($series[1]['weapon_power'], $series[0]['weapon_power']);
            $this->assertLessThan($series[2]['weapon_power'], $series[1]['weapon_power']);
        }
        $this->assertSame([1, 2, 3], array_column($armors, 'rank'));
        $this->assertSame([100, 300, 900], array_column($armors, 'buy_price'));
        foreach (['physical_defense', 'magical_defense', 'max_hp'] as $field) {
            $this->assertLessThan($armors[1][$field], $armors[0][$field]);
            $this->assertLessThan($armors[2][$field], $armors[1][$field]);
        }

        foreach (AlphaV1CombatRules::STATS as $stat) {
            $series = array_values(array_filter(
                $accessories,
                static fn (array $item, string $key): bool => str_starts_with($key, $stat.'_'),
                ARRAY_FILTER_USE_BOTH,
            ));
            $this->assertSame([1, 2, 3], array_column($series, 'rank'));
            $this->assertSame([60, 180, 600], array_column($series, 'buy_price'));
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
        $crystalBug = $configuration['exploration']['encounters']['crystal_bug']['enemy'];
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
        $skillManifest['enemies']['crystal_bug'] = $configuration['exploration']['encounters']['crystal_bug']['enemy'];
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
            skills: ['radiant_judgment', 'severing_bleed'],
            aiRules: [
                ['conditions' => [['type' => 'skill_ready', 'skill' => 'radiant_judgment']], 'action' => 'skill:radiant_judgment'],
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
        $this->assertSame(2, $result->actionUsage['radiant_judgment']);
        $this->assertSame(1, $result->actionUsage['severing_bleed']);
        $this->assertSame(AlphaV1CombatRules::MAX_MP - 2_400, $result->finalMp);
        $this->assertSame(0, $result->awakening['gauge_after']);
        $this->assertTrue($result->awakening['triggered']);
        $this->assertTrue($result->awakening['technique']['used']);
        $this->assertSame(1, $result->actionUsage['awakening_technique']);
        $lastRound = collect($result->actionLog)->last(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'round_end',
        );
        $this->assertIsArray($lastRound);
        $this->assertSame(4, $lastRound['player']['cooldowns']['radiant_judgment']);
        $this->assertSame(0, $lastRound['player']['cooldowns']['severing_bleed']);
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
        $counterRows = array_values(array_filter(
            $first->actionLog,
            static fn (array $row): bool => $row['action'] === 'counter',
        ));
        $counter = $counterRows[array_key_last($counterRows)];
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

    private function awakeningCatalog(
        int $enemyHits = 1,
        int $enemyWeaponPower = 3_000,
        bool $enemyDefends = false,
        string $enemyCategory = 'physical',
        int $enemyAgility = 10,
    ): AlphaV1BuildCatalog {
        [$manifest] = $this->catalog();
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
            'physical_defense' => 100,
            'magical_defense' => 100,
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
            'skills' => [],
            'ai_rules' => [[
                'conditions' => [['type' => 'always']],
                'action' => $enemyDefends ? 'defend' : 'normal_attack',
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
    ): array {
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $snapshot = [
            'key' => 'awakening_secretary',
            'label' => '覚醒秘書',
            'stats' => ['vitality' => 100, 'might' => 40, 'finesse' => 30, 'spirit' => 40, 'agility' => 200],
            'active_skills' => $skills,
            'ai_rules' => $aiRules ?? [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']],
            'modifiers' => [],
            'equipment' => $configuration['exploration']['starter_weapon'],
            'awakening' => [
                'unlocked' => $unlocked,
                'gauge' => $gauge,
                'message' => '魔力が覚醒秘書の全身を駆け巡る――！',
                'growth_path' => $growthPath,
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
