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
        $connection = (string) getenv('DB_CONNECTION');
        $database = (string) getenv('DB_DATABASE');

        if ($connection === 'pgsql' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against PostgreSQL database [{$database}].");
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
