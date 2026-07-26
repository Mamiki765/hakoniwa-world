<?php

namespace App\Application;

use App\Domain\Hex\HexCoordinate;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCapital;

interface InitialIslandGenerator
{
    public function generate(MapSpace $mapSpace, Nation $nation, HexCoordinate $center, string $seed): NationCapital;
}
