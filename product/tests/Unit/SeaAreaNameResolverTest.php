<?php

namespace Tests\Unit;

use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Map\SeaAreaNameResolver;
use PHPUnit\Framework\TestCase;

final class SeaAreaNameResolverTest extends TestCase
{
    public function test_names_are_chunk_stable_negative_safe_and_prepared_through_five_expansions(): void
    {
        $resolver = new SeaAreaNameResolver(new ChunkCoordinateService(16));
        $fallback = $resolver->forCoordinate(16 * 99, 16 * -99);

        $this->assertSame($fallback, $resolver->forCoordinate(16 * -99, 16 * 99));
        $this->assertSame($resolver->forCoordinate(0, 0), $resolver->forCoordinate(15, 15));
        $this->assertNotSame($resolver->forCoordinate(0, 0), $resolver->forCoordinate(16, 0));
        $this->assertSame($resolver->forCoordinate(-16, -16), $resolver->forCoordinate(-1, -1));
        $this->assertNotSame($resolver->forCoordinate(0, 0), $resolver->forCoordinate(-1, -1));

        for ($chunkY = -1; $chunkY <= 4; $chunkY++) {
            for ($chunkX = -2; $chunkX <= 4; $chunkX++) {
                $this->assertNotSame($fallback, $resolver->forCoordinate($chunkX * 16, $chunkY * 16));
            }
        }
    }
}
