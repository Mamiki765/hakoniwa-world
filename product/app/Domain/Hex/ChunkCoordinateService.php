<?php

namespace App\Domain\Hex;

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

    /** @return array{chunk_q: int, chunk_r: int, local_q: int, local_r: int} */
    public function locate(int $q, int $r): array
    {
        return [
            'chunk_q' => $this->floorDiv($q),
            'chunk_r' => $this->floorDiv($r),
            'local_q' => $this->floorMod($q),
            'local_r' => $this->floorMod($r),
        ];
    }
}
