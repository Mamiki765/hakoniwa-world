<?php

namespace App\Domain\Disaster;

use App\Models\Nation;
use App\Models\RulesetVersion;
use DomainException;

final class LandSubsidenceThresholdResolver
{
    public function resolve(RulesetVersion $ruleset, Nation $nation): int
    {
        $base = $ruleset->settings['turn_processing']['disasters']['land_subsidence']['base_safe_land_cells'] ?? null;
        if (! is_int($base) || $base < 0) {
            throw new DomainException('The active ruleset has an invalid land-subsidence safe-land threshold.');
        }

        // The Nation parameter is the intentional extension boundary for future
        // Nation/item effects. PR18 returns only the immutable ruleset base value.
        return $base;
    }
}
