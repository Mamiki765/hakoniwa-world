<?php

namespace App\Domain\World;

use DomainException;

enum WorldGenerationProfile: string
{
    case Production = 'default';
    case Debug32x32 = 'debug-32x32';

    /** @param array<string, mixed> $rules */
    public function bounds(array $rules): WorldBounds
    {
        return match ($this) {
            self::Production => WorldBounds::fromRuleset($rules),
            self::Debug32x32 => WorldBounds::debug32x32((int) $rules['chunk_size']),
        };
    }

    /** @param array<string, mixed> $rules */
    public static function matchingBounds(WorldBounds $bounds, array $rules): ?self
    {
        foreach (self::cases() as $profile) {
            if ($profile->bounds($rules)->equals($bounds)) {
                return $profile;
            }
        }

        return null;
    }

    public function assertAvailable(string $environment): void
    {
        if ($this === self::Debug32x32 && ! in_array($environment, ['local', 'testing'], true)) {
            throw new DomainException('The debug-32x32 World profile is restricted to local and testing environments.');
        }
    }

    public function generatorVersion(string $configuredVersion): string
    {
        return $this === self::Production ? $configuredVersion : $configuredVersion.'-'.$this->value;
    }

    public function seed(string $configuredSeed): string
    {
        return $this === self::Production ? $configuredSeed : $configuredSeed.':'.$this->value;
    }
}
