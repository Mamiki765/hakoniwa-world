<?php

namespace App\Domain\Command;

use App\Domain\Map\GridCoordinate;

final class CapitalCorePolicy
{
    /**
     * @param  list<array{nation_id: int, x: int, y: int}>  $capitals
     */
    public function protectsCurrentOwnerTerritory(
        GridCoordinate $target,
        int $currentOwnerNationId,
        array $capitals,
        int $radius,
    ): bool {
        foreach ($capitals as $capital) {
            if ($capital['nation_id'] === $currentOwnerNationId
                && $target->distanceTo(new GridCoordinate($capital['x'], $capital['y'])) <= $radius) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{nation_id: int, x: int, y: int}>  $capitals
     */
    public function protectsTransfer(
        GridCoordinate $target,
        int $newOwnerNationId,
        array $capitals,
        int $radius,
    ): bool {
        foreach ($capitals as $capital) {
            if ($capital['nation_id'] === $newOwnerNationId) {
                continue;
            }
            if ($target->distanceTo(new GridCoordinate($capital['x'], $capital['y'])) <= $radius) {
                return true;
            }
        }

        return false;
    }
}
