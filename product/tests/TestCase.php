<?php

namespace Tests;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
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
