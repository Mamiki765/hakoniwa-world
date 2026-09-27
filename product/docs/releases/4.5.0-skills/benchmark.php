<?php

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$source = json_decode(file_get_contents(config_path('underground/balance/trial3-v1.json')), true, flags: JSON_THROW_ON_ERROR);
$players = $app->make(UndergroundAlphaV1PlayerCatalog::class);
$equipment = $app->make(UndergroundEquipmentCatalog::class);
$generator = $app->make(UndergroundRuntimeEquipmentGenerator::class);
$model = $app->make(AlphaV1CombatModel::class);

// A reference loadout is a measurement input, not another skill definition.
$reference = [
    'martial_100' => ['base' => 'martial_red', 'nodes' => null],
    'martial_100_sustain' => ['base' => 'martial_sustain', 'nodes' => null],
    'guardian_100' => ['base' => 'guardianship_blue', 'nodes' => null],
    'miracle_100' => ['base' => 'blessing_green', 'nodes' => null],
    'martial_100_bleed' => ['base' => 'martial_red', 'nodes' => [
        'martial_precision_cut' => 5, 'martial_dagger_flurry' => 1, 'martial_severing_bleed' => 2,
        'martial_sweeping_cut' => null, 'martial_armor_break' => null, 'martial_iaido_cut' => 3,
        'martial_executioner' => 4,
    ]],
    'martial_100_bleed_sustain' => ['base' => 'martial_red', 'nodes' => [
        'martial_precision_cut' => 5, 'martial_dagger_flurry' => 1, 'martial_severing_bleed' => 2,
        'martial_sweeping_cut' => null, 'martial_armor_break' => null, 'martial_iaido_cut' => 3,
        'martial_draining_cut' => 4,
    ]],
    'guardian_100_standard' => ['base' => 'guardianship_blue', 'nodes' => [
        'guardianship_shield_bash' => 1, 'guardianship_counter_stance' => 2,
        'guardianship_renewing_guard' => 3, 'guardianship_bulwark_strike' => 4,
        'guardianship_rallying_cry' => null, 'guardianship_protective_oath' => null,
        'guardianship_fortress' => 5,
    ]],
    'miracle_100_standard' => ['base' => 'blessing_green', 'nodes' => [
        'miracle_mending_prayer' => 1, 'miracle_regeneration' => 2,
        'miracle_lucid_dream' => 3, 'miracle_crystal_cycle' => 4,
        'miracle_holy_bolt' => null, 'miracle_holy_nova' => null, 'miracle_holy_lance' => 5,
    ]],
    'miracle_100_two_attack' => ['base' => 'blessing_green', 'nodes' => [
        'miracle_mending_prayer' => 1, 'miracle_regeneration' => 2,
        'miracle_lucid_dream' => 3, 'miracle_crystal_cycle' => null,
        'miracle_holy_bolt' => null, 'miracle_holy_nova' => 4, 'miracle_holy_lance' => 5,
    ]],
    'martial_140_bleed' => ['base' => 'martial_red', 'nodes' => [
        'martial_precision_cut' => null, 'martial_dagger_flurry' => 1, 'martial_severing_bleed' => 2,
        'martial_sweeping_cut' => null, 'martial_armor_break' => null, 'martial_iaido_cut' => 4,
        'martial_executioner' => 5, 'martial_wound_opening' => 3,
    ]],
    'martial_140_dispel' => ['base' => 'martial_red', 'nodes' => [
        'martial_precision_cut' => null, 'martial_sweeping_cut' => null, 'martial_armor_break' => 2,
        'martial_iaido_cut' => 3, 'martial_whirlwind' => 1, 'martial_executioner' => 4,
        'martial_draining_cut' => null, 'martial_dispelling_cut' => 5,
    ]],
    'martial_140_dispel_sustain' => ['base' => 'martial_red', 'nodes' => [
        'martial_precision_cut' => null, 'martial_dagger_flurry' => 1, 'martial_severing_bleed' => 2,
        'martial_sweeping_cut' => null, 'martial_armor_break' => null, 'martial_iaido_cut' => 3,
        'martial_draining_cut' => 4, 'martial_quick_stab' => null, 'martial_dispelling_cut' => 5,
    ]],
    'martial_140_wound_sustain' => ['base' => 'martial_red', 'nodes' => [
        'martial_precision_cut' => null, 'martial_dagger_flurry' => 1, 'martial_severing_bleed' => 2,
        'martial_sweeping_cut' => null, 'martial_armor_break' => null, 'martial_iaido_cut' => 4,
        'martial_draining_cut' => 5, 'martial_quick_stab' => null, 'martial_wound_opening' => 3,
    ]],
    'guardian_140_cover' => ['base' => 'guardianship_blue', 'nodes' => [
        'guardianship_shield_bash' => 4, 'guardianship_rallying_cry' => null,
        'guardianship_counter_stance' => null, 'guardianship_protective_oath' => null,
        'guardianship_fortress' => 2, 'guardianship_bulwark_strike' => 5,
        'guardianship_retort' => null, 'guardianship_renewing_guard' => 3,
        'guardianship_emergency_cover' => 1,
    ]],
    'guardian_140_barrier' => ['base' => 'guardianship_blue', 'nodes' => [
        'guardianship_shield_bash' => 4, 'guardianship_rallying_cry' => null,
        'guardianship_counter_stance' => 2, 'guardianship_protective_oath' => null,
        'guardianship_fortress' => null, 'guardianship_bulwark_strike' => 5,
        'guardianship_retort' => null, 'guardianship_renewing_guard' => 3,
        'guardianship_barrier_crash' => 1,
    ]],
    'miracle_140_group' => ['base' => 'blessing_green', 'nodes' => [
        'miracle_mending_prayer' => 1, 'miracle_regeneration' => null,
        'miracle_resurrection' => null, 'miracle_heart_of_mercy' => null,
        'miracle_holy_bolt' => null, 'miracle_holy_nova' => 4, 'miracle_holy_lance' => 5,
        'miracle_harmony_heal' => 2, 'miracle_lucid_dream' => 3, 'miracle_crystal_cycle' => null,
    ]],
    'miracle_140_hybrid' => ['base' => 'blessing_green', 'nodes' => [
        'miracle_mending_prayer' => 1, 'miracle_regeneration' => 2,
        'miracle_resurrection' => null, 'miracle_heart_of_mercy' => null,
        'miracle_holy_bolt' => null, 'miracle_holy_nova' => null, 'miracle_holy_lance' => 4,
        'miracle_healing_ray' => 5, 'miracle_lucid_dream' => 3, 'miracle_crystal_cycle' => null,
    ]],
];

