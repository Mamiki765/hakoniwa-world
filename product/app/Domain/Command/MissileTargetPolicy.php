<?php

namespace App\Domain\Command;

use DomainException;

final class MissileTargetPolicy
{
    public const ACTIVE_NATION = 'active';

    public const ANY_EXISTING_COORDINATE = 'any_existing_coordinate';

    /** @param array<string, mixed> $settings */
    public static function explicitTargetState(array $settings): string
    {
        $state = $settings['military']['dormant_impact']['explicit_target_state'] ?? null;
        if (! in_array($state, [self::ACTIVE_NATION, self::ANY_EXISTING_COORDINATE], true)) {
            throw new DomainException('The active ruleset has an invalid explicit missile target policy.');
        }

        return $state;
    }
}
