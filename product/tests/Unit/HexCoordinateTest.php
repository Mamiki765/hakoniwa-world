<?php

namespace Tests\Unit;

use App\Domain\Hex\ChunkCoordinateService;
use App\Domain\Hex\HexCoordinate;
use App\Domain\Hex\StaggeredProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HexCoordinateTest extends TestCase
{
    #[DataProvider('chunkBoundaries')]
    public function test_chunk_boundaries_include_negative_values(int $value, int $chunk, int $local): void
    {
        $service = new ChunkCoordinateService(16);
        $this->assertSame($chunk, $service->floorDiv($value));
        $this->assertSame($local, $service->floorMod($value));
    }

    public static function chunkBoundaries(): array
    {
        return [[0, 0, 0], [15, 0, 15], [16, 1, 0], [-1, -1, 15], [-16, -1, 0], [-17, -2, 15]];
    }

    public function test_neighbors_distance_radius_and_ring(): void
    {
        $origin = new HexCoordinate(0, 0);
        $neighbors = array_map(fn (int $direction): HexCoordinate => $origin->neighbor($direction), range(0, 5));

        $this->assertCount(6, array_unique(array_map(fn (HexCoordinate $hex): string => $hex->q.':'.$hex->r, $neighbors)));
        $this->assertSame(4, $origin->distanceTo(new HexCoordinate(-2, 4)));
        $this->assertCount(19, $origin->radius(2));
        $this->assertCount(18, $origin->ring(3));
        $this->assertSame(19, count(array_unique(array_map(fn (HexCoordinate $hex): string => $hex->q.':'.$hex->r, $origin->radius(2)))));
        $this->assertCount(91, $origin->radius(5));
    }

    public function test_staggered_square_tile_projection_matches_frontend_contract(): void
    {
        $projection = new StaggeredProjection;

        $this->assertSame(['x' => 16, 'y' => 0], $projection->toPixel(new HexCoordinate(0, 0)));
        $this->assertSame(['x' => 32, 'y' => 32], $projection->toPixel(new HexCoordinate(0, 1)));
        $this->assertSame(['x' => 0, 'y' => -32], $projection->toPixel(new HexCoordinate(0, -1)));
    }

    public function test_odd_q_round_trip_supports_negative_coordinates(): void
    {
        foreach ([new HexCoordinate(0, 0), new HexCoordinate(5, -9), new HexCoordinate(-7, 3), new HexCoordinate(-1, -1)] as $hex) {
            $offset = $hex->toOddQ();
            $this->assertEquals($hex, HexCoordinate::fromOddQ($offset['column'], $offset['row']));
        }
    }
}
