<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCapital;

interface InitialIslandGenerator
{
    public function generate(MapSpace $mapSpace, Nation $nation, GridCoordinate $center, string $seed): NationCapital;
}
