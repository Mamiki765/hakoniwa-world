<?php

namespace App\Domain\Command;

use App\Domain\Map\OwnedLandProximity;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\Ship;

final class UnderseaConstructionPolicy
{
    public function __construct(
        private readonly OwnedLandProximity $ownedLand,
        private readonly SurfaceShipCatalog $ships,
    ) {}

    public function appliesTo(CommandDefinition $command): bool
    {
        return LandDevelopmentDistancePolicy::isUnderseaConstruction($command);
    }

    /** @param array<string, mixed> $parameters */
    public function costMultiplier(
        Nation $nation,
        CommandDefinition $command,
        MapCell $target,
        array $parameters,
        bool $lock = false,
    ): int {
        if (! array_key_exists('construction_ship_id', $parameters)) {
            return 1;
        }
        $ship = $this->constructionShip($nation, $command, $parameters, $lock);
        if ($ship->map_cell_id !== $target->id) {
            throw new PlayerFacingCommandException('海底工作船の現在位置から建設を予約してください。');
        }

        return $this->ships->definition($command->rulesetVersion->settings, $ship->ship_type_key)->constructionCostMultiplier;
    }

    /** @param array<string, mixed> $parameters */
    public function constructionShip(
        Nation $nation,
        CommandDefinition $command,
        array $parameters,
        bool $lock = false,
    ): Ship {
        $id = $parameters['construction_ship_id'] ?? null;
        if (! is_int($id) || $id < 1
            || ! isset($command->metadata['parameters']['construction_ship_id'])
            || ! $this->appliesTo($command)) {
            throw new PlayerFacingCommandException('この建設には海底工作船を指定できません。');
        }
        $query = Ship::activeForNation($nation->world_id, $nation->id)
            ->whereKey($id)->whereNotNull('map_cell_id')->where('current_hp', '>', 0);
        if ($lock) {
            $query->lockForUpdate();
        }
        $ship = $query->first();
        if (! $ship instanceof Ship) {
            throw new PlayerFacingCommandException('指定した自国の海底工作船は建設に使えません。');
        }
        $definition = $this->ships->definition($command->rulesetVersion->settings, $ship->ship_type_key);
        if (! $definition->underseaConstruction) {
            throw new PlayerFacingCommandException('指定した船は海底工作船ではありません。');
        }

        return $ship;
    }

    public function hasNearbyOwnedLand(Nation $nation, MapCell $target): bool
    {
        return $this->ownedLand->within($nation, $target, LandDevelopmentDistancePolicy::WATER_RADIUS);
    }
}
