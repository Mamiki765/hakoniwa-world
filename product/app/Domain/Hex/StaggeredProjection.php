<?php

namespace App\Domain\Hex;

final class StaggeredProjection
{
    public const TILE_SIZE = 32;

    public const HALF_TILE = 16;

    public const VERTICAL_STEP = 32;

    /** @return array{column: int, row: int} */
    public function fromAxial(HexCoordinate $coordinate): array
    {
        return [
            'column' => $coordinate->q + $this->floorDiv($coordinate->r + 1, 2),
            'row' => $coordinate->r,
        ];
    }

    /** @return array{x: int, y: int} */
    public function toPixel(HexCoordinate $coordinate): array
    {
        $offset = $this->fromAxial($coordinate);

        return [
            'x' => $offset['column'] * self::TILE_SIZE + ($this->floorMod($offset['row'], 2) === 0 ? self::HALF_TILE : 0),
            'y' => $offset['row'] * self::VERTICAL_STEP,
        ];
    }

    private function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }

    private function floorMod(int $value, int $divisor): int
    {
        return $value - $this->floorDiv($value, $divisor) * $divisor;
    }
}
