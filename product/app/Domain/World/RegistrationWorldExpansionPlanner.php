<?php

namespace App\Domain\World;

use DomainException;

final class RegistrationWorldExpansionPlanner
{
    private const CHUNK_SIZE = 16;

    private const CANONICAL_BASE_MAX = 63;

    public function nextBounds(MapBounds $current): MapBounds
    {
        if ($current->chunkSize !== self::CHUNK_SIZE) {
            throw new DomainException('Automatic registration expansion requires 16-cell chunks.');
        }

        // 60x60 occupied the same 4x4 chunks as 64x64. Complete those partial
        // chunks and add the first useful LEFT chunk in one atomic expansion.
        if ($current->equals(new MapBounds(0, 59, 0, 59, self::CHUNK_SIZE))) {
            return new MapBounds(-16, 63, 0, 63, self::CHUNK_SIZE);
        }

        $offsets = [
            'left' => -$current->minX,
            'up' => -$current->minY,
            'right' => $current->maxX - self::CANONICAL_BASE_MAX,
            'down' => $current->maxY - self::CANONICAL_BASE_MAX,
        ];
        foreach ($offsets as $direction => $offset) {
            if ($offset < 0 || $offset % self::CHUNK_SIZE !== 0) {
                throw new DomainException(
                    "Current World bounds cannot be interpreted as the canonical expansion rotation ({$direction}).",
                );
            }
        }

        $counts = array_map(
            static fn (int $offset): int => intdiv($offset, self::CHUNK_SIZE),
            $offsets,
        );
        $steps = array_sum($counts);
        $cycles = intdiv($steps, 4);
        $remainder = $steps % 4;
        $expected = [
            'left' => $cycles + ($remainder >= 1 ? 1 : 0),
            'up' => $cycles + ($remainder >= 2 ? 1 : 0),
            'right' => $cycles + ($remainder >= 3 ? 1 : 0),
            'down' => $cycles,
        ];
        if ($counts !== $expected) {
            throw new DomainException(
                'Current World bounds cannot be interpreted as the canonical LEFT/UP/RIGHT/DOWN rotation.',
            );
        }

        return match ($remainder) {
            0 => new MapBounds($this->subtractChunk($current->minX), $current->maxX, $current->minY, $current->maxY, self::CHUNK_SIZE),
            1 => new MapBounds($current->minX, $current->maxX, $this->subtractChunk($current->minY), $current->maxY, self::CHUNK_SIZE),
            2 => new MapBounds($current->minX, $this->addChunk($current->maxX), $current->minY, $current->maxY, self::CHUNK_SIZE),
            3 => new MapBounds($current->minX, $current->maxX, $current->minY, $this->addChunk($current->maxY), self::CHUNK_SIZE),
            default => throw new DomainException('Canonical expansion rotation remainder is outside 0..3.'),
        };
    }

    private function subtractChunk(int $coordinate): int
    {
        if ($coordinate < -2_147_483_632) {
            throw new DomainException('Automatic World expansion would exceed signed 32-bit coordinates.');
        }

        return $coordinate - self::CHUNK_SIZE;
    }

    private function addChunk(int $coordinate): int
    {
        if ($coordinate > 2_147_483_631) {
            throw new DomainException('Automatic World expansion would exceed signed 32-bit coordinates.');
        }

        return $coordinate + self::CHUNK_SIZE;
    }
}
