<?php

namespace Tests;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $connection = (string) ($_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION'));
        $database = (string) ($_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE'));
        $environment = (string) ($_SERVER['APP_ENV'] ?? getenv('APP_ENV'));

        if ($environment !== 'testing' || ($connection === 'pgsql' && ! str_ends_with($database, '_test'))) {
            throw new RuntimeException("Refusing to run tests in environment [{$environment}] against database [{$database}].");
        }

        parent::setUp();

        $connection = DB::connection();
        if ($connection instanceof SQLiteConnection) {
            $connection->getPdo()->sqliteCreateFunction(
                'GREATEST',
                static fn (int|float ...$values): int|float => max($values),
                -1,
            );
        }
    }
}
