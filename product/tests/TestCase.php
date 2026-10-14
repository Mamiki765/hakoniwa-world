<?php

namespace Tests;

use App\Application\CurrentCatalogInstaller;
use App\Application\RulesetPublisher;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\SendsUndergroundAdmission;

abstract class TestCase extends BaseTestCase
{
    use SendsUndergroundAdmission;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $application = $this->app;
        $testConnection = $application['config']->get('database.default');
        if (! is_string($testConnection)) {
            throw new RuntimeException('The default connection is not configured for the test database baseline.');
        }
        $application['events']->listen(MigrationsStarted::class, static function (MigrationsStarted $event) use ($application, $testConnection): void {
            $connection = $application['db']->connection($testConnection);
            if ($event->method !== 'up' || ! $application->environment('testing')
                || $connection->getDriverName() !== 'pgsql' || ! str_ends_with($connection->getDatabaseName(), '_test')
                || $connection->table('migrations')->exists()) {
                return;
            }
            // psql's plain schema loader leaves existing functions intact after migrate:fresh.
            // Restore the canonical trigger body before replaying migrations in the next profile.
            $source = file_get_contents(database_path('schema/pgsql-schema.sql'));
            if ($source === false || preg_match('/CREATE FUNCTION public\.validate_surface_ship_identity\(\).*?^\$\$;/ms', $source, $matches) !== 1) {
                throw new RuntimeException('The canonical Ship identity trigger is missing.');
            }
            $connection->unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $matches[0]));
        });
        $application['events']->listen(
            [MigrationsEnded::class, NoPendingMigrations::class],
            static function (MigrationsEnded|NoPendingMigrations $event) use ($application, $testConnection): void {
                if ($event->method !== 'up') {
                    return;
                }
                $migrator = $application['migrator'];
                if (! $migrator instanceof Migrator) {
                    throw new RuntimeException('The migration service is unavailable for the test database baseline.');
                }
                $migrationConnection = $migrator->getConnection();
                if ($migrationConnection !== null && $migrationConnection !== $testConnection) {
                    return;
                }
                $settings = $application['config']->get('hakoniwa.ruleset');
                if (! is_array($settings)) {
                    throw new RuntimeException('The current Ruleset is not configured for the test database baseline.');
                }

                $application->make(CurrentCatalogInstaller::class)->install($settings);
                $application->make(RulesetPublisher::class)->publish($settings);
            },
        );
    }

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
