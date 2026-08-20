<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;

final class SecretaryItemPresenter
{
    public function __construct(private readonly SecretaryItemCatalog $catalog) {}

    /** @return array{equipment_version: int, inventory: array{capacity: int, used: int, items: list<array<string, mixed>>}, equipment: array{slot_count: int, slots: list<array<string, mixed>>, category_limits: list<array{category: string, label: string, maximum_equipped: int}>}} */
    public function present(Secretary $secretary): array
    {
        $secretary->loadMissing('itemInstances');
        $items = $secretary->itemInstances->sortBy([
            ['obtained_at', 'asc'],
            ['id', 'asc'],
        ])->values()->map(fn (SecretaryItemInstance $item): array => $this->item($item));
        $equipped = $items->filter(fn (array $item): bool => $item['equipped_slot'] !== null)
            ->keyBy('equipped_slot');
        $slots = collect(range(1, SecretaryItemGrantService::EQUIPMENT_SLOT_COUNT))
            ->map(fn (int $slot): array => ['slot' => $slot, 'item' => $equipped->get($slot)])
            ->all();

        return [
            'equipment_version' => $secretary->equipment_version,
            'inventory' => [
                'capacity' => SecretaryItemGrantService::INVENTORY_CAPACITY,
                'used' => $items->count(),
                'items' => $items->all(),
            ],
            'equipment' => [
                'slot_count' => SecretaryItemGrantService::EQUIPMENT_SLOT_COUNT,
                'slots' => $slots,
                'category_limits' => $this->catalog->categoryLimits(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function item(SecretaryItemInstance $item): array
    {
        $definition = $this->catalog->definition($item->item_key);

        return [
            'id' => $item->id,
            'key' => $item->item_key,
            'name' => $definition['name'],
            'level' => $item->level,
            'category' => $definition['category'],
            'category_label' => $definition['category_label'],
            'equipped_slot' => $item->equipped_slot,
            'is_equipped' => $item->equipped_slot !== null,
            'flavor_text' => $definition['flavor_text'],
            'obtained_at' => $item->obtained_at->toIso8601String(),
        ];
    }
}
