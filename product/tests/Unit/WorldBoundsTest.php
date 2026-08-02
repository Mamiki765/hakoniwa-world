<?php

namespace Tests\Unit;

use App\Domain\World\WorldBounds;
use PHPUnit\Framework\TestCase;

class WorldBoundsTest extends TestCase
{
    public function test_production_bounds_derive_dimensions_counts_chunks_and_center(): void
    {
        $bounds = new WorldBounds(0, 59, 0, 59, 16);

        $this->assertSame(60, $bounds->width());
        $this->assertSame(60, $bounds->height());
        $this->assertSame(3600, $bounds->cellCount());
        $this->assertSame(16, $bounds->chunkCount());
        $this->assertSame([30, 30], [$bounds->center()->x, $bounds->center()->y]);
    }

    public function test_debug_bounds_cover_chunk_edges_and_derive_their_center(): void
    {
        $bounds = WorldBounds::debug32x32(16);

        $this->assertSame(range(0, 31), $bounds->xCoordinates());
        $this->assertSame(range(0, 31), $bounds->yCoordinates());
        $this->assertSame(1024, $bounds->cellCount());
        $this->assertSame(4, $bounds->chunkCount());
        $this->assertSame([16, 16], [$bounds->center()->x, $bounds->center()->y]);
    }
}
