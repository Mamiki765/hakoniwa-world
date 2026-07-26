<?php

namespace App\Domain\Hex;

use InvalidArgumentException;

final readonly class HexCoordinate
{
    /** @var list<array{0: int, 1: int}> */
    public const DIRECTIONS = [
        [1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1],
    ];

    public function __construct(public int $q, public int $r) {}

    public function neighbor(int $direction): self
    {
        if (! isset(self::DIRECTIONS[$direction])) {
            throw new InvalidArgumentException('Hex direction must be between 0 and 5.');
        }

        [$dq, $dr] = self::DIRECTIONS[$direction];

        return new self($this->q + $dq, $this->r + $dr);
    }

    public function distanceTo(self $other): int
    {
        $dq = $this->q - $other->q;
        $dr = $this->r - $other->r;
        $ds = (-$this->q - $this->r) - (-$other->q - $other->r);

        return intdiv(abs($dq) + abs($dr) + abs($ds), 2);
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

        $cursor = new self($this->q - $radius, $this->r + $radius);
        $result = [];

        for ($direction = 0; $direction < 6; $direction++) {
            for ($step = 0; $step < $radius; $step++) {
                $result[] = $cursor;
                $cursor = $cursor->neighbor($direction);
            }
        }

        return $result;
    }

    /** @return list<self> */
    public function radius(int $radius): array
    {
        if ($radius < 0) {
            throw new InvalidArgumentException('Radius cannot be negative.');
        }

        $result = [$this];

        for ($current = 1; $current <= $radius; $current++) {
            array_push($result, ...$this->ring($current));
        }

        return $result;
    }

    /** @return array{column: int, row: int} */
    public function toOddQ(): array
    {
        $parity = (($this->q % 2) + 2) % 2;

        return [
            'column' => $this->q,
            'row' => $this->r + intdiv($this->q - $parity, 2),
        ];
    }

    public static function fromOddQ(int $column, int $row): self
    {
        $parity = (($column % 2) + 2) % 2;

        return new self($column, $row - intdiv($column - $parity, 2));
    }
}
