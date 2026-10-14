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
        return in_array($commandKey, ['build_seabed_base', 'build_undersea_city', 'build_undersea_fire_station'], true);
    }

    public function hasNearbyOwnedLand(Nation $nation, MapCell $target): bool
    {
        return $this->ownedLand->within($nation, $target, 3);
    }
}
