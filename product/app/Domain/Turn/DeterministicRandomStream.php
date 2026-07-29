<?php

namespace App\Domain\Turn;

use InvalidArgumentException;
use LogicException;

final class DeterministicRandomStream
{
    private const UINT32_CARDINALITY = 4_294_967_296;

    private const SIGNED_INT32_MIN = -2_147_483_648;

    private const SIGNED_INT32_MAX = 2_147_483_647;

    private string $counter = "\0\0\0\0\0\0\0\0";

    private string $buffer = '';

    public function __construct(private readonly string $streamKey)
    {
        if (strlen($streamKey) !== 32) {
            throw new InvalidArgumentException('A deterministic stream key must contain exactly 32 bytes.');
        }
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('Deterministic turn random streams require 64-bit PHP integers.');
        }
    }

    public function integer(mixed $minimum, mixed $maximum): int
    {
        if (! is_int($minimum) || ! is_int($maximum)) {
            throw new InvalidArgumentException('Random integer bounds must be integers.');
        }
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Random integer minimum must not exceed maximum.');
        }
        if ($minimum < self::SIGNED_INT32_MIN || $maximum > self::SIGNED_INT32_MAX) {
            throw new InvalidArgumentException('Random integer bounds must fit signed 32-bit integers.');
        }

        $width = $maximum - $minimum + 1;
        $acceptBelow = intdiv(self::UINT32_CARDINALITY, $width) * $width;

        do {
            $sample = $this->nextUnsignedInt32();
        } while ($sample >= $acceptBelow);

        return $minimum + ($sample % $width);
    }

    /**
     * @template T
     *
     * @param  array<array-key, T>  $values
     * @return list<T>
     */
    public function shuffle(array $values): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Deterministic shuffle input must be a list.');
        }

        for ($index = count($values) - 1; $index > 0; $index--) {
            $swapIndex = $this->integer(0, $index);
            [$values[$index], $values[$swapIndex]] = [$values[$swapIndex], $values[$index]];
        }

        return $values;
    }

    private function nextUnsignedInt32(): int
    {
        while (strlen($this->buffer) < 4) {
            $this->buffer .= hash_hmac('sha256', $this->counter, $this->streamKey, true);
            $this->incrementCounter();
        }

        $word = substr($this->buffer, 0, 4);
        $this->buffer = substr($this->buffer, 4);
        $unpacked = unpack('Nvalue', $word);
        if (! is_array($unpacked) || ! is_int($unpacked['value'] ?? null)) {
            throw new LogicException('Unable to decode a deterministic 32-bit random word.');
        }

        return $unpacked['value'];
    }

    private function incrementCounter(): void
    {
        for ($index = strlen($this->counter) - 1; $index >= 0; $index--) {
            $byte = ord($this->counter[$index]);
            if ($byte < 255) {
                $this->counter[$index] = chr($byte + 1);

                return;
            }

            $this->counter[$index] = "\0";
        }

        throw new LogicException('Deterministic random stream counter exhausted.');
    }
}
