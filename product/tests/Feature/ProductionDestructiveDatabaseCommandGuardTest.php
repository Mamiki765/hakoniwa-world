<?php

namespace Tests\Feature;

use App\Console\ProductionDestructiveDatabaseCommandGuard;
use App\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProductionDestructiveDatabaseCommandGuardTest extends TestCase
{
    use RefreshDatabase;

    private const array GUARDED_COMMANDS = [
        'migrate:fresh' => FreshCommand::class,
        'migrate:refresh' => RefreshCommand::class,
        'migrate:reset' => ResetCommand::class,
        'migrate:rollback' => RollbackCommand::class,
        'db:wipe' => WipeCommand::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::GUARDED_COMMANDS as $commandClass) {
            $commandClass::prohibit(false);
        }

        parent::tearDown();
    }

    public function test_destructive_database_commands_are_rejected_before_any_query_in_production(): void
    {
        $user = User::factory()->create();
        $queries = [];

        $this->setEnvironment('production');
        $this->guard()->configure();
        $kernel = $this->app->make(KernelContract::class);
        $this->assertInstanceOf(ConsoleKernel::class, $kernel);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
        DB::listen(static function () use (&$queries): void {
            $queries[] = true;
        });

        try {
            foreach (array_keys(self::GUARDED_COMMANDS) as $command) {
                $this->assertSame(1, Artisan::call($command, ['--force' => true]));
                $this->assertStringContainsString(
                    "Destructive database command [{$command}] is disabled in production.",
                    Artisan::output(),
                );
            }

            $this->assertSame([], $queries);
        } finally {
            $this->setEnvironment('testing');
            $this->guard()->configure();
        }

        $this->assertSame(1, User::query()->whereKey($user->id)->count());
    }

    public function test_local_and_testing_environments_can_run_all_five_commands_on_an_isolated_database(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $this->setEnvironment($environment);
            $this->guard()->configure();
            $this->configureIsolatedDatabase();

            $migrationOptions = [
                '--database' => 'guard_test',
                '--path' => base_path('tests/Fixtures/database-command-guard'),
                '--realpath' => true,
                '--force' => true,
            ];

            $this->assertSame(0, Artisan::call('migrate:fresh', $migrationOptions));
            $this->assertTrue(Schema::connection('guard_test')->hasTable('guard_records'));
            $this->assertSame(0, Artisan::call('migrate:refresh', $migrationOptions));
            $this->assertTrue(Schema::connection('guard_test')->hasTable('guard_records'));
            $this->assertSame(0, Artisan::call('migrate:rollback', $migrationOptions));
            $this->assertFalse(Schema::connection('guard_test')->hasTable('guard_records'));
            $this->assertSame(0, Artisan::call('migrate:fresh', $migrationOptions));
            $this->assertSame(0, Artisan::call('migrate:reset', $migrationOptions));
            $this->assertFalse(Schema::connection('guard_test')->hasTable('guard_records'));
            $this->assertSame(0, Artisan::call('migrate:fresh', $migrationOptions));
            $this->assertSame(0, Artisan::call('db:wipe', [
                '--database' => 'guard_test',
                '--force' => true,
            ]));
            $this->assertFalse(Schema::connection('guard_test')->hasTable('guard_records'));
        }
    }

    public function test_environment_override_cannot_bypass_a_production_runtime(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'migrate:fresh', "--env={$environment}", '--force', '--no-interaction'],
                base_path(),
                [
                    'APP_ENV' => 'production',
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => ':memory:',
                    'LOG_CHANNEL' => 'stderr',
                ],
            );
            $process->setTimeout(30);
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString(
                'This command is prohibited from running in this environment.',
                $output,
            );
        }
    }

    public function test_alternate_environment_file_cannot_mask_a_production_default_environment(): void
    {
        $files = $this->app->make(Filesystem::class);
        $environmentPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hakoniwa-command-guard-'.bin2hex(random_bytes(8));
        $originalEnvironmentPath = $this->app->environmentPath();
        $originalEnvironmentFile = $this->app->environmentFile();
        $queries = [];
        $files->makeDirectory($environmentPath);
        $files->put($environmentPath.DIRECTORY_SEPARATOR.'.env', "APP_ENV=production\n");
        $files->put($environmentPath.DIRECTORY_SEPARATOR.'.env.local', "APP_ENV=local\n");

        try {
            $this->app->useEnvironmentPath($environmentPath);
            $this->app->loadEnvironmentFrom('.env.local');
            $this->setEnvironment('local');
            $this->guard()->configure();
            $this->configureIsolatedDatabase();
            DB::listen(static function () use (&$queries): void {
                $queries[] = true;
            });

            $this->assertSame(1, Artisan::call('db:wipe', [
                '--database' => 'guard_test',
                '--force' => true,
            ]));
            $this->assertSame([], $queries);
        } finally {
            $this->app->useEnvironmentPath($originalEnvironmentPath);
            $this->app->loadEnvironmentFrom($originalEnvironmentFile);
            $this->setEnvironment('testing');
            $this->guard()->configure();
            $files->deleteDirectory($environmentPath);
        }
    }

    public function test_normal_migration_and_unrelated_artisan_commands_remain_available_in_production(): void
    {
        $user = User::factory()->create();

        $this->setEnvironment('production');
        $this->guard()->configure();

        try {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertSame(0, Artisan::call('about'));
        } finally {
            $this->setEnvironment('testing');
            $this->guard()->configure();
        }

        $this->assertSame(1, User::query()->whereKey($user->id)->count());
    }

    public function test_rejection_message_and_log_are_safe_and_command_specific(): void
    {
        $this->setEnvironment('production');
        $output = new BufferedOutput;
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('Destructive database command was blocked in production.', [
                'command' => 'migrate:fresh',
            ]);
        $guard = new ProductionDestructiveDatabaseCommandGuard(
            $this->app,
            $this->app->make('config'),
            $logger,
            $this->app->make(Filesystem::class),
        );

        $guard(new CommandStarting('migrate:fresh', new ArrayInput([]), $output));

        $this->assertSame(
            'Destructive database command [migrate:fresh] is disabled in production.'.PHP_EOL,
            $output->fetch(),
        );
    }

    private function guard(): ProductionDestructiveDatabaseCommandGuard
    {
        return $this->app->make(ProductionDestructiveDatabaseCommandGuard::class);
    }

    private function setEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
        $this->app['config']->set('app.env', $environment);
    }

    private function configureIsolatedDatabase(): void
    {
        DB::purge('guard_test');
        $this->app['config']->set('database.connections.guard_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
