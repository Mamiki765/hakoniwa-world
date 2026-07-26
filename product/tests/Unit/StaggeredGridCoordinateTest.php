<?php

namespace Tests\Unit;

use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\StaggeredProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StaggeredGridCoordinateTest extends TestCase
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

    public function test_chunk_and_local_coordinates_use_xy_names(): void
    {
        $service = new ChunkCoordinateService(16);

        $this->assertSame(
            ['chunk_x' => 3, 'chunk_y' => 0, 'local_x' => 11, 'local_y' => 15],
            $service->locate(59, 15),
        );
    }

    public function test_even_row_neighbors_match_right_shifted_rows(): void
    {
        $origin = new GridCoordinate(10, 8);

        $this->assertSame([
            [11, 8], [11, 7], [10, 7], [9, 8], [10, 9], [11, 9],
        ], $this->pairs($origin));
    }

    public function test_odd_row_neighbors_match_unshifted_rows(): void
    {
        $origin = new GridCoordinate(10, 9);

        $this->assertSame([
            [11, 9], [10, 8], [9, 8], [9, 9], [9, 10], [10, 10],
        ], $this->pairs($origin));
    }

    public function test_corner_and_edge_neighbors_exclude_world_bounds(): void
    {
        $corner = new GridCoordinate(0, 0);
        $edge = new GridCoordinate(59, 1);

        $this->assertSame([[1, 0], [0, 1], [1, 1]], array_map(
            static fn (GridCoordinate $coordinate): array => [$coordinate->x, $coordinate->y],
            $corner->neighborsWithin(0, 59, 0, 59),
        ));
        $this->assertCount(5, $edge->neighborsWithin(0, 59, 0, 59));
    }

    public function test_distance_is_symmetric_zero_for_self_and_one_for_neighbors(): void
    {
        $origin = new GridCoordinate(0, 0);
        $other = new GridCoordinate(4, 4);

        $this->assertSame(0, $origin->distanceTo($origin));
        foreach (range(0, 5) as $direction) {
            $this->assertSame(1, $origin->distanceTo($origin->neighbor($direction)));
        }
        $this->assertSame($origin->distanceTo($other), $other->distanceTo($origin));
        $this->assertSame(6, $origin->distanceTo($other));
        $this->assertCount(19, $origin->radius(2));
        $this->assertCount(18, $origin->ring(3));
        $this->assertCount(91, $origin->radius(5));
    }

    public function test_square_tile_projection_alternates_without_horizontal_drift(): void
    {
        $projection = new StaggeredProjection;

        $this->assertSame(['x' => 16, 'y' => 0], $projection->toPixel(new GridCoordinate(0, 0)));
        $this->assertSame(['x' => 48, 'y' => 0], $projection->toPixel(new GridCoordinate(1, 0)));
        $this->assertSame(['x' => 0, 'y' => 32], $projection->toPixel(new GridCoordinate(0, 1)));
        $this->assertSame(['x' => 16, 'y' => 64], $projection->toPixel(new GridCoordinate(0, 2)));
        $this->assertSame(16, $projection->toPixel(new GridCoordinate(0, 58))['x']);
        $this->assertSame(0, $projection->toPixel(new GridCoordinate(0, 59))['x']);
    }

    /** @return list<array{0: int, 1: int}> */
    private function pairs(GridCoordinate $coordinate): array
    {
        return array_map(
            static fn (int $direction): array => [
                $coordinate->neighbor($direction)->x,
                $coordinate->neighbor($direction)->y,
            ],
            range(0, 5),
        );
    }
}
