<?php

namespace App\Domain\Map;

use InvalidArgumentException;

final class ChunkCoordinateService
{
    public function __construct(private readonly int $size = 16)
    {
        if ($size <= 0) {
            throw new InvalidArgumentException('Chunk size must be positive.');
        }
    }

    public function floorDiv(int $value): int
    {
        $quotient = intdiv($value, $this->size);

        if ($value < 0 && $value % $this->size !== 0) {
            return $quotient - 1;
        }

        return $quotient;
    }

    public function floorMod(int $value): int
    {
        return $value - $this->floorDiv($value) * $this->size;
    }

    /** @return array{chunk_x: int, chunk_y: int, local_x: int, local_y: int} */
    public function locate(int $x, int $y): array
    {
        return [
            'chunk_x' => $this->floorDiv($x),
            'chunk_y' => $this->floorDiv($y),
            'local_x' => $this->floorMod($x),
            'local_y' => $this->floorMod($y),
        ];
    }
}
