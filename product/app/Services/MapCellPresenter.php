<?php

namespace App\Services;

use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Facility\MissileBaseRules;
use App\Models\MapCell;
use App\Models\TerrainDefinition;

final class MapCellPresenter
{
    private ?TerrainDefinition $forest = null;

    public function __construct(
        private readonly AssetManifestResolver $assets,
        private readonly FacilityCapacityService $capacities,
        private readonly MissileBaseRules $missiles,
    ) {}

    /** @return array<string, mixed> */
    public function present(MapCell $cell, ?int $viewerNationId): array
    {
        $isOwner = $viewerNationId !== null && $viewerNationId === $cell->owner_nation_id;
        $isDisguised = $cell->facility?->visibility_policy === FacilityVisibilityPolicy::Disguised->value
            && ! $isOwner;
        $terrain = $isDisguised ? $this->forest() : $cell->terrain;
        $facility = $isDisguised ? null : $cell->facility;
        $displayDefinition = $facility ?? $terrain;
        $layers = $this->assets->resolveLayers($displayDefinition->asset_key, $displayDefinition->name);
        $details = $this->details($cell, $isOwner, $isDisguised);

        return [
            'x' => $cell->x,
            'y' => $cell->y,
            'terrain' => $terrain->key,
            'terrain_name' => $terrain->name,
            'facility' => $facility?->key,
            'facility_name' => $facility?->name,
            'display_name' => $displayDefinition->name,
            'owner_nation_id' => $cell->owner_nation_id,
            'owner_name' => $cell->ownerNation?->name,
            'details' => $details,
            'asset' => $layers['completed'],
            'overlays' => $layers['overlays'],
            'aria_label' => $this->ariaLabel($cell, $displayDefinition->name, $details),
            // Secret-only state changes must not alter a non-owner representation version.
            'version' => $isOwner ? $cell->version : 1,
            'updated_at' => $isOwner ? $cell->updated_at?->toIso8601String() : null,
        ];
    }

    /** @return array<int, array{key: string, label: string, value: int|string, unit: string|null, formatted: string, visibility: string}> */
    private function details(MapCell $cell, bool $isOwner, bool $isDisguised): array
    {
        if ($isDisguised) {
            return [];
        }

        $details = [];
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

        if ($facility?->key === 'missile_base' && $isOwner) {
            $experience = (int) ($cell->facility_experience ?? 0);
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

    /** @param array<int, array{label: string, formatted: string}> $details */
    private function ariaLabel(MapCell $cell, string $displayName, array $details): string
    {
        $suffix = array_map(static fn (array $detail): string => $detail['label'].' '.$detail['formatted'], $details);

        return trim(implode(' ', [
            "x {$cell->x} y {$cell->y}",
            $displayName,
            '所有 '.($cell->owner_nation_id === null ? '中立' : $cell->ownerNation->name),
            ...$suffix,
        ]));
    }

    private function forest(): TerrainDefinition
    {
        return $this->forest ??= TerrainDefinition::query()->where('key', 'forest')->firstOrFail();
    }
}
