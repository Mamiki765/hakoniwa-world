<?php

namespace App\Domain\Facility;

enum FacilityVisibilityPolicy: string
{
    case Public = 'public';
    case Disguised = 'disguised';

    public static function isSupported(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $policy): string => $policy->value,
            self::cases(),
        );
    }
}
