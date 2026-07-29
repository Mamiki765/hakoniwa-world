<?php

namespace App\Http\Resources;

use App\Domain\Economy\NationCapacityResolver;
use App\Models\Nation;
use App\Models\NationResource as NationResourceBalance;
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Nation */
class NationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $balances = $this->relationLoaded('resourceBalances')
            ? $this->resourceBalances->sortBy(fn (NationResourceBalance $balance): int => $balance->definition->sort_order)
            : null;
        $isOwner = $balances !== null;
        $foodTotal = $isOwner
            ? (int) $balances
                ->filter(fn (NationResourceBalance $balance): bool => $balance->definition->category === 'food')
                ->sum('amount')
            : null;
        $capacities = $isOwner
            ? app(NationCapacityResolver::class)->resolve($this->resource)
            : null;

        return [
            'id' => $this->id, 'world_id' => $this->world_id, 'name' => $this->name,
            'money' => $this->when($isOwner, (int) $this->money),
            'money_display' => $this->when($isOwner, app(MoneyFormatter::class)->exact((int) $this->money)),
            'money_capacity' => $this->when($isOwner, $capacities?->money),
            'money_remaining_capacity' => $this->when(
                $isOwner,
                max(0, ($capacities->money ?? 0) - (int) $this->money),
            ),
            'state' => $this->state,
            'current_turn' => (int) $this->world()->value('current_turn'),
            'total_population' => (int) $this->territoryCells()->sum('population'),
            'territory_cell_count' => $this->territoryCells()->count(),
            'total_food_tons' => $this->when($isOwner, $foodTotal),
            'food_total_tons' => $this->when($isOwner, $foodTotal),
            'food_capacity_tons' => $this->when($isOwner, $capacities?->foodTons),
            'food_remaining_capacity_tons' => $this->when(
                $isOwner,
                max(0, ($capacities->foodTons ?? 0) - ($foodTotal ?? 0)),
            ),
            'food_resources' => $this->when($isOwner, fn (): array => $balances
                ?->filter(fn (NationResourceBalance $balance): bool => $balance->definition->category === 'food')
                ->map(fn (NationResourceBalance $balance): array => [
                    'key' => $balance->definition->key,
                    'name' => $balance->definition->name,
                    'balance' => $balance->amount,
                    'unit' => $balance->definition->unit,
                    'unit_label' => $balance->definition->unit_label,
                ])->values()->all() ?? []),
            'resources' => $this->when($isOwner, fn (): array => $balances
                ?->map(fn (NationResourceBalance $balance): array => [
                    'key' => $balance->definition->key,
                    'name' => $balance->definition->name,
                    'category' => $balance->definition->category,
                    'unit' => $balance->definition->unit,
                    'unit_label' => $balance->definition->unit_label,
                    'nutrition_per_unit' => $balance->definition->nutrition_per_unit,
                    'storable' => $balance->definition->storable,
                    'tradable' => $balance->definition->tradable,
                    'amount' => $balance->amount,
                ])->values()->all() ?? []),
            'capital' => $this->whenLoaded('capital', fn (): ?array => $this->capital === null ? null : [
                'x' => $this->capital->x, 'y' => $this->capital->y,
            ]),
        ];
    }
}
