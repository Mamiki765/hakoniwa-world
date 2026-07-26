<?php

namespace App\Domain\Map;

use InvalidArgumentException;

final readonly class GridCoordinate
{
    public const EAST = 0;

    public const NORTH_EAST = 1;

    public const NORTH_WEST = 2;

    public const WEST = 3;

    public const SOUTH_WEST = 4;

    public const SOUTH_EAST = 5;

    /** @var array<int, string> */
    public const DIRECTION_NAMES = [
        self::EAST => 'east',
        self::NORTH_EAST => 'north-east',
        self::NORTH_WEST => 'north-west',
        self::WEST => 'west',
        self::SOUTH_WEST => 'south-west',
        self::SOUTH_EAST => 'south-east',
    ];

    public function __construct(public int $x, public int $y) {}

    public function neighbor(int $direction): self
    {
        if (! isset(self::DIRECTION_NAMES[$direction])) {
            throw new InvalidArgumentException('Grid direction must be between 0 and 5.');
        }

        $evenRow = self::floorMod($this->y, 2) === 0;
        [$deltaX, $deltaY] = match ($direction) {
            self::EAST => [1, 0],
            self::NORTH_EAST => [$evenRow ? 1 : 0, -1],
            self::NORTH_WEST => [$evenRow ? 0 : -1, -1],
            self::WEST => [-1, 0],
            self::SOUTH_WEST => [$evenRow ? 0 : -1, 1],
            self::SOUTH_EAST => [$evenRow ? 1 : 0, 1],
        };

        return new self($this->x + $deltaX, $this->y + $deltaY);
    }

    /** @return list<self> */
    public function neighborsWithin(int $minX, int $maxX, int $minY, int $maxY): array
    {
        $neighbors = [];

        foreach (array_keys(self::DIRECTION_NAMES) as $direction) {
            $neighbor = $this->neighbor($direction);
            if ($neighbor->x >= $minX && $neighbor->x <= $maxX && $neighbor->y >= $minY && $neighbor->y <= $maxY) {
                $neighbors[] = $neighbor;
            }
        }

        return $neighbors;
    }

    public function distanceTo(self $other): int
    {
        [$firstX, $firstY, $firstZ] = $this->toCube();
        [$secondX, $secondY, $secondZ] = $other->toCube();

        return max(
            abs($firstX - $secondX),
            abs($firstY - $secondY),
            abs($firstZ - $secondZ),
        );
    }

    /** @return list<self> */
    public function ring(int $radius): array
    {
        if ($radius < 0) {
            throw new InvalidArgumentException('Radius cannot be negative.');
        }

        if ($radius === 0) {
            return [$this];
        }

        return array_values(array_filter(
            $this->coordinatesInSquare($radius),
            fn (self $coordinate): bool => $this->distanceTo($coordinate) === $radius,
        ));
    }

    /** @return list<self> */
    public function radius(int $radius): array
    {
        if ($radius < 0) {
            throw new InvalidArgumentException('Radius cannot be negative.');
        }

        return array_values(array_filter(
            $this->coordinatesInSquare($radius),
            fn (self $coordinate): bool => $this->distanceTo($coordinate) <= $radius,
        ));
    }

    /**
     * Cube coordinates are a private distance-calculation detail. They are never
     * persisted or exposed by an API.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function toCube(): array
    {
        $first = $this->x - self::floorDiv($this->y + 1, 2);
        $second = $this->y;

        return [$first, $second, -$first - $second];
    }

    /** @return list<self> */
    private function coordinatesInSquare(int $radius): array
    {
        $coordinates = [];

        for ($y = $this->y - $radius; $y <= $this->y + $radius; $y++) {
            for ($x = $this->x - $radius; $x <= $this->x + $radius; $x++) {
                $coordinates[] = new self($x, $y);
            }
        }

        return $coordinates;
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
