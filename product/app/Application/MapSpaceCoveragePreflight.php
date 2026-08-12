<?php

namespace App\Application;

use App\Domain\World\MapBounds;
use App\Domain\World\MapSpaceCoverageValidator;
use App\Models\MapSpace;

final readonly class MapSpaceCoveragePreflight
{
    public function __construct(private MapSpaceCoverageValidator $validator) {}

    public function assertComplete(MapSpace $mapSpace, ?MapBounds $bounds = null): void
    {
        $cells = $mapSpace->cells()
            ->select(['map_space_id', 'x', 'y', 'chunk_x', 'chunk_y', 'local_x', 'local_y'])
            ->orderBy('y')
            ->orderBy('x')
            ->cursor();

        $this->validator->assertComplete(
            $bounds ?? $mapSpace->currentBounds(),
            $cells,
            $mapSpace->id,
        );
    }
}
