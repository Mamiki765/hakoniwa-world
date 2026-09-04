<?php

namespace App\Services;

use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Facility\MissileBaseRules;
use App\Domain\Map\SeaAreaNameResolver;
use App\Domain\Monster\MonsterHardening;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\Ship;
use App\Models\TerrainDefinition;

final class MapCellPresenter
{
    /** @var array<string, TerrainDefinition> */
    private array $terrains = [];

    /** @var array<string, FacilityDefinition> */
    private array $facilities = [];

    public function __construct(
        private readonly AssetManifestResolver $assets,
        private readonly FacilityCapacityService $capacities,
        private readonly MissileBaseRules $missiles,
        private readonly MonsterHardening $hardening,
        private readonly SeaAreaNameResolver $seaAreas,
        private readonly SurfaceShipCatalog $ships,
    ) {}

    /** @return array<string, mixed> */
    public function present(MapCell $cell, ?int $viewerNationId, int $currentTurn, ?string $theme = null): array
    {
        $isOwner = $viewerNationId !== null && $viewerNationId === $cell->owner_nation_id;
        $isDisguised = self::isDisguised($cell, $viewerNationId);
        $visibleState = self::visibleState($cell, $viewerNationId);
        $terrain = $visibleState['terrain_key'] === $cell->terrain->key
            ? $cell->terrain
            : $this->terrain($visibleState['terrain_key']);
        $neutralizeOwnership = $isDisguised
            && $cell->facility->disguise_ownership_policy === 'neutral';
        $ownerNation = $visibleState['owner_nation_id'] === null ? null : $cell->ownerNation;
        $facility = $visibleState['facility_key'] === null
            ? null
            : ($visibleState['facility_key'] === $cell->facility?->key
                ? $cell->facility
                : $this->facility($visibleState['facility_key']));
        $ship = $this->ship($cell, $viewerNationId);
        $displayDefinition = $facility ?? $terrain;
        $displayAssetKey = $ship['asset_key'] ?? ($facility?->key === 'monument' && $cell->monumentDefinition !== null
            ? $cell->monumentDefinition->asset_key
            : $displayDefinition->asset_key);
        $displayName = $ship['name'] ?? ($facility?->key === 'monument' && $cell->monumentDefinition !== null
            ? $cell->monumentDefinition->name
            : $displayDefinition->name);
        $layers = $this->assets->resolveLayers($displayAssetKey, $displayName, theme: $theme);
        $seaAreaName = $this->seaAreas->forCoordinate($cell->x, $cell->y);
        $details = $this->details($cell, $isOwner, $isDisguised, $seaAreaName);
        $monster = $this->monster($cell, $currentTurn, $neutralizeOwnership);

        return [
            'x' => $cell->x,
            'y' => $cell->y,
            'terrain' => $terrain->key,
            'terrain_name' => $terrain->name,
            'facility' => $facility?->key,
            'facility_name' => $facility?->key === 'monument' ? $displayName : $facility?->name,
            'display_name' => $displayName,
            'sea_area_name' => $seaAreaName,
            'owner_nation_id' => $visibleState['owner_nation_id'],
            'owner_nation_number' => $ownerNation?->nation_number,
            'owner_name' => $ownerNation?->name,
            'details' => $details,
            'ship' => $ship,
            'monster' => $monster,
            'asset' => $layers['completed'],
            'overlays' => $layers['overlays'],
            'aria_label' => $this->ariaLabel($cell, $displayName, $ownerNation, $details, $ship, $monster),
            // Secret-only state changes must not alter a non-owner representation version.
            'version' => $isOwner ? $cell->version : 1,
            'updated_at' => $isOwner ? $cell->updated_at?->toIso8601String() : null,
        ];
    }

