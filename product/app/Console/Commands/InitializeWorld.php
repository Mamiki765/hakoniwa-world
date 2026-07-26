<?php

namespace App\Console\Commands;

use App\Application\OceanWorldGenerator;
use Illuminate\Console\Command;

class InitializeWorld extends Command
{
    protected $signature = 'hakoniwa:world:init';

    protected $description = 'Idempotently initialize the shared surface world as ocean.';

    public function handle(OceanWorldGenerator $generator): int
    {
        $world = $generator->initialize();
        $count = $world->mapSpaces()->firstOrFail()->cells()->count();
        $this->info("World {$world->key} is ready with {$count} ocean cells.");

        return self::SUCCESS;
    }
}
