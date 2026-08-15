<?php

declare(strict_types=1);

use Tests\Support\ParallelTestDatabaseManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php prepare <shard-total> [8-hex-token]\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php shard <manifest> <zero-based-index> <configuration|log|database>\n");
    fwrite(STDERR, "  php tests/scripts/parallel_test_databases.php cleanup <manifest>\n");
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
        $token = $argv[3] ?? null;
        if ($token !== null && preg_match('/^[a-f0-9]{8}$/', $token) !== 1) {
            $usage();
        }

        echo $manager->prepare((int) $total, $token)."\n";
        exit(0);
    }

    if ($command === 'shard') {
        $manifest = $argv[2] ?? null;
        $index = $argv[3] ?? null;
        $field = $argv[4] ?? null;
        if ($manifest === null
            || $index === null
            || preg_match('/^(0|[1-9][0-9]*)$/', $index) !== 1
            || ! in_array($field, ['configuration', 'log', 'database'], true)) {
            $usage();
        }

        $shard = $manager->shard($manifest, (int) $index);
        if ($shard === null) {
            exit(3);
        }

        echo $shard[$field]."\n";
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

    $usage();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Parallel test database operation failed: '.$exception->getMessage()."\n");
    exit(1);
}