    /** @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null} */
    public static function visibleState(MapCell $cell, ?int $viewerNationId): array
    {
        $isOwner = $viewerNationId !== null && $viewerNationId === $cell->owner_nation_id;
        $isDisguised = self::isDisguised($cell, $viewerNationId);
        $impersonatedFacilityKey = ! $isOwner && is_string($cell->facility?->metadata['display_as_facility_key'] ?? null)
            ? $cell->facility->metadata['display_as_facility_key']
            : null;

        return [
            'terrain_key' => $isDisguised
                ? ($cell->facility->disguise_terrain_key ?? 'forest')
                : $cell->terrain->key,
            'facility_key' => $isDisguised
                ? null
                : ($impersonatedFacilityKey ?? $cell->facility?->key),
            'owner_nation_id' => $isDisguised && $cell->facility->disguise_ownership_policy === 'neutral'
                ? null
                : $cell->owner_nation_id,
        ];
    }

    /** @return array<string, mixed>|null */
    private function monster(MapCell $cell, int $currentTurn, bool $hideOwnership): ?array
    {
        $instance = $cell->monsterOccupancy?->monster;
        if ($instance === null || $instance->state !== 'alive') {
            return null;
        }

        $definition = $instance->definition;
        $hardened = $this->hardening->isHardened($definition, $currentTurn);
        $assetKey = $hardened && $definition->hardened_asset_key !== null
            ? $definition->hardened_asset_key
            : $definition->asset_key;
        $asset = $this->assets->resolve($assetKey, $definition->name);
        $hostNation = $hideOwnership ? null : $cell->ownerNation;

        return [
            'id' => $instance->id,
            'key' => $definition->key,
            'name' => $definition->name,
            'asset_key' => $assetKey,
            'asset_url' => $asset['url'],
            'asset' => $asset,
            'current_hp' => $instance->current_hp,
            'spawned_max_hp' => $instance->spawned_max_hp,
            'hp_range' => [
                'min' => $definition->base_hp,
                'max' => $definition->base_hp + $definition->hp_variation,
            ],
            'skill_description' => $definition->skill_description,
            'hardened_now' => $hardened,
            'public_state' => 'alive',
            'coordinate' => ['x' => $cell->x, 'y' => $cell->y],
            'host_nation' => $hostNation === null ? null : [
                'nation_number' => $hostNation->nation_number,
                'name' => $hostNation->name,
            ],
            'host_label' => $hostNation === null ? '無所属' : 'N'.$hostNation->nation_number,
        ];
    }

    /** @return array<string, mixed>|null */
    private function ship(MapCell $cell, ?int $viewerNationId): ?array
    {
        $ship = $cell->ship;
        if (! $ship instanceof Ship) {
            return null;
        }
        $ruleset = $ship->rulesetVersion;
        $definition = collect($this->ships->definitions($ruleset->settings))
            ->first(static fn ($candidate): bool => $candidate->key === $ship->ship_type_key);
        if ($definition === null) {
            return null;
        }
        $owner = $ship->nation;
        $isOwner = $viewerNationId !== null && $viewerNationId === $ship->nation_id;

        return [
            'id' => $ship->id,
            'key' => $definition->key,
            'name' => $definition->name,
            'asset_key' => $definition->assetKey,
            'current_hp' => $ship->current_hp,
            'max_hp' => $ship->max_hp,
            'public_state' => Ship::STATE_ACTIVE,
            'owner_nation' => [
                'nation_number' => $owner->nation_number,
                'name' => $owner->name,
            ],
            'is_owner' => $isOwner,
            'heading' => $isOwner ? $ship->heading : null,
            'version' => $isOwner ? $ship->version : null,
        ];
    }

