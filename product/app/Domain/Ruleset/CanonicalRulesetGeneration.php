<?php

namespace App\Domain\Ruleset;

use DomainException;

final class CanonicalRulesetGeneration
{
    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): ?int
    {
        $key = $settings['key'] ?? null;
        if (! is_string($key) || preg_match('/\Ahakoniwa-2s-plus-v([1-9][0-9]*)\z/D', $key, $matches) !== 1) {
            return null;
        }

        $generation = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($generation)) {
            throw new DomainException('The canonical Ruleset generation must be a positive integer.');
        }
        if (array_key_exists('version', $settings) && $settings['version'] !== $generation) {
            throw new DomainException('The canonical Ruleset key and version must agree.');
        }

        return $generation;
    }
}
