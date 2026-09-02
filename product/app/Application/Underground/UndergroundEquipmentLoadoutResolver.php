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
        $equipped = array_fill_keys(UndergroundEquipmentCatalog::EQUIPPED_SLOTS, null);
        foreach ($rows as $row) {
            if (! array_key_exists($row->equipped_slot, $equipped)) {
                throw new RuntimeException('Underground equipped slot is unsupported.');
            }
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
    public function definitionForRow(UndergroundOwnedEquipment $row): array
    {
        if (! $this->catalog->supportsIdentity($row->catalog_identity)) {
            throw new RuntimeException('Underground owned equipment catalog identity is unsupported.');
        }
        if ($row->instance_kind === 'fixed') {
            return $this->catalog->definition($row->definition_key, $row->catalog_identity);
        }
        if ($row->instance_kind !== 'generated'
            || $row->generator_identity !== $this->catalog->generatorIdentity()
            || ! is_string($row->instance_identity) || strlen($row->instance_identity) !== 64
            || ! is_array($row->generated_payload)) {
            throw new RuntimeException('Underground generated equipment persistence is invalid.');
        }
        $definition = $row->generated_payload;
        if (($definition['instance_identity'] ?? null) !== $row->instance_identity
            || ($definition['generator_identity'] ?? null) !== $row->generator_identity
            || ($definition['key'] ?? null) !== $row->definition_key) {
            throw new RuntimeException('Underground generated equipment identity is inconsistent.');
        }
        $this->catalog->assertDefinition($definition, true);

        return $definition;
    }

    /** @return array<string, mixed> */
    public function projectOwned(UndergroundOwnedEquipment $row): array
    {
        $definition = $this->definitionForRow($row);
        $projection = $definition;
        unset($projection['base'], $projection['source']);
        $projection['affixes'] = array_map(
            static fn (array $affix): array => [
                'key' => $affix['key'],
                'label' => $affix['label'],
                'kind' => $affix['kind'],
                'target' => $affix['target'],
                'value' => $affix['value'],
                'quality_bps' => $affix['quality_bps'],
            ],
            $definition['affixes'],
        );

        return [
            'id' => $row->id,
            ...$projection,
            'instance_kind' => $row->instance_kind,
            'instance_identity' => $row->instance_identity,
            'generator_identity' => $row->generator_identity,
            'catalog_identity' => $row->catalog_identity,
            'sell_price' => $this->catalog->sellPrice($definition),
            'equipped_slot' => $row->equipped_slot,
            'acquired_at' => $row->acquired_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array{slot: string, definition: array<string, mixed>, catalog_identity: string, instance_identity: string|null}>
     */
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
            $definition = $this->definitionForRow($row);
            $slot = $row->equipped_slot;
            if (! is_string($slot) || ! in_array($slot, UndergroundEquipmentCatalog::EQUIPPED_SLOTS, true)) {
                throw new RuntimeException('Underground equipped slot is unsupported.');
            }
            $expectedCategory = str_starts_with($slot, 'accessory_') ? 'accessory' : $slot;
            if ($definition['category'] !== $expectedCategory) {
                throw new RuntimeException('Underground equipped slot is incompatible.');
            }
            $definitions[] = [
                'slot' => $slot,
                'definition' => $definition,
                'catalog_identity' => $row->catalog_identity,
                'instance_identity' => $row->instance_identity,
            ];
        }

        return $definitions;
    }
}
