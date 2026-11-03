<?php

namespace Tests\Unit;

use App\Application\DisasterMutableCellIndex;
use App\Domain\Map\GridCoordinate;
use App\Models\MapCell;
use PHPUnit\Framework\TestCase;

final class DisasterMutableCellIndexTest extends TestCase
{
    public function test_radius_lookup_keeps_id_order_and_current_indexed_cells(): void
    {
        $center = new GridCoordinate(0, 0);
        $west = $this->cell(40, $center->neighbor(GridCoordinate::WEST));
        $east = $this->cell(10, $center->neighbor(GridCoordinate::EAST));
        $edge = $this->cell(20, $center->ring(2)[0]);
        $origin = $this->cell(90, $center);
        $outside = $this->cell(1, $center->ring(3)[0]);
        $index = DisasterMutableCellIndex::fromCells([$origin, $outside, $west, $edge, $east]);

        $cells = $index->cellsWithinRadius($center, 2);

        $this->assertSame([10, 20, 40, 90], array_map(static fn (MapCell $cell): int => $cell->id, $cells));
        $this->assertSame($east, $cells[0]);
        $east->owner_nation_id = 7;
        $added = $this->cell(5, $center->neighbor(GridCoordinate::SOUTH_EAST));
        $index->addCells([$added]);

        $current = $index->cellsWithinRadius($center, 2);
        $this->assertSame([5, 10, 20, 40, 90], array_map(static fn (MapCell $cell): int => $cell->id, $current));
        $this->assertSame(7, $current[1]->owner_nation_id);
        $this->assertSame($added, $current[0]);
    }

    private function cell(int $id, GridCoordinate $coordinate): MapCell
    {
        return (new MapCell)->setRawAttributes(['id' => $id, 'x' => $coordinate->x, 'y' => $coordinate->y]);
    }
}
