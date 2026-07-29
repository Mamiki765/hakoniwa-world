<?php

namespace App\Domain\Economy;

enum SalePolicy: string
{
    case SellAll = 'sell_all';
    case Stockpile = 'stockpile';
    case KeepAmount = 'keep_amount';

    public static function isSupported(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    public static function isSupportedRulesetDefault(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::rulesetDefaultValues(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $policy): string => $policy->value,
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function rulesetDefaultValues(): array
    {
        return [
            self::SellAll->value,
            self::Stockpile->value,
        ];
    }
}
