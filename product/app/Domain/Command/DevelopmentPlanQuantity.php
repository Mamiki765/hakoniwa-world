<?php

namespace App\Domain\Command;

final class DevelopmentPlanQuantity
{
    public const MINIMUM = 1;

    public const MAXIMUM = 99;

    public const DEFAULT = 1;

    /** @var list<int> */
    public const QUICK_PRESETS = [1, 5, 10, 25, 50, 99];

    public static function normalize(mixed $value = null, bool $provided = false): int
    {
        if (! $provided) {
            return self::DEFAULT;
        }
        if (! is_int($value)) {
            throw new PlayerFacingCommandException('quantityは整数で指定してください。');
        }
        if ($value < self::MINIMUM || $value > self::MAXIMUM) {
            throw new PlayerFacingCommandException('quantityは1から99の範囲で指定してください。');
        }

        return $value;
    }

    /** @return array{type: string, minimum: int, maximum: int, default: int, quick_presets: list<int>} */
    public static function contract(): array
    {
        return [
            'type' => 'integer',
            'minimum' => self::MINIMUM,
            'maximum' => self::MAXIMUM,
            'default' => self::DEFAULT,
            'quick_presets' => self::QUICK_PRESETS,
        ];
    }

    public static function matchesContract(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $expected = self::contract();
        ksort($expected);
        ksort($value);

        return $value === $expected;
    }
}
