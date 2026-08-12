<?php

namespace App\Domain\World;

use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Map\GridCoordinate;
use DomainException;

readonly class MapBounds
{
    public function __construct(
        public int $minX,
        public int $maxX,
        public int $minY,
        public int $maxY,
        public int $chunkSize,
    ) {
        if ($maxX < $minX || $maxY < $minY) {
            throw new DomainException('Map bounds must have non-empty x and y ranges.');
        }
        if ($chunkSize < 1) {
            throw new DomainException('Map chunk size must be positive.');
        }
    }

    public function width(): int
    {
        return $this->maxX - $this->minX + 1;
    }

    public function height(): int
    {
        return $this->maxY - $this->minY + 1;
    }

    public function cellCount(): int
    {
        return $this->width() * $this->height();
    }

    public function rowCount(): int
    {
        return $this->height();
    }

    public function columnCount(): int
    {
        return $this->width();
    }

    public function chunkCount(): int
    {
        $chunks = new ChunkCoordinateService($this->chunkSize);

        return ($chunks->floorDiv($this->maxX) - $chunks->floorDiv($this->minX) + 1)
            * ($chunks->floorDiv($this->maxY) - $chunks->floorDiv($this->minY) + 1);
    }

    /** @return list<int> */
    public function xCoordinates(): array
    {
        return range($this->minX, $this->maxX);
    }

    /** @return list<int> */
    public function yCoordinates(): array
    {
        return range($this->minY, $this->maxY);
    }

    public function center(): GridCoordinate
    {
        return new GridCoordinate(
            $this->minX + intdiv($this->width(), 2),
            $this->minY + intdiv($this->height(), 2),
        );
    }

    public function contains(int $x, int $y): bool
    {
        return $x >= $this->minX && $x <= $this->maxX
            && $y >= $this->minY && $y <= $this->maxY;
    }

    public function containsBounds(self $other): bool
    {
        return $this->contains($other->minX, $other->minY)
            && $this->contains($other->maxX, $other->maxY);
    }

    /** @return array{min_x: int, max_x: int, min_y: int, max_y: int}|null */
    public function intersectionWithChunk(int $chunkX, int $chunkY): ?array
    {
        $chunkMinX = $chunkX * $this->chunkSize;
        $chunkMinY = $chunkY * $this->chunkSize;
        $minX = max($this->minX, $chunkMinX);
        $maxX = min($this->maxX, $chunkMinX + $this->chunkSize - 1);
        $minY = max($this->minY, $chunkMinY);
        $maxY = min($this->maxY, $chunkMinY + $this->chunkSize - 1);

        if ($minX > $maxX || $minY > $maxY) {
            return null;
        }

        return [
            'min_x' => $minX,
            'max_x' => $maxX,
            'min_y' => $minY,
            'max_y' => $maxY,
        ];
    }

    public function cellCountWithinChunk(int $chunkX, int $chunkY): int
    {
        $intersection = $this->intersectionWithChunk($chunkX, $chunkY);
        if ($intersection === null) {
            return 0;
        }

        return ($intersection['max_x'] - $intersection['min_x'] + 1)
            * ($intersection['max_y'] - $intersection['min_y'] + 1);
    }

    public function revision(): string
    {
        return hash('sha256', implode(':', [
            'map-bounds-v1',
            $this->minX,
            $this->maxX,
            $this->minY,
            $this->maxY,
            $this->chunkSize,
        ]));
    }

    public function equals(self $other): bool
    {
        return $this->minX === $other->minX
            && $this->maxX === $other->maxX
            && $this->minY === $other->minY
            && $this->maxY === $other->maxY
            && $this->chunkSize === $other->chunkSize;
    }
}
