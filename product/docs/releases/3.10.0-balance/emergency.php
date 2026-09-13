<?php

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\PriorityCombatAiConfiguration;
use Illuminate\Contracts\Console\Kernel;

$productRoot = getenv('HAKONIWA_BENCHMARK_PRODUCT') ?: dirname(__DIR__, 3);
require $productRoot.'/vendor/autoload.php';
$app = require $productRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
});
if (($argv[1] ?? '') === '') {
    throw new InvalidArgumentException('Usage: php emergency.php output.json');
}
$players = $app->make(UndergroundAlphaV1PlayerCatalog::class);
$model = $app->make(AlphaV1CombatModel::class);
$baseCatalog = $players->explorationCatalog();
$cases = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)['new'];
$party = [];
foreach ($cases as $case) {
    if ($case['level'] !== 90) {
        continue;
    }
    $snapshot = $case['snapshot'];
    $snapshot['combatant_id'] = 'synthetic:'.$case['tree'];
    unset($snapshot['awakening']);
    $snapshot['current_hp'] = $case['tree'] === 'martial' ? 0 : (int) floor($snapshot['current_hp'] * 0.4);
    $party[$case['tree']] = $snapshot;
}
$skills = ['mending_prayer', 'regeneration', 'resurrection', 'heart_of_mercy', 'crystal_cycle'];
$allocations = [];
$acquire = function (string $key) use (&$acquire, &$allocations, $baseCatalog): void {
    $node = $baseCatalog->node($key)['node'];
    if ($node['prerequisite'] !== null) {
        $acquire($node['prerequisite']);
    }
    $allocations[$key] = ['rank' => 1, 'active_slot' => null];
};
foreach ($skills as $skill) {
    $acquire($baseCatalog->skill($skill)['node_key']);
}
foreach ($skills as $slot => $skill) {
    $allocations[$baseCatalog->skill($skill)['node_key']]['active_slot'] = $slot + 1;
}
$spent = 0;
foreach ($allocations as $key => $row) {
    $spent += $baseCatalog->node($key)['node']['point_cost_per_rank'];
}
if ($spent > 100) {
    throw new RuntimeException('Healer exceeds the SP budget.');
}
$build = $players->playerSkillBuild($allocations, $party['miracle']['equipment']['weapon_style']);
$party['miracle']['active_skills'] = $build['active_skills'];
$party['miracle']['modifiers'] = $build['passive_modifiers'];
$party['miracle']['ai_rules'] = (new PriorityCombatAiConfiguration)->defaultRules($build['ai_rules'], $baseCatalog);
$out = [];
foreach ([700, 1400, 2800] as $fixedDamage) {
    $manifest = $baseCatalog->manifest();
    $manifest['enemies']['emergency_probe'] = [
        'label' => '回復負担の合成測定体', 'boss' => true,
        'base_stats' => array_fill_keys(AlphaV1CombatRules::STATS, 1),
        'max_hp' => 100000, 'physical_defense' => 450, 'magical_defense' => 450,
        'weapon_power' => 1,
        'normal_attack' => ['type' => 'damage', 'category' => 'physical', 'potency_bps' => 10000,
            'stat_coefficients' => [], 'weapon_coefficient_bps' => 0, 'fixed' => $fixedDamage,
            'target_max_hp_bps' => 0, 'can_crit' => false, 'dodgeable' => false, 'hits' => 1,
            'target_scope' => 'all_enemies'],
        'skills' => [], 'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']],
    ];
    $catalog = new AlphaV1BuildCatalog($manifest);
    foreach ([true, false] as $withMpSupply) {
        $snapshots = $party;
        if (! $withMpSupply) {
            $snapshots['miracle']['active_skills'] = array_values(array_filter($skills, static fn (string $key): bool => $key !== 'crystal_cycle'));
            $snapshots['miracle']['ai_rules'] = array_values(array_filter($snapshots['miracle']['ai_rules'], static fn (array $rule): bool => $rule['action'] !== 'skill:crystal_cycle'));
        }
        $runs = [];
        for ($seed = 31000; $seed < 31032; $seed++) {
            $result = $model->fightPartySnapshots($catalog, array_values($snapshots), ['emergency_probe'], $seed, 200, 300);
            $actions = [];
            $healing = 0;
            $revivals = 0;
            $mpBlocked = 0;
            $mercyDamage = 0;
            foreach ($result->actionLog as $row) {
                if (($row['actor_id'] ?? null) !== 'synthetic:miracle') {
                    continue;
                }
                if (($row['kind'] ?? null) === 'decision') {
                    $actions[$row['action_key']] = ($actions[$row['action_key']] ?? 0) + 1;
                    $mpBlocked += ($row['mp_blocked'] ?? false) ? 1 : 0;
                }
                if (in_array($row['effect_type'] ?? null, ['recovery', 'revival'], true) && $row['amount'] < 0) {
                    $healing -= $row['amount'];
                }
                $revivals += ($row['kind'] ?? null) === 'revival' && ($row['revived'] ?? false) ? 1 : 0;
                if (($row['action'] ?? null) === 'heart_of_mercy' && ($row['effect_type'] ?? null) === 'damage') {
                    $mercyDamage += max(0, $row['amount']);
                }
            }
            $runs[] = ['seed' => $seed, 'winner' => $result->winner, 'rounds' => $result->rounds,
                'healer_actions' => $actions, 'healer_effective_healing' => $healing,
                'healer_revivals' => $revivals, 'healer_mp_blocked' => $mpBlocked,
                'healer_final_mp' => $result->finalStates['synthetic:miracle']['mp'],
                'healer_final_hp' => $result->finalStates['synthetic:miracle']['hp'],
                'mercy_damage' => $mercyDamage,
                'party_survivors' => count(array_filter($result->finalStates, static fn (array $state, string $id): bool => str_starts_with($id, 'synthetic:') && $state['hp'] > 0, ARRAY_FILTER_USE_BOTH)),
                'invalid_resources' => count(array_filter($result->finalStates, static fn (array $state): bool => $state['hp'] < 0 || $state['hp'] > $state['max_hp'] || $state['mp'] < 0 || $state['mp'] > 10000))];
        }
        $out[] = ['fixed_damage' => $fixedDamage, 'with_mp_supply' => $withMpSupply,
            'healer_spent' => $spent, 'runs' => $runs];
    }
}
file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
