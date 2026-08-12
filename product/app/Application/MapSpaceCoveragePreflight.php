<?php

namespace App\Application;

use App\Domain\World\MapSpaceCoverageValidator;
use App\Models\MapSpace;

final readonly class MapSpaceCoveragePreflight
{
    public function __construct(private MapSpaceCoverageValidator $validator) {}

    public function assertComplete(MapSpace $mapSpace): void
    {
        $cells = $mapSpace->cells()
            ->select(['x', 'y', 'chunk_x', 'chunk_y', 'local_x', 'local_y'])
            ->orderBy('y')
            ->orderBy('x')
            ->cursor();

        $this->validator->assertComplete($mapSpace->currentBounds(), $cells);
    }
}