    /** @return array<int, array{key: string, label: string, value: int|string, unit: string|null, formatted: string, visibility: string}> */
    private function details(MapCell $cell, bool $isOwner, bool $isDisguised, string $seaAreaName): array
    {
        if ($isDisguised) {
            return [$this->detail('sea_area', '海域', $seaAreaName, null, $seaAreaName, 'public')];
        }

        $details = [$this->detail('sea_area', '海域', $seaAreaName, null, $seaAreaName, 'public')];
        if ($cell->population > 0) {
            $details[] = $this->detail('population', '人口', $cell->population, '人', number_format($cell->population).'人', 'public');
        }

        if ($cell->terrain->quantity_key !== null && $cell->terrain_quantity !== null && $isOwner) {
            $details[] = $this->detail(
                'terrain_quantity',
                (string) $cell->terrain->quantity_label,
                $cell->terrain_quantity,
                $cell->terrain->quantity_unit,
                number_format($cell->terrain_quantity).$cell->terrain->quantity_unit,
                'owner',
            );
        }

        $facility = $cell->facility;
        if ($facility?->scale_unit_people !== null && $cell->facility_scale !== null) {
            $capacity = $this->capacities->capacityPeople($facility, $cell->facility_scale);
            $details[] = $this->detail('facility_capacity', '規模', $capacity, '人', number_format($capacity).'人規模', 'public');
        }

        if ($facility !== null && $cell->facility_experience !== null && $isOwner) {
            $experience = (int) $cell->facility_experience;
            $level = $this->missiles->level($facility, $experience);
            $launchCapacity = $this->missiles->launchCapacity($facility, $experience);
            $details[] = $this->detail('facility_experience', '経験値', $experience, null, number_format($experience), 'owner');
            $details[] = $this->detail('facility_level', 'LV', $level, null, (string) $level, 'owner');
            $details[] = $this->detail('launch_capacity', '発射可能数', $launchCapacity, '発', number_format($launchCapacity).'発', 'owner');
        }

        return $details;
    }

    /** @return array{key: string, label: string, value: int|string, unit: string|null, formatted: string, visibility: string} */
    private function detail(string $key, string $label, int|string $value, ?string $unit, string $formatted, string $visibility): array
    {
        return compact('key', 'label', 'value', 'unit', 'formatted', 'visibility');
    }

    /**
     * @param  array<int, array{label: string, formatted: string}>  $details
     * @param  array<string, mixed>|null  $ship
     * @param  array<string, mixed>|null  $monster
     */
    private function ariaLabel(
        MapCell $cell,
        string $displayName,
        ?Nation $ownerNation,
        array $details,
        ?array $ship,
        ?array $monster,
    ): string {
        $suffix = array_map(static fn (array $detail): string => $detail['label'].' '.$detail['formatted'], $details);
        if ($ship !== null) {
            $suffix[] = sprintf(
                '船 %s HP %d/%d 所有 %s N%d',
                $ship['name'],
                $ship['current_hp'],
                $ship['max_hp'],
                $ship['owner_nation']['name'],
                $ship['owner_nation']['nation_number'],
            );
        }
        if ($monster !== null) {
            $suffix[] = sprintf(
                '怪獣 %s HP %d %s%s',
                $monster['name'],
                $monster['current_hp'],
                $monster['host_label'],
                $monster['hardened_now'] ? ' 硬化中' : '',
            );
        }

        return trim(implode(' ', [
            "x {$cell->x} y {$cell->y}",
            $displayName,
            '所有 '.($ownerNation === null
                ? '中立'
                : $ownerNation->name.' N'.$ownerNation->nation_number),
            ...$suffix,
        ]));
    }

    private function terrain(string $key): TerrainDefinition
    {
        return $this->terrains[$key] ??= TerrainDefinition::query()->where('key', $key)->firstOrFail();
    }

    private function facility(string $key): FacilityDefinition
    {
        return $this->facilities[$key] ??= FacilityDefinition::query()->where('key', $key)->firstOrFail();
    }

    private static function isDisguised(MapCell $cell, ?int $viewerNationId): bool
    {
        return $cell->facility?->visibility_policy === FacilityVisibilityPolicy::Disguised->value
            && ($viewerNationId === null || $viewerNationId !== $cell->owner_nation_id);
    }
}
