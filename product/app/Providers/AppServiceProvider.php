<?php

namespace App\Providers;

use App\Application\CompleteTurnEngine;
use App\Application\InitialIslandGenerator;
use App\Application\LegacyInspiredInitialIslandGenerator;
use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Turn\GameplayTurnPhase;
use App\Domain\Turn\RandomTurnSeedGenerator;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnSeedGenerator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ChunkCoordinateService::class, fn (): ChunkCoordinateService => new ChunkCoordinateService(
            (int) config('hakoniwa.ruleset.chunk_size'),
        ));
        $this->app->bind(InitialIslandGenerator::class, LegacyInspiredInitialIslandGenerator::class);
        $this->app->bind(TurnSeedGenerator::class, RandomTurnSeedGenerator::class);
        $this->app->bind(TurnPipeline::class, function ($app): TurnPipeline {
            $engine = $app->make(CompleteTurnEngine::class);

            return new TurnPipeline(array_map(
                static fn (string $key): GameplayTurnPhase => new GameplayTurnPhase($key, $engine),
                TurnPipeline::CANONICAL_PHASE_KEYS,
            ));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('discord', Provider::class);
        });
    }
}
