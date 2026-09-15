<?php

declare(strict_types=1);

use Tests\Support\PhpunitSelection;
use Tests\Support\TestShardPlanner;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php plan <shard-total> <full|surface|underground> <output.json>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php plan-focus <scope-plan.json> <list-tests.xml> <output.json>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php plan-verify <plan.json>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php plan-describe <plan.json> <zero-based-index>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php plan-list <plan.json>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php plan-files <plan.json> <zero-based-index>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php plan-profiles <plan.json> <zero-based-index>\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php verify <shard-total> [full|surface|underground]\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php describe <shard-total> <zero-based-index> [full|surface|underground]\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php files <shard-total> <zero-based-index> [full|surface|underground]\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php profiles <shard-total> <zero-based-index> [full|surface|underground]\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php selection-classify [phpunit arguments ...]\n");
    fwrite(STDERR, "  php tests/scripts/test_shards.php selection-passthrough [phpunit arguments ...]\n");
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

$printReport = static function (
    TestShardPlanner $planner,
    string $scope,
    array $discovered,
    array $shards,
    ?array $plan = null,
): void {
    $report = $planner->coverageReport($discovered, $shards);
    if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
        throw new RuntimeException('Test shard coverage is incomplete or overlapping.');
    }
    echo "scope: {$scope}\n";
    echo 'total discovered files: '.$report['discovered_count']."\n";
    echo 'shard count: '.$report['shard_count']."\n";
    if ($plan !== null) {
        echo 'assignment strategy: '.$plan['strategy']."\n";
        echo 'historical timing files: '.$plan['historical_weight_count']."\n";
        echo 'historical timing sources: '.count($plan['historical_sources'])."\n";
    }
    foreach ($report['shard_file_counts'] as $index => $fileCount) {
        $suffix = $plan === null ? '' : sprintf(
            ', predicted seconds: %.6f',
            (float) ($plan['predicted_seconds'][$index] ?? 0),
        );
        echo sprintf("shard %02d/%02d assigned files: %d%s\n", $index + 1, count($shards), $fileCount, $suffix);
    }
    foreach ($planner->groupByFixtureProfile($discovered) as $profile => $files) {
        echo "fixture {$profile} files: ".count($files)."\n";
    }
    echo 'union count: '.$report['union_count']."\n";
    echo 'duplicate count: '.$report['duplicate_count']."\n";
    echo 'missing count: '.$report['missing_count']."\n";
    echo 'unexpected count: '.$report['unexpected_count']."\n";
};

