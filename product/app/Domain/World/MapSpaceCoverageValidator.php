<?php

namespace App\Domain\World;

use App\Domain\Map\ChunkCoordinateService;
use DomainException;

final readonly class MapSpaceCoverageValidator
{
    /**
     * This is an operation/preflight boundary, not a MapChunk GET-path check.
     *
     * @param  iterable<array<string, mixed>|object>  $cells
     */
    public function assertComplete(
        MapBounds $bounds,
        iterable $cells,
        ?int $expectedMapSpaceId = null,
    ): void {
        $chunks = new ChunkCoordinateService($bounds->chunkSize);
        $coordinates = [];

        foreach ($cells as $cell) {
            if ($expectedMapSpaceId !== null
                && $this->field($cell, 'map_space_id') !== $expectedMapSpaceId) {
                throw new DomainException('MapCell belongs to a different MapSpace.');
            }

            $x = $this->field($cell, 'x');
            $y = $this->field($cell, 'y');
            if (! $bounds->contains($x, $y)) {
                throw new DomainException("MapCell ({$x}, {$y}) is outside current MapSpace bounds.");
            }

            $key = $x.':'.$y;
            if (isset($coordinates[$key])) {
                throw new DomainException("MapSpace coverage contains duplicate coordinate ({$x}, {$y}).");
            }

            $location = $chunks->locate($x, $y);
            foreach (['chunk_x', 'chunk_y', 'local_x', 'local_y'] as $field) {
                $actual = $this->field($cell, $field);
                if ($actual !== $location[$field]) {
                    throw new DomainException(
                        "MapCell ({$x}, {$y}) has {$field}={$actual}; expected {$location[$field]}.",
                    );
                }
            }
            if ($location['local_x'] < 0 || $location['local_x'] >= $bounds->chunkSize
                || $location['local_y'] < 0 || $location['local_y'] >= $bounds->chunkSize) {
                throw new DomainException("MapCell ({$x}, {$y}) has local coordinates outside its chunk.");
            }

            $coordinates[$key] = true;
        }

        if (count($coordinates) !== $bounds->cellCount()) {
            throw new DomainException(
                'MapSpace coverage has '.count($coordinates)." coordinates; expected {$bounds->cellCount()}.",
            );
        }

        for ($y = $bounds->minY; $y <= $bounds->maxY; $y++) {
            for ($x = $bounds->minX; $x <= $bounds->maxX; $x++) {
                if (! isset($coordinates[$x.':'.$y])) {
                    throw new DomainException("MapSpace coverage is missing coordinate ({$x}, {$y}).");
                }
            }
        }
    }

    /** @param array<string, mixed>|object $cell */
    private function field(array|object $cell, string $field): int
    {
        $value = is_array($cell) ? ($cell[$field] ?? null) : ($cell->{$field} ?? null);
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)) {
            throw new DomainException("MapCell {$field} must be an integer.");
        }

        return (int) $value;
    }
}
