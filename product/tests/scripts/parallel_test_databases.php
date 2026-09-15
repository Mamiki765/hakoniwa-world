<?php

declare(strict_types=1);

use Tests\Support\ParallelTestDatabaseManager;
use Tests\Support\TestShardPlanner;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php prepare <shard-total> <full|surface|underground> [8-hex-token]\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php shard <manifest> <zero-based-index> <configuration|log|database|evidence_log|junit>\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php fixture <manifest> <zero-based-index> <standard|reusable_surface|individual> <log|completion|evidence_log|junit|fixture_metrics>\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php evidence <manifest> directory\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php cleanup <manifest>\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php finalize <8-hex-token> <test-exit-code> <cleanup-exit-code> <discovered-test-files>\n");
    exit(2);
};

try {
    $manager = new ParallelTestDatabaseManager(dirname(__DIR__, 2));
    $command = $argv[1] ?? null;

    if ($command === 'prepare') {
        $total = $argv[2] ?? null;
        if ($total === null || preg_match('/^[1-9][0-9]*$/', $total) !== 1) {
            $usage();
        }
        $scope = $argv[3] ?? null;
        if ($scope === null) {
            $usage();
        }
        $scope = TestShardPlanner::normalizeScope($scope);
        $token = $argv[4] ?? null;
        if ($token !== null && preg_match('/^[a-f0-9]{8}$/', $token) !== 1) {
            $usage();
        }

        echo $manager->prepare((int) $total, $scope, $token)."\n";
        exit(0);
    }

    if ($command === 'shard') {
        $manifest = $argv[2] ?? null;
        $index = $argv[3] ?? null;
        $field = $argv[4] ?? null;
        if ($manifest === null
            || $index === null
            || preg_match('/^(0|[1-9][0-9]*)$/', $index) !== 1
            || ! in_array($field, ['configuration', 'log', 'database', 'evidence_log', 'junit'], true)) {
            $usage();
        }

        $shard = $manager->shard($manifest, (int) $index);
        if ($shard === null) {
            exit(3);
        }

        echo $shard[$field]."\n";
        exit(0);
    }

    if ($command === 'evidence') {
        $manifest = $argv[2] ?? null;
        $field = $argv[3] ?? null;
        if ($manifest === null || $field !== 'directory') {
            $usage();
        }

        $directory = $manager->evidenceDirectory($manifest);
        if ($directory === null) {
            exit(3);
        }

        echo $directory."\n";
        exit(0);
    }

    if ($command === 'fixture') {
        $manifest = $argv[2] ?? null;
        $index = $argv[3] ?? null;
        $profile = $argv[4] ?? null;
        $field = $argv[5] ?? null;
        if ($manifest === null
            || $index === null
            || preg_match('/^(0|[1-9][0-9]*)$/', $index) !== 1
            || $profile === null
            || $field === null) {
            $usage();
        }

        $artifact = $manager->fixtureArtifact($manifest, (int) $index, $profile, $field);
        if ($artifact === null) {
            exit(3);
        }
        echo $artifact."\n";
        exit(0);
    }

    if ($command === 'cleanup') {
        $manifest = $argv[2] ?? null;
        if ($manifest === null) {
            $usage();
        }

        $manager->cleanup($manifest);
        exit(0);
    }

    if ($command === 'finalize') {
        $token = $argv[2] ?? null;
        $testExitCode = $argv[3] ?? null;
        $cleanupExitCode = $argv[4] ?? null;
        $discoveredTestFiles = $argv[5] ?? null;
        if ($token === null || preg_match('/^[a-f0-9]{8}$/', $token) !== 1
            || $testExitCode === null || preg_match('/^(0|[1-9][0-9]{0,2})$/', $testExitCode) !== 1
            || (int) $testExitCode > 255
            || $cleanupExitCode === null || preg_match('/^(0|[1-9][0-9]{0,2})$/', $cleanupExitCode) !== 1
            || (int) $cleanupExitCode > 255
            || $discoveredTestFiles === null || preg_match('/^(0|[1-9][0-9]*)$/', $discoveredTestFiles) !== 1) {
            $usage();
        }

        echo $manager->finalizeEvidence(
            $token,
            (int) $testExitCode,
            (int) $cleanupExitCode,
            (int) $discoveredTestFiles,
        )."\n";
        exit(0);
    }

    $usage();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Parallel test database operation failed: '.$exception->getMessage()."\n");
    exit(1);
}
