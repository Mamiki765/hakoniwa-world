<?php

namespace App\Providers;

use App\Application\InitialIslandGenerator;
use App\Application\LegacyInspiredInitialIslandGenerator;
use App\Domain\Hex\ChunkCoordinateService;
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
