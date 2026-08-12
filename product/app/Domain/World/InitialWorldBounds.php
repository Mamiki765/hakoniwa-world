<?php

namespace App\Domain\World;

use DomainException;

final readonly class InitialWorldBounds extends MapBounds
{
    public function __construct(int $minX, int $maxX, int $minY, int $maxY, int $chunkSize)
    {
        parent::__construct($minX, $maxX, $minY, $maxY, $chunkSize);

        if ($minX !== 0 || $minY !== 0) {
            throw new DomainException('Initial World bounds must start at x=0 and y=0.');
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
}
