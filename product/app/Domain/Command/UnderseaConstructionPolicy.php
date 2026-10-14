<?php

namespace App\Domain\Command;

use App\Domain\Map\GridCoordinate;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\Ship;

final class UnderseaConstructionPolicy
{
    public function __construct(
        private readonly NationLandAreaCalculator $landArea,
        private readonly SurfaceShipCatalog $ships,
    ) {}

    public function appliesTo(CommandDefinition $command): bool
    {
        return ($command->metadata['construction_context'] ?? null) === 'undersea'
            && $command->target_type === 'cell' && $command->result_facility_key !== null;
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
        $settings = $command->rulesetVersion->settings;
        $id = $parameters['construction_ship_id'];
        if (! is_int($id) || $id < 1 || $target->terrain->key !== 'sea'
            || ! isset($command->metadata['parameters']['construction_ship_id'])
            || ! $this->appliesTo($command)) {
            throw new PlayerFacingCommandException('この建設には海底工作船を指定できません。');
        }
        $query = Ship::activeForNation($nation->world_id, $nation->id)
            ->whereKey($id)->where('map_cell_id', $target->id)->where('current_hp', '>', 0);
        if ($lock) {
            $query->lockForUpdate();
        }
        $ship = $query->first();
        if (! $ship instanceof Ship) {
            throw new PlayerFacingCommandException('指定した自国の海底工作船が建設対象の真上に存在しません。');
        }
        $definition = $this->ships->definition($settings, $ship->ship_type_key);
        if (! $definition->underseaConstruction) {
            throw new PlayerFacingCommandException('指定した船は海底工作船ではありません。');
        }

        return $definition->constructionCostMultiplier;
    }

    public function hasNearbyOwnedLand(Nation $nation, MapCell $target): bool
    {
        $coordinates = (new GridCoordinate($target->x, $target->y))->radius(3);

        return MapCell::query()
            ->where('map_space_id', $target->map_space_id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->with('terrain')
            ->get()
            ->contains(fn (MapCell $cell): bool => $this->landArea->isLand($cell));
    }
}
