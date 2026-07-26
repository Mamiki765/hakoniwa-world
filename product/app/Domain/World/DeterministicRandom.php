<?php

namespace App\Domain\World;

use InvalidArgumentException;

final class DeterministicRandom
{
    private int $counter = 0;

    public function __construct(private readonly string $seed) {}

    public function nextInt(int $upperExclusive): int
    {
        if ($upperExclusive <= 0) {
            throw new InvalidArgumentException('Upper bound must be positive.');
        }

        $hash = hash('sha256', $this->seed.':'.$this->counter++);
        $value = (int) hexdec(substr($hash, 0, 8));

        return $value % $upperExclusive;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return list<T>
     */
    public function shuffled(array $values): array
    {
        for ($index = count($values) - 1; $index > 0; $index--) {
            $swap = $this->nextInt($index + 1);
            [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
        }

        return $values;
    }
}
