<?php

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
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
$players = $app->make(UndergroundAlphaV1PlayerCatalog::class);
$model = $app->make(AlphaV1CombatModel::class);
$ai = $app->make(PriorityCombatAiConfiguration::class);
$rules = $app->make(AlphaV1CombatRules::class);

function standardSkills(string $tree, int $budget): array
{
    return match ($tree) {
        'martial' => $budget <= 20 ? ['precision_cut', 'dagger_flurry']
            : ($budget <= 60 ? ['precision_cut', 'dagger_flurry', 'armor_break_strike']
                : ['precision_cut', 'dagger_flurry', 'armor_break_strike', 'severing_bleed', 'executioner_cut']),
        'guardianship' => $budget <= 20 ? ['shield_bash', 'counter_stance']
            : ($budget <= 60 ? ['shield_bash', 'counter_stance', 'bulwark_strike']
                : ['shield_bash', 'counter_stance', 'renewing_guard', 'bulwark_strike', 'unbroken_retort']),
        'miracle' => $budget <= 20 ? ['holy_bolt', 'mending_prayer', 'lucid_dream']
            : ($budget <= 60 ? ['holy_bolt', 'mending_prayer', 'crystal_cycle', 'crystal_aegis']
                : ['holy_bolt', 'mending_prayer', 'crystal_cycle', 'crystal_aegis', 'holy_lance']),
        default => throw new InvalidArgumentException('Unknown role.'),
    };
}

function standardRoleSnapshot(array $case): array
{
    global $players, $rules;
    $snapshot = $case['snapshot'];
    if ($case['tree'] === 'guardianship') {
        $entitlement = $players->stpEntitlement('guardianship_blue', $case['level']);
        $stp = array_fill_keys($rules::STATS, 0);
        $stp['might'] = intdiv($entitlement * 40, 100);
        $stp['vitality'] = $entitlement - $stp['might'];
        $snapshot['stats'] = $players->currentStats('guardianship_blue', $case['level'], $stp);
        $snapshot['current_hp'] = $players->maxHp($players->combatStats($snapshot['stats'], $snapshot['equipment']), $snapshot['equipment']);
    }

    return $snapshot;
}

function rebuildSnapshot(array $snapshot, array $skills, int $budget): array
{
    global $players, $ai;
    $catalog = $players->explorationCatalog();
    $allocations = [];
    $acquire = function (string $key) use (&$acquire, &$allocations, $catalog): void {
        $node = $catalog->node($key)['node'];
        if ($node['prerequisite'] !== null) {
            $acquire($node['prerequisite']);
        }
        $allocations[$key] = ['rank' => 1, 'active_slot' => null];
    };
    foreach ($skills as $skill) {
        $acquire($catalog->skill($skill)['node_key']);
    }
    foreach ($skills as $slot => $skill) {
        $allocations[$catalog->skill($skill)['node_key']]['active_slot'] = $slot + 1;
    }
    $spent = array_sum(array_map(static fn (string $key): int => $catalog->node($key)['node']['point_cost_per_rank'], array_keys($allocations)));
    if ($spent > $budget || count($skills) > 5) {
        throw new RuntimeException('Build exceeds SP or active slot budget: '.$spent.' / '.$budget);
    }
    $build = $players->playerSkillBuild($allocations, $snapshot['equipment']['weapon_style']);
    $snapshot['active_skills'] = $build['active_skills'];
    $snapshot['modifiers'] = $build['passive_modifiers'];
    $snapshot['ai_rules'] = $ai->defaultRules($build['ai_rules'], $catalog);
    $snapshot['ai_mode'] = 'default';

    return ['snapshot' => $snapshot, 'spent' => $spent, 'allocations' => $allocations];
}

function measurementEnemy(int $level, int $fixedDamage = 0): array
{
    global $players;

    return [
        'label' => '報酬なし測定体', 'boss' => true,
        'base_stats' => array_fill_keys(AlphaV1CombatRules::STATS, 1),
        'max_hp' => 1000000000, 'physical_defense' => $level * 5, 'magical_defense' => $level * 5,
        'weapon_power' => 1,
        'normal_attack' => ['type' => 'damage', 'category' => 'physical', 'potency_bps' => 10000,
            'stat_coefficients' => [], 'weapon_coefficient_bps' => 0, 'fixed' => max(1, $fixedDamage),
            'target_max_hp_bps' => 0, 'can_crit' => false, 'dodgeable' => false, 'hits' => 1],
        'skills' => $fixedDamage > 0 ? [] : ['benchmark_idle'],
        'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => $fixedDamage > 0 ? 'normal_attack' : 'skill:benchmark_idle']],
    ];
}

function distribution(array $values): array
{
    sort($values);

    return ['mean' => array_sum($values) / count($values), 'median' => $values[(int) floor(count($values) * 0.5)],
        'p90' => $values[(int) floor(count($values) * 0.9)], 'p99' => $values[min(count($values) - 1, (int) floor(count($values) * 0.99))], 'max' => max($values)];
}
