<?php

declare(strict_types=1);

use Tests\Support\TestShardPlanner;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php verify <shard-total>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php describe <shard-total> <zero-based-index>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php files <shard-total> <zero-based-index>\n");
    exit(2);
};

$positiveInteger = static function (?string $value, string $label) use ($usage): int {
    if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        fwrite(STDERR, "{$label} must be a positive integer.\n");
        $usage();
    }

    return (int) $value;
};

$indexInteger = static function (?string $value, int $shardTotal) use ($usage): int {
    if ($value === null || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
        fwrite(STDERR, "Shard index must be a zero-based integer.\n");
        $usage();
    }

    $index = (int) $value;
    if ($index >= $shardTotal) {
        fwrite(STDERR, "Shard index {$index} is outside 0..".($shardTotal - 1).".\n");
        $usage();
    }

    return $index;
};

try {
    $command = $argv[1] ?? null;
    $shardTotal = $positiveInteger($argv[2] ?? null, 'Shard total');
    $projectRoot = dirname(__DIR__, 2);
    $planner = new TestShardPlanner($projectRoot);
    $discovered = $planner->discover();
    $shards = $planner->assign($discovered, $shardTotal);
    $report = $planner->coverageReport($discovered, $shards);

    if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
        throw new RuntimeException('Test shard coverage is incomplete or overlapping.');
    }

    if ($command === 'verify') {
        echo "total discovered files: {$report['discovered_count']}\n";
        echo "shard count: {$report['shard_count']}\n";
        foreach ($report['shard_file_counts'] as $index => $fileCount) {
            echo sprintf("shard %02d/%02d assigned files: %d\n", $index + 1, $shardTotal, $fileCount);
        }
        echo "union count: {$report['union_count']}\n";
        echo "duplicate count: {$report['duplicate_count']}\n";
        echo "missing count: {$report['missing_count']}\n";
        echo "unexpected count: {$report['unexpected_count']}\n";
        exit(0);
    }

    if ($command !== 'describe' && $command !== 'files') {
        $usage();
    }

    $index = $indexInteger($argv[3] ?? null, $shardTotal);
    $assigned = $shards[$index];

    if ($command === 'describe') {
        echo sprintf("shard index: %d (%02d/%02d)\n", $index, $index + 1, $shardTotal);
        echo "shard total: {$shardTotal}\n";
        echo 'assigned file count: '.count($assigned)."\n";
        echo "total discovered files: {$report['discovered_count']}\n";
        echo "assigned files:\n";
        foreach ($assigned as $file) {
            echo "  - {$file}\n";
        }
        exit(0);
    }

    foreach ($assigned as $file) {
        echo $file."\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Test shard planning failed: '.$exception->getMessage()."\n");
    exit(1);
}
