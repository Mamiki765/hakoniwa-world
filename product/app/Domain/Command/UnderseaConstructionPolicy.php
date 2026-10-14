<?php

namespace App\Domain\Command;

use App\Domain\Map\OwnedLandProximity;
use App\Models\MapCell;
use App\Models\Nation;

final class UnderseaConstructionPolicy
{
    public function __construct(private readonly OwnedLandProximity $ownedLand) {}

    public function appliesTo(string $commandKey): bool
    {
        return LandDevelopmentDistancePolicy::isUnderseaConstruction($commandKey);
    }

    public function hasNearbyOwnedLand(Nation $nation, MapCell $target): bool
    {
        return $this->ownedLand->within($nation, $target, LandDevelopmentDistancePolicy::WATER_RADIUS);
    }
}