try {
    $command = $argv[1] ?? null;
    $projectRoot = dirname(__DIR__, 2);
    $planner = new TestShardPlanner($projectRoot);

    if (in_array($command, ['selection-classify', 'selection-passthrough'], true)) {
        $selection = PhpunitSelection::classify(array_values(array_slice($argv, 2)));
        if ($command === 'selection-classify') {
            echo 'selection_mode: '.$selection['selection_mode']."\n";
            echo 'has test inputs: '.($selection['has_test_inputs'] ? 'yes' : 'no')."\n";
        } else {
            foreach ($selection['passthrough'] as $argument) {
                echo $argument."\0";
            }
        }

        exit(0);
    }

    if ($command === 'plan') {
        $shardTotal = $positiveInteger($argv[2] ?? null, 'Shard total');
        $scope = TestShardPlanner::normalizeScope($argv[3] ?? 'full');
        $output = $argv[4] ?? null;
        if ($output === null) {
            $usage();
        }
        $plan = $planner->createRunPlan($shardTotal, $scope, metadata: [
            'source_tree_sha256' => getenv('HAKONIWA_PLAN_SOURCE_TREE_SHA256') ?: 'unknown',
            'composer_lock_sha256' => getenv('HAKONIWA_PLAN_COMPOSER_LOCK_SHA256') ?: 'unknown',
        ], useHistoricalTiming: getenv('HAKONIWA_PLAN_SKIP_HISTORICAL_TIMING') !== '1');
        $planner->writeRunPlan($output, $plan);
        $printReport($planner, $scope, $plan['discovered'], $plan['shards'], $plan);

        exit(0);
    }

    if ($command === 'plan-focus') {
        $scopePlanPath = $argv[2] ?? null;
        $listTestsXmlPath = $argv[3] ?? null;
        $output = $argv[4] ?? null;
        if ($scopePlanPath === null || $listTestsXmlPath === null || $output === null) {
            $usage();
        }
        $scopePlan = $planner->loadRunPlan($scopePlanPath);
        $plan = $planner->focusRunPlan($scopePlan, $listTestsXmlPath);
        $planner->writeRunPlan($output, $plan);
        $printReport($planner, $plan['scope'], $plan['discovered'], $plan['shards'], $plan);
        echo 'selected test identifiers: '.$plan['selected_test_identifier_count']."\n";

        exit(0);
    }

    if (in_array($command, ['plan-verify', 'plan-describe', 'plan-list', 'plan-files', 'plan-profiles'], true)) {
        $path = $argv[2] ?? null;
        if ($path === null) {
            $usage();
        }
        $plan = $planner->loadRunPlan($path);
        $scope = $plan['scope'];
        $shards = $plan['shards'];
        if ($command === 'plan-verify') {
            $printReport($planner, $scope, $plan['discovered'], $shards, $plan);

            exit(0);
        }
        if ($command === 'plan-list') {
            foreach ($plan['discovered'] as $file) {
                echo $file."\n";
            }

            exit(0);
        }
        $index = $indexInteger($argv[3] ?? null, $plan['shard_total']);
        $assigned = $shards[$index];
        if ($command === 'plan-describe') {
            echo "scope: {$scope}\n";
            echo sprintf("shard index: %d (%02d/%02d)\n", $index, $index + 1, $plan['shard_total']);
            echo 'assignment strategy: '.$plan['strategy']."\n";
            echo 'predicted seconds: '.sprintf('%.6f', (float) ($plan['predicted_seconds'][$index] ?? 0))."\n";
            echo 'assigned file count: '.count($assigned)."\n";
            foreach ($planner->groupByFixtureProfile($assigned) as $profile => $files) {
                echo "fixture {$profile} files: ".count($files)."\n";
            }
            echo 'total discovered files: '.count($plan['discovered'])."\n";
            echo "assigned files:\n";
            foreach ($assigned as $file) {
                echo "  - {$file}\n";
            }

            exit(0);
        }
        if ($command === 'plan-profiles') {
            foreach ($planner->groupByFixtureProfile($assigned) as $profile => $files) {
                foreach ($files as $file) {
                    echo $profile."\t".$file."\n";
                }
            }

            exit(0);
        }
        foreach ($assigned as $file) {
            echo $file."\n";
        }

        exit(0);
    }

    $shardTotal = $positiveInteger($argv[2] ?? null, 'Shard total');
    $scopeArgument = $command === 'verify' ? ($argv[3] ?? 'full') : ($argv[4] ?? 'full');
    $scope = TestShardPlanner::normalizeScope($scopeArgument);
    $discovered = $planner->discover($scope);
    $shards = $planner->assign($discovered, $shardTotal);

    if ($command === 'verify') {
        $printReport($planner, $scope, $discovered, $shards);

        exit(0);
    }
    if ($command !== 'describe' && $command !== 'files' && $command !== 'profiles') {
        $usage();
    }

    $index = $indexInteger($argv[3] ?? null, $shardTotal);
    $assigned = $shards[$index];
    if ($command === 'describe') {
        echo "scope: {$scope}\n";
        echo sprintf("shard index: %d (%02d/%02d)\n", $index, $index + 1, $shardTotal);
        echo "shard total: {$shardTotal}\n";
        echo 'assigned file count: '.count($assigned)."\n";
        foreach ($planner->groupByFixtureProfile($assigned) as $profile => $files) {
            echo "fixture {$profile} files: ".count($files)."\n";
        }
        echo 'total discovered files: '.count($discovered)."\n";
        echo "assigned files:\n";
        foreach ($assigned as $file) {
            echo "  - {$file}\n";
        }

        exit(0);
    }
    if ($command === 'profiles') {
        foreach ($planner->groupByFixtureProfile($assigned) as $profile => $files) {
            foreach ($files as $file) {
                echo $profile."\t".$file."\n";
            }
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
