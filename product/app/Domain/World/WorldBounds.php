<?php

namespace App\Domain\World;

use App\Domain\Map\GridCoordinate;
use DomainException;

final readonly class WorldBounds
{
    public function __construct(
        public int $minX,
        public int $maxX,
        public int $minY,
        public int $maxY,
        public int $chunkSize,
    ) {
        if ($minX !== 0 || $minY !== 0 || $maxX < $minX || $maxY < $minY) {
            throw new DomainException('World bounds must start at x=0, y=0 and have non-negative dimensions.');
        }
        if ($chunkSize < 1) {
            throw new DomainException('World chunk size must be positive.');
        }
    }

    /** @param array<string, mixed> $rules */
    public static function fromRuleset(array $rules): self
    {
        return new self(
            (int) $rules['initial_x_min'],
            (int) $rules['initial_x_max'],
            (int) $rules['initial_y_min'],
            (int) $rules['initial_y_max'],
            (int) $rules['chunk_size'],
        );
    }

    public static function debug32x32(int $chunkSize): self
    {
        return new self(0, 31, 0, 31, $chunkSize);
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
        return (int) ceil($this->width() / $this->chunkSize)
            * (int) ceil($this->height() / $this->chunkSize);
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
}
