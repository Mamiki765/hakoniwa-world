<?php

namespace Tests\Concerns;

use App\Application\OceanWorldGenerator;
use App\Domain\World\WorldBounds;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapSpace;
use App\Models\World;

trait CreatesTestWorlds
{
    protected function lightweightWorld(): World
    {
        return app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
    }

    protected function surfaceMapSpace(World $world): MapSpace
    {
        return MapSpace::query()
            ->where('world_id', $world->id)
            ->where('key', config('hakoniwa.world.map_space_key'))
            ->firstOrFail();
    }

    protected function boundsFor(World $world): WorldBounds
    {
        $mapSpace = $this->surfaceMapSpace($world);

        return new WorldBounds(
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
            (int) config('hakoniwa.ruleset.chunk_size'),
        );
    }
}
