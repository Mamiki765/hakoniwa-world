<?php

namespace App\Domain\Turn;

use InvalidArgumentException;

final class TurnRandomStreamFactory
{
    public const DERIVATION_VERSION = 'hakoniwa-turn-random-stream-v1';

    public const DEVELOPMENT_NATION_ORDER = 'development_commands:nation_order';

    public const SURFACE_CELL_ORDER = 'process_cells:surface_cell_order';

    /** @var array<string, DeterministicRandomStream> */
    private array $streams = [];

    private readonly string $masterSeedBytes;

    public function __construct(public readonly string $masterSeed)
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $masterSeed) !== 1) {
            throw new InvalidArgumentException('Turn master seed must be 64 lowercase hexadecimal characters.');
        }

        $decoded = hex2bin($masterSeed);
        if (! is_string($decoded) || strlen($decoded) !== 32) {
            throw new InvalidArgumentException('Turn master seed must decode to exactly 256 bits.');
        }

        $this->masterSeedBytes = $decoded;
    }

    public function stream(string $label): DeterministicRandomStream
    {
        if ($label === '') {
            throw new InvalidArgumentException('Turn random stream label must not be empty.');
        }
        if (preg_match('//u', $label) !== 1) {
            throw new InvalidArgumentException('Turn random stream label must be valid UTF-8.');
        }

        return $this->streams[$label] ??= new DeterministicRandomStream(
            hash_hmac(
                'sha256',
                self::DERIVATION_VERSION."\0".$label,
                $this->masterSeedBytes,
                true,
            ),
        );
    }
}
