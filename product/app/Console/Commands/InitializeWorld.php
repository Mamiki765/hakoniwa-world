<?php

namespace App\Console\Commands;

use App\Application\OceanWorldGenerator;
use App\Domain\World\WorldBounds;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapSpace;
use App\Models\World;
use Illuminate\Console\Command;

class InitializeWorld extends Command
{
    protected $signature = 'hakoniwa:world:init';

    protected $description = 'Idempotently initialize the shared surface world as ocean.';

    public function handle(OceanWorldGenerator $generator): int
    {
        $world = $generator->initialize($this->existingProfile());
        $count = $world->mapSpaces()->firstOrFail()->cells()->count();
        $this->info("World {$world->key} is ready with {$count} ocean cells.");

        return self::SUCCESS;
    }

    private function existingProfile(): WorldGenerationProfile
    {
        $world = World::query()->where('key', config('hakoniwa.world.key'))->first();
        if ($world === null) {
            return WorldGenerationProfile::Production;
        }

        $mapSpace = MapSpace::query()
            ->where('world_id', $world->id)
            ->where('key', config('hakoniwa.world.map_space_key'))
            ->first();
        if ($mapSpace === null) {
            return WorldGenerationProfile::Production;
        }

        $rules = $world->rulesetVersion()->firstOrFail()->settings;
        $bounds = new WorldBounds(
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
            (int) $rules['chunk_size'],
        );

        return WorldGenerationProfile::matchingBounds($bounds, $rules)
            ?? WorldGenerationProfile::Production;
    }
}
