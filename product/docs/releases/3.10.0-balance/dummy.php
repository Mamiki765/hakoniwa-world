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
$variant = $argv[1] ?? '';
if (! in_array($variant, ['old', 'new'], true) || ($argv[2] ?? '') === '') {
    throw new InvalidArgumentException('Usage: php dummy.php old|new output.json');
}
$players = $app->make(UndergroundAlphaV1PlayerCatalog::class);
$model = $app->make(AlphaV1CombatModel::class);
$cases = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)[$variant];
$out = [];
foreach ($cases as $case) {
    $manifest = $players->explorationCatalog()->manifest();
    $manifest['enemies']['benchmark_dummy'] = [
        'label' => '報酬なし木人', 'boss' => false,
        'base_stats' => array_fill_keys(AlphaV1CombatRules::STATS, 1),
        'max_hp' => 1_000_000_000,
        'physical_defense' => $case['level'] * 5, 'magical_defense' => $case['level'] * 5,
        'weapon_power' => 1,
        'normal_attack' => ['type' => 'damage', 'category' => 'physical', 'potency_bps' => 1,
            'stat_coefficients' => ['might' => 1], 'weapon_coefficient_bps' => 0,
            'fixed' => 0, 'target_max_hp_bps' => 0, 'can_crit' => false, 'dodgeable' => false, 'hits' => 1],
        'skills' => [], 'ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'defend']],
    ];
    $catalog = new AlphaV1BuildCatalog($manifest);
    $runs = [];
    for ($seed = 31000; $seed < 31032; $seed++) {
        $result = $model->fightPlayerSnapshot($catalog, $case['snapshot'], 'benchmark_dummy', $seed, 200, 300);
        $middle = null;
        foreach ($result->actionLog as $row) {
            if (($row['kind'] ?? null) === 'round_end' && $row['round'] === 100) {
                $middle = $row['enemy']['hp'];
            }
        }
        if (! is_int($middle)) {
            throw new RuntimeException('Missing round 100 state.');
        }
        $runs[] = ['seed' => $seed, 'dpr' => $result->damageDealt / 200,
            'late_dpr' => ($middle - $result->enemyRemainingHp) / 100,
            'normal_attacks' => $result->actionUsage['normal_attack'] ?? 0,
            'mp_blocked' => $result->skillUnavailableDueToMp, 'final_mp' => $result->finalMp,
            'abnormal' => $result->abnormalState];
    }
    $out[] = ['tree' => $case['tree'], 'level' => $case['level'], 'sp_budget' => $case['sp_budget'], 'runs' => $runs];
}
file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
