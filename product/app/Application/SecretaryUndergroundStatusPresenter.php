<?php

namespace App\Application;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundEquipmentLoadoutResolver;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;

final readonly class SecretaryUndergroundStatusPresenter
{
    public function __construct(
        private UndergroundAlphaV1PlayerCatalog $players,
        private UndergroundEquipmentLoadoutResolver $equipment,
    ) {}

    /** @return array<string, mixed>|null */
    public function present(?UndergroundProfile $profile): ?array
    {
        if ($profile === null || $profile->growth_path_key === null) {
            return null;
        }

        $loadout = $this->equipment->combatLoadout($profile);
        $stats = $this->players->combatStats(
            $this->players->currentStats($profile->growth_path_key, $profile->combat_level, $profile->allocatedStp()),
            $loadout,
        );
        $equipped = array_fill_keys(UndergroundEquipmentCatalog::EQUIPPED_SLOTS, null);
        foreach (UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->whereNotNull('equipped_slot')->orderBy('id')->get() as $row) {
            $definition = $this->equipment->definitionForRow($row);
            // This is a public status projection: never serialize the owned row or inventory summary.
            $equipped[$row->equipped_slot] = [
                'label' => $definition['name'],
                'item_level' => $definition['item_level'],
                'quality_percent' => $definition['quality_percent'],
            ];
        }

        return [
            'growth_path_label' => $this->players->growthPath($profile->growth_path_key)['label'],
            'stats' => $stats,
            'max_hp' => $this->players->maxHp($stats, $loadout),
            'equipped' => $equipped,
        ];
    }
}
