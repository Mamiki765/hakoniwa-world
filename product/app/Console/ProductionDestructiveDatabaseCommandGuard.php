<?php

namespace App\Console;

use Dotenv\Dotenv;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ProductionDestructiveDatabaseCommandGuard
{
    /** @var array<string, class-string> */
    private const array GUARDED_COMMANDS = [
        'migrate:fresh' => FreshCommand::class,
        'migrate:refresh' => RefreshCommand::class,
        'migrate:reset' => ResetCommand::class,
        'migrate:rollback' => RollbackCommand::class,
        'db:wipe' => WipeCommand::class,
    ];

    public function __construct(
        private Application $app,
        private Repository $config,
        private LoggerInterface $logger,
        private Filesystem $files,
    ) {}

    public function configure(): void
    {
        $prohibit = $this->isProduction();

        foreach (self::GUARDED_COMMANDS as $commandClass) {
            $commandClass::prohibit($prohibit);
        }
    }

    public function __invoke(CommandStarting $event): void
    {
        if (! $this->isProduction() || ! isset(self::GUARDED_COMMANDS[$event->command])) {
            return;
        }

        $event->output->writeln(sprintf(
            '<error>Destructive database command [%s] is disabled in production.</error>',
            $event->command,
        ));
        $this->logger->warning('Destructive database command was blocked in production.', [
            'command' => $event->command,
        ]);
    }

    private function isProduction(): bool
    {
        return $this->app->environment('production')
            || $this->config->get('app.env') === 'production'
            || getenv('APP_ENV') === 'production'
            || ($_SERVER['APP_ENV'] ?? null) === 'production'
            || ($_ENV['APP_ENV'] ?? null) === 'production'
            || $this->defaultEnvironmentIsProduction();
    }

    private function defaultEnvironmentIsProduction(): bool
    {
        $path = $this->app->environmentPath().DIRECTORY_SEPARATOR.'.env';

        if (! $this->files->isFile($path)) {
            return false;
        }

        try {
            $values = Dotenv::parse($this->files->get($path));
        } catch (Throwable) {
            return true;
        }

        return ($values['APP_ENV'] ?? null) === 'production';
    }
}
