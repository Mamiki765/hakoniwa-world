<?php

namespace Tests\Unit;

use App\Domain\World\InitialWorldBounds;
use App\Domain\World\MapBounds;
use DomainException;
use PHPUnit\Framework\TestCase;

class WorldBoundsTest extends TestCase
{
    public function test_production_bounds_derive_dimensions_counts_chunks_and_center(): void
    {
        $bounds = new InitialWorldBounds(0, 59, 0, 59, 16);

        $this->assertSame(60, $bounds->width());
        $this->assertSame(60, $bounds->height());
        $this->assertSame(3600, $bounds->cellCount());
        $this->assertSame(16, $bounds->chunkCount());
        $this->assertSame([30, 30], [$bounds->center()->x, $bounds->center()->y]);
    }

    public function test_debug_bounds_cover_chunk_edges_and_derive_their_center(): void
    {
        $bounds = InitialWorldBounds::debug32x32(16);

        $this->assertSame(range(0, 31), $bounds->xCoordinates());
        $this->assertSame(range(0, 31), $bounds->yCoordinates());
        $this->assertSame(1024, $bounds->cellCount());
        $this->assertSame(4, $bounds->chunkCount());
        $this->assertSame([16, 16], [$bounds->center()->x, $bounds->center()->y]);
    }

    public function test_initial_contract_remains_zero_origin_while_current_bounds_are_signed(): void
    {
        $current = new MapBounds(-17, 79, -16, 79, 16);

        $this->assertSame([-17, 79, -16, 79], [
            $current->minX, $current->maxX, $current->minY, $current->maxY,
        ]);
        $this->assertSame(42, $current->chunkCount());

        $this->expectException(DomainException::class);
        new InitialWorldBounds(-1, 59, 0, 59, 16);
    }

    public function test_ruleset_initial_bounds_contract_is_unchanged(): void
    {
        $bounds = InitialWorldBounds::fromRuleset([
            'initial_x_min' => 0,
            'initial_x_max' => 59,
            'initial_y_min' => 0,
            'initial_y_max' => 59,
            'chunk_size' => 16,
        ]);

        $this->assertSame([0, 59, 0, 59], [$bounds->minX, $bounds->maxX, $bounds->minY, $bounds->maxY]);
    }

    public function test_current_bounds_revision_is_deterministic_and_changes_with_signed_bounds(): void
    {
        $first = new MapBounds(0, 59, 0, 59, 16);
        $same = new MapBounds(0, 59, 0, 59, 16);
        $expanded = new MapBounds(-16, 59, 0, 59, 16);

        $this->assertSame($first->revision(), $same->revision());
        $this->assertNotSame($first->revision(), $expanded->revision());
        $this->assertTrue($expanded->containsBounds(new InitialWorldBounds(0, 59, 0, 59, 16)));
    }
}
