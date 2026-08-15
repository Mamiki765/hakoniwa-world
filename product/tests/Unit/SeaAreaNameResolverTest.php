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

        $this->assertSame('ペリドット海域', $resolver->forCoordinate(0, 0));
        $this->assertSame('ペリドット海域', $resolver->forCoordinate(15, 15));
        $this->assertSame('サファイア海域', $resolver->forCoordinate(16, 0));
        $this->assertSame('アクアマリン海域', $resolver->forCoordinate(-1, -1));

        $names = [];
        for ($chunkY = -1; $chunkY <= 4; $chunkY++) {
            for ($chunkX = -2; $chunkX <= 4; $chunkX++) {
                $name = $resolver->forCoordinate($chunkX * 16, $chunkY * 16);
                $this->assertNotSame('原石の海域', $name);
                $names[] = $name;
            }
        }
        $this->assertCount(42, array_unique($names));
        $this->assertSame('原石の海域', $resolver->forCoordinate(16 * 99, 16 * -99));
    }
}
