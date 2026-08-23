<?php

namespace App\Domain\Nation;

use App\Domain\Map\GridCoordinate;
use App\Domain\Turn\TurnContext;
use DomainException;

final class NationProtectionPolicy
{
    public function protectedNationId(TurnContext $context, int $x, int $y): ?int
    {
        $recoveryNationId = $context->state->recoveryTerritoryNationId($x, $y);
        if ($recoveryNationId !== null) {
            return $recoveryNationId;
        }
        $radius = $context->ruleset->settings['nation_lifecycle']['dormant_protection_radius'] ?? null;
        if (! is_int($radius) || $radius < 0) {
            throw new DomainException('The current Ruleset has an invalid dormant protection radius.');
        }
        $target = new GridCoordinate($x, $y);
        foreach ($context->state->nationLifecycleSnapshots() as $nationId => $snapshot) {
            if ($snapshot['state'] !== 'dormant') {
                continue;
            }
            $capital = new GridCoordinate($snapshot['capital_x'], $snapshot['capital_y']);
            if ($capital->distanceTo($target) <= $radius) {
                return (int) $nationId;
            }
        }

        return null;
    }

    public function protects(TurnContext $context, int $x, int $y): bool
    {
        return $this->protectedNationId($context, $x, $y) !== null;
    }
}
