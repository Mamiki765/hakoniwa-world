<?php

namespace App\Domain\Command;

use DomainException;

final class CommandQueueLimit
{
    public const MINIMUM = 1;

    public const MAXIMUM = 168;

    /** @param array<string, mixed> $settings */
    public static function fromRulesetSettings(array $settings): int
    {
        $limit = $settings['command_queue_limit'] ?? null;
        if (! is_int($limit) || $limit < self::MINIMUM || $limit > self::MAXIMUM) {
            throw new DomainException('World command queue limit is invalid.');
        }

        return $limit;
    }
}