function allocatedStp(int $entitlement, array $weights): array
{
    $points = $remainders = [];
    foreach (AlphaV1CombatRules::STATS as $stat) {
        $value = $entitlement * $weights[$stat];
        $points[$stat] = intdiv($value, 10000);
        $remainders[$stat] = $value % 10000;
    }
    $order = AlphaV1CombatRules::STATS;
    usort($order, static fn (string $a, string $b): int => $remainders[$b] <=> $remainders[$a]);
    for ($left = $entitlement - array_sum($points), $index = 0; $left > 0; $left--, $index++) {
        $points[$order[$index % count($order)]]++;
    }

    return $points;
}

function commonStpWeights(string $growthPath): array
{
    // Rounded equal-person averages of anonymous Lv300-700 production allocations
    // for martial (n=3) and blessing (n=4), observed on 2026-09-27.
    // Guardian uses the same offense/agility shares and moves finesse to vitality.
    $vitality = $growthPath === 'guardianship_blue' ? 3350 : 2100;
    $finesse = $growthPath === 'guardianship_blue' ? 0 : 1250;

    return [
        'vitality' => $vitality,
        'might' => $growthPath === 'blessing_green' ? 0 : 5200,
        'finesse' => $finesse,
        'spirit' => $growthPath === 'blessing_green' ? 5200 : 0,
        'agility' => 1450,
    ];
}

