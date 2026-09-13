<?php

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use Illuminate\Contracts\Console\Kernel;

$productRoot = getenv('HAKONIWA_BENCHMARK_PRODUCT') ?: dirname(__DIR__, 3);
require $productRoot.'/vendor/autoload.php';
$app = require $productRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
});
if (! in_array($argv[1] ?? '', ['old', 'new'], true) || ($argv[2] ?? '') === '') {
    throw new InvalidArgumentException('Pass old|new and the output JSON path.');
}
$variant = $argv[1];
$players = $app->make(UndergroundAlphaV1PlayerCatalog::class);
$model = $app->make(AlphaV1CombatModel::class);
$base = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)['old'][0]['snapshot'];
$out = [];
$quantiles = static function (array $values): array {
    sort($values);

    return ['mean' => array_sum($values) / count($values), 'p10' => $values[(int) floor(count($values) * .1)], 'p50' => $values[(int) floor(count($values) * .5)], 'p90' => $values[(int) floor(count($values) * .9)], 'max' => max($values)];
};
foreach ([30, 1000] as $level) {
    foreach ([0, 30, 50] as $percent) {
        $m = $players->laboratoryCatalog()->manifest();
        $m['skills']['crit_probe'] = ['label' => '会心比較', 'mp_cost' => 0, 'cooldown' => 0, 'effects' => [['type' => 'damage', 'category' => 'physical', 'potency_bps' => 10000, 'stat_coefficients' => ['might' => 10000], 'weapon_coefficient_bps' => 10000, 'fixed' => 0, 'target_max_hp_bps' => 0, 'can_crit' => true, 'dodgeable' => false, 'hits' => 1]]];
        $m['enemies']['crit_target'] = ['label' => '会心比較体', 'boss' => false, 'base_stats' => array_fill_keys(AlphaV1CombatRules::STATS, 1), 'max_hp' => $level * 200, 'physical_defense' => $level * 5, 'magical_defense' => $level * 5, 'weapon_power' => 1, 'normal_attack' => $m['normal_attack'], 'skills' => [], 'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'defend']]];
        $m['enemies']['crit_target']['max_hp'] = 1000000000;
        $m['skills']['crit_probe']['node_key'] = 'martial_precision_cut';
        $m['skill_trees']['martial']['nodes']['martial_precision_cut']['skill_key'] = 'crit_probe';
        $catalog = new AlphaV1BuildCatalog($m);
        $p = $base;
        unset($p['current_hp']);
        $f = max(1, intdiv($level * 10 * $percent, 100));
        $p['stats'] = ['vitality' => $level * 3, 'might' => $level * 10 - $f, 'finesse' => $f, 'spirit' => $level, 'agility' => 1];
        $p['equipment'] = ['weapon_style' => 'dagger', 'weapon_power' => $level * 2, 'physical_defense' => 0, 'magical_defense' => 0, 'max_hp' => 0, 'stats' => array_fill_keys(AlphaV1CombatRules::STATS, 0), 'modifiers' => []];
        $p['equipment'] = [...$base['equipment'], ...$p['equipment'], 'affixes' => [], 'unique_effect' => null];
        $p['modifiers'] = [];
        $p['active_skills'] = ['crit_probe'];
        $p['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'skill:crit_probe']];
        $single = [];
        $totals = [];
        $wins = 0;
        for ($seed = 31000; $seed < 31256; $seed++) {
            $r = $model->fightPlayerSnapshot($catalog, $p, 'crit_target', $seed, 20, 0);
            $rows = array_values(array_filter($r->actionLog, static fn (array $row): bool => ($row['action'] ?? null) === 'crit_probe' && ($row['effect_type'] ?? null) === 'damage'));
            $single[] = $rows[0]['amount'];
            $totals[] = $r->damageDealt;
            $wins += $r->winner === 'player' ? 1 : 0;
        }

        $thresholds = json_decode(file_get_contents(__DIR__.'/results.json'), true, flags: JSON_THROW_ON_ERROR)['finesse_thresholds'];
        $crossings = count(array_filter($totals, static fn (int $damage): bool => $damage >= $thresholds[$level]));
        $row = ['level' => $level, 'finesse_share_percent' => $percent, 'single' => $quantiles($single), 'total_20_turns' => $quantiles($totals), 'damage_threshold' => $thresholds[$level], 'threshold_crossings' => $crossings, 'seeds' => 256];
        $out[] = $row;
        echo json_encode($row),PHP_EOL;
    }
}
file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
