<?php

namespace Tests\Unit;

use App\Domain\Map\ChunkCoordinateService;
use App\Domain\World\MapBounds;
use App\Domain\World\MapSpaceCoverageValidator;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MapSpaceCoverageValidatorTest extends TestCase
{
    #[DataProvider('completeBounds')]
    public function test_complete_rectangles_accept_full_and_partial_edge_chunks(
        int $minX,
        int $maxX,
        int $minY,
        int $maxY,
    ): void {
        $bounds = new MapBounds($minX, $maxX, $minY, $maxY, 16);

        (new MapSpaceCoverageValidator)->assertComplete($bounds, $this->cells($bounds));

        $this->assertSame($bounds->cellCount(), count($this->cells($bounds)));
    }

    public static function completeBounds(): array
    {
        return [
            'production-shaped 60x60 with partial edges' => [0, 59, 0, 59],
            'artificial aligned 64x64' => [0, 63, 0, 63],
            'artificial signed expansion shape' => [-16, 79, -16, 79],
        ];
    }

    public function test_partial_edge_chunk_expected_size_is_bounds_intersection_not_256(): void
    {
        $bounds = new MapBounds(0, 59, 0, 59, 16);

        $this->assertSame(256, $bounds->cellCountWithinChunk(0, 0));
        $this->assertSame(192, $bounds->cellCountWithinChunk(3, 0));
        $this->assertSame(144, $bounds->cellCountWithinChunk(3, 3));
        $this->assertSame(0, $bounds->cellCountWithinChunk(4, 3));
    }

    public function test_missing_duplicate_and_inconsistent_chunk_coordinates_fail_closed(): void
    {
        $bounds = new MapBounds(-1, 0, -1, 0, 16);
        $valid = $this->cells($bounds);

        foreach (['missing', 'duplicate', 'wrong_chunk', 'wrong_local'] as $corruption) {
            $cells = $valid;
            if ($corruption === 'missing') {
                array_pop($cells);
            } elseif ($corruption === 'duplicate') {
                $cells[] = $cells[0];
            } elseif ($corruption === 'wrong_chunk') {
                $cells[0]['chunk_x']++;
            } else {
                $cells[0]['local_y'] = 16;
            }

            try {
                (new MapSpaceCoverageValidator)->assertComplete($bounds, $cells);
                $this->fail("{$corruption} coverage unexpectedly passed.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return list<array{x: int, y: int, chunk_x: int, chunk_y: int, local_x: int, local_y: int}> */
    private function cells(MapBounds $bounds): array
    {
        $chunks = new ChunkCoordinateService($bounds->chunkSize);
        $cells = [];
        for ($y = $bounds->minY; $y <= $bounds->maxY; $y++) {
            for ($x = $bounds->minX; $x <= $bounds->maxX; $x++) {
                $cells[] = ['x' => $x, 'y' => $y, ...$chunks->locate($x, $y)];
            }
        }

        return $cells;
    }
}
