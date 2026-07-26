<?php

namespace App\Domain\Map;

final class StaggeredProjection
{
    public const TILE_SIZE = 32;

    public const HALF_TILE = 16;

    public const VERTICAL_STEP = 32;

    /** @return array{x: int, y: int} */
    public function toPixel(GridCoordinate $coordinate): array
    {
        return [
            'x' => $coordinate->x * self::TILE_SIZE + (self::floorMod($coordinate->y, 2) === 0 ? self::HALF_TILE : 0),
            'y' => $coordinate->y * self::VERTICAL_STEP,
        ];
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }

    private static function floorMod(int $value, int $divisor): int
    {
        return $value - self::floorDiv($value, $divisor) * $divisor;
    }
}
