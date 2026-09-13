<?php

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundTrialBalanceSimulator;
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
$trial = $app->make(UndergroundTrialBalanceSimulator::class);
$cases = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)[$variant];
$out = ['kind' => 'synthetic_matched_comparison', 'variant' => $variant, 'combat_identity' => AlphaV1CombatRules::IDENTITY, 'seed_start' => 31000, 'seed_count' => 32, 'pve' => [], 'trials' => []];
$config = require $productRoot.'/config/underground-alpha-v1.php';
foreach ($cases as $case) {
    $ground = ['30' => 'shallow_caves', '60' => 'black_crystal_cave', '90' => 'shining_kingdom'][(string) $case['level']];
    $enemies = array_keys($config['exploration']['grounds'][$ground]['encounters']);
    $rare = $config['exploration']['grounds'][$ground]['rare_encounter']['key'] ?? null;
    if (is_string($rare)) {
        $enemies[] = $rare;
    }
    $rows = [];
    foreach ($enemies as $enemy) {
        $wins = 0;
        $rounds = 0;
        $abnormal = 0;
        for ($seed = 31000; $seed < 31032; $seed++) {
            $r = $model->fightPlayerSnapshot($players->explorationCatalog(), $case['snapshot'], $enemy, $seed, 100, 300);
            $wins += $r->winner === 'player' ? 1 : 0;
            $rounds += $r->rounds;
            $abnormal += count($r->abnormalState);
        }
        $rows[] = ['enemy' => $enemy, 'wins' => $wins, 'mean_rounds' => $rounds / 32, 'abnormal' => $abnormal];
    }
    $out['pve'][] = ['tree' => $case['tree'], 'level' => $case['level'], 'sp_budget' => $case['sp_budget'], 'ground' => $ground, 'enemies' => $rows];
    echo $variant,' pve ',$case['tree'],' ',$case['level'],' ',array_sum(array_column($rows, 'wins')),'/',count($rows) * 32,PHP_EOL;
}
foreach ([1 => [30, 35], 2 => [150, 180]] as $generation => $levels) {
    $manifest = json_decode(file_get_contents($productRoot.'/config/underground/balance/trial'.$generation.'-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['martial_red', 'guardianship_blue', 'blessing_green', 'free_black'] as $build) {
        foreach ($levels as $level) {
            $id = $build.':lv'.$level.':heal2000';
            $wins = 0;
            $abnormal = 0;
            $rounds = 0;
            for ($seed = 31000; $seed < 31032; $seed++) {
                $r = $trial->replay($manifest, $id, $seed)['result'];
                $wins += $r['cleared'] ? 1 : 0;
                $abnormal += $r['abnormal_result_count'];
                $rounds += array_sum(array_column($r['battles'], 'rounds'));
            }
            $row = ['generation' => $generation, 'build' => $build, 'level' => $level, 'wins' => $wins, 'abnormal' => $abnormal, 'mean_rounds' => $rounds / 32];
            $out['trials'][] = $row;
            echo json_encode($row),PHP_EOL;
        }
    }
}
file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
