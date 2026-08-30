<?php

namespace App\Application\Underground;

use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

final readonly class UndergroundEquipmentLoadoutResolver
{
    public function __construct(private UndergroundEquipmentCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function combatLoadout(UndergroundProfile $profile): array
    {
        return $this->catalog->combatLoadout($this->equippedDefinitions($profile));
    }

    /** @return array{used: int, capacity: int, equipped: array<string, array<string, mixed>|null>} */
    public function summary(UndergroundProfile $profile): array
    {
        $rows = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->whereNotNull('equipped_slot')
            ->orderBy('id')
            ->get();
        $equipped = ['weapon' => null, 'armor' => null, 'accessory' => null];
        foreach ($rows as $row) {
            $equipped[$row->equipped_slot] = $this->projectOwned($row);
        }

        return [
            'used' => UndergroundOwnedEquipment::query()
                ->where('underground_profile_id', $profile->id)
                ->count(),
            'capacity' => $this->catalog->vaultCapacity(),
            'equipped' => $equipped,
        ];
    }

    /** @return array<string, mixed> */
    public function projectOwned(UndergroundOwnedEquipment $row): array
    {
        if ($row->catalog_identity !== $this->catalog->identity()) {
            throw new RuntimeException('Underground owned equipment catalog identity is unsupported.');
        }
        $definition = $this->catalog->definition($row->definition_key);

        return [
            'id' => $row->id,
            ...$definition,
            'sell_price' => $this->catalog->sellPrice($definition),
            'equipped_slot' => $row->equipped_slot,
            'acquired_at' => $row->acquired_at->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function equippedDefinitions(UndergroundProfile $profile): array
    {
        /** @var Collection<int, UndergroundOwnedEquipment> $rows */
        $rows = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->whereNotNull('equipped_slot')
            ->orderBy('id')
            ->get();
        $definitions = [];
        foreach ($rows as $row) {
            if ($row->catalog_identity !== $this->catalog->identity()) {
                throw new RuntimeException('Underground equipped catalog identity is unsupported.');
            }
            $definition = $this->catalog->definition($row->definition_key);
            if ($definition['category'] !== $row->equipped_slot) {
                throw new RuntimeException('Underground equipped slot is incompatible.');
            }
            $definitions[] = $definition;
        }

        return $definitions;
    }
}