function measuredBuild(array $source, string $key, array $spec, int $level, UndergroundAlphaV1PlayerCatalog $players,
    UndergroundEquipmentCatalog $equipment, UndergroundRuntimeEquipmentGenerator $generator,
    string $equipmentStage = 'before_boss'): array
{
    $base = $source['builds'][$spec['base']];
    if (isset($base['inherits'])) {
        $base = array_replace($source['builds'][$base['inherits']], $base);
    }
    $allocations = $spec['nodes'] === null ? $base['skill_allocations'] : array_map(
        static fn (?int $slot): array => ['rank' => 1, 'active_slot' => $slot], $spec['nodes'],
    );
    $catalog = $players->laboratoryCatalog();
    $spent = 0;
    foreach ($allocations as $nodeKey => $allocation) {
        $node = $catalog->node($nodeKey)['node'];
        $spent += $node['point_cost_per_rank'];
        $required = $node['invested_points_required'];
        if ($players->investedBelowGate($catalog, $allocations, $catalog->node($nodeKey)['tree'], $required) < $required
            || ($node['prerequisite'] !== null && ! isset($allocations[$node['prerequisite']]))) {
            throw new RuntimeException("Illegal reference route {$nodeKey}");
        }
    }
    $budget = str_contains($key, '_140_') ? 140 : 100;
    if ($spent > $budget) {
        throw new RuntimeException("Reference route exceeds {$budget} SP");
    }
    $equipped = [];
    foreach ($base['equipment_stages'][$equipmentStage] as $request) {
        $definition = $generator->generate(
            $request['item_level'], $request['tier'], $request['rarity'], $request['category'],
            $request['weapon_style'], $request['main_stat'], $request['seed'],
            implode(':', [$source['trial_identity'], $spec['base'], $equipmentStage, $request['slot']]),
            $request['resonance_variant'] ?? null,
        );
        $equipped[] = [
            'slot' => $request['slot'], 'definition' => $definition,
            'catalog_identity' => $definition['generator_identity'],
            'instance_identity' => $definition['instance_identity'],
        ];
    }
    $loadout = $equipment->combatLoadout($equipped);
    $definition = $players->explorationCombatDefinition(
        $base['growth_path'], $level,
        allocatedStp($players->stpEntitlement($base['growth_path'], $level), commonStpWeights($base['growth_path'])),
        $loadout, $base['label'], null, $allocations,
    );

    return ['definition' => $definition, 'allocations' => $allocations, 'spent' => $spent, 'budget' => $budget];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $level = (int) ($argv[1] ?? 650);
    $seedStart = (int) ($argv[2] ?? 31000);
    $seedCount = (int) ($argv[3] ?? 32);
    $practicalEnemy = $argv[4] ?? 'trial3_shelna';
    if (! isset($source['enemies'][$practicalEnemy])) {
        throw new InvalidArgumentException('Unknown Trial 3 enemy.');
    }
    $results = [];
    foreach ($reference as $key => $spec) {
        $build = measuredBuild($source, $key, $spec, $level, $players, $equipment, $generator);
        $definition = $build['definition'];
        $manifest = $definition['catalog']->manifest();
        $manifest['enemies']['benchmark_dummy'] = [
            'label' => '非攻撃木人', 'boss' => false,
            'base_stats' => array_fill_keys(AlphaV1CombatRules::STATS, 1),
            'max_hp' => 1_000_000_000,
            'physical_defense' => $level * 5, 'magical_defense' => $level * 5,
            'weapon_power' => 1,
            'normal_attack' => ['type' => 'damage', 'category' => 'physical', 'potency_bps' => 1,
                'stat_coefficients' => ['might' => 1], 'weapon_coefficient_bps' => 0,
                'fixed' => 0, 'target_max_hp_bps' => 0, 'can_crit' => false, 'dodgeable' => false, 'hits' => 1],
            'skills' => [], 'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'defend']],
        ];
        foreach (['skills', 'statuses', 'enemies'] as $section) {
            foreach ($source[$section] as $name => $value) {
                $manifest[$section][$name] = $value;
            }
        }
        $catalog = new AlphaV1BuildCatalog($manifest);
        $dummyDamage = $practicalDamage = $practicalRounds = $received = $healing = $prevented = $wins = 0;
        $actions = $dummyActions = $dummyDamageByAction = [];
        for ($seed = $seedStart; $seed < $seedStart + $seedCount; $seed++) {
            $dummy = $model->fightPlayerSnapshot($catalog, $definition['player_snapshot'], 'benchmark_dummy', $seed, 100, 300);
            if ($dummy->rounds !== 100 || $dummy->abnormalState !== []) {
                throw new RuntimeException("Dummy failed {$key} seed {$seed}");
            }
            $dummyDamage += $dummy->damageDealt;
            foreach ($dummy->actionUsage as $action => $count) {
                $dummyActions[$action] = ($dummyActions[$action] ?? 0) + $count;
            }
            foreach ($dummy->actionLog as $row) {
                if (($row['effect_type'] ?? null) === 'damage' && ($row['side'] ?? null) === 'player') {
                    $action = $row['action'];
                    $dummyDamageByAction[$action] = ($dummyDamageByAction[$action] ?? 0) + $row['amount'];
                }
            }
            $battle = $model->fightPlayerSnapshot($catalog, $definition['player_snapshot'], $practicalEnemy, $seed, 100, 300);
            $practicalDamage += $battle->damageDealt;
            $practicalRounds += $battle->rounds;
            $received += $battle->damageReceived;
            $healing += $battle->effectiveHealing;
            $prevented += $battle->damagePrevented;
            $wins += $battle->winner === 'player' ? 1 : 0;
            foreach ($battle->actionUsage as $action => $count) {
                $actions[$action] = ($actions[$action] ?? 0) + $count;
            }
        }
        $results[$key] = [
            'sp' => $build['spent'], 'budget' => $build['budget'],
            'allocations' => $build['allocations'], 'active_skills' => $definition['active_skills'],
            'dummy_dpr' => $dummyDamage / ($seedCount * 100),
            'dummy_actions' => $dummyActions,
            'dummy_damage_by_action' => $dummyDamageByAction,
            'practical_dpr' => $practicalDamage / max(1, $practicalRounds),
            'practical_wins' => $wins, 'practical_rounds_mean' => $practicalRounds / $seedCount,
            'practical_damage_received_mean' => $received / $seedCount,
            'practical_healing_mean' => $healing / $seedCount,
            'practical_prevented_mean' => $prevented / $seedCount,
            'practical_actions' => $actions,
        ];
    }
    echo json_encode(['level' => $level, 'seed_start' => $seedStart, 'seed_count' => $seedCount,
        'equipment_stage' => 'before_boss', 'stp_weights_bps' => [
            'martial_red' => commonStpWeights('martial_red'),
            'guardianship_blue' => commonStpWeights('guardianship_blue'),
            'blessing_green' => commonStpWeights('blessing_green'),
        ], 'practical_enemy' => $practicalEnemy, 'results' => $results],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
}
