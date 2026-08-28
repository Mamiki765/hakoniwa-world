<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;
use LogicException;

final class UndergroundRandom
{
    private const DERIVATION_VERSION = 'secretary-underground-random-v1';

    private const UINT32_CARDINALITY = 4_294_967_296;

    /**
     * @var array<string, array{key: string, counter: string, buffer: string}>
     */
    private array $streams = [];

    private readonly string $masterKey;

    public function __construct(public readonly int $seed)
    {
        if ($seed < 0 || $seed > 2_147_483_647) {
            throw new InvalidArgumentException('Underground simulation seed must fit a non-negative signed 32-bit integer.');
        }
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('Underground deterministic combat requires 64-bit PHP integers.');
        }

        $this->masterKey = hash('sha256', self::DERIVATION_VERSION."\0".(string) $seed, true);
    }

    public function integer(string $label, int $minimum, int $maximum): int
    {
        if ($label === '' || preg_match('//u', $label) !== 1) {
            throw new InvalidArgumentException('Underground random stream label must be non-empty UTF-8.');
        }
        if ($minimum > $maximum || $minimum < -2_147_483_648 || $maximum > 2_147_483_647) {
            throw new InvalidArgumentException('Underground random integer range is invalid.');
        }

        $width = $maximum - $minimum + 1;
        $acceptBelow = intdiv(self::UINT32_CARDINALITY, $width) * $width;

        do {
            $sample = $this->nextUnsignedInt32($label);
        } while ($sample >= $acceptBelow);

        return $minimum + ($sample % $width);
    }

    private function nextUnsignedInt32(string $label): int
    {
        if (! isset($this->streams[$label])) {
            $this->streams[$label] = [
                'key' => hash_hmac('sha256', self::DERIVATION_VERSION."\0".$label, $this->masterKey, true),
                'counter' => "\0\0\0\0\0\0\0\0",
                'buffer' => '',
            ];
        }

        $stream = &$this->streams[$label];
        while (strlen($stream['buffer']) < 4) {
            $stream['buffer'] .= hash_hmac('sha256', $stream['counter'], $stream['key'], true);
            $this->incrementCounter($stream['counter']);
        }

        $word = substr($stream['buffer'], 0, 4);
        $stream['buffer'] = substr($stream['buffer'], 4);
        $unpacked = unpack('Nvalue', $word);
        if (! is_array($unpacked) || ! is_int($unpacked['value'] ?? null)) {
            throw new LogicException('Unable to decode an Underground deterministic random word.');
        }

        return $unpacked['value'];
    }

    private function incrementCounter(string &$counter): void
    {
        for ($index = strlen($counter) - 1; $index >= 0; $index--) {
            $byte = ord($counter[$index]);
            if ($byte < 255) {
                $counter[$index] = chr($byte + 1);

                return;
            }

            $counter[$index] = "\0";
        }

        throw new LogicException('Underground random stream counter exhausted.');
    }
}
