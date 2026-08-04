<?php

namespace App\Http\Resources;

use App\Domain\Economy\NationCapacities;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\NationLandAreaCalculator;
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
            'id' => $this->id, 'world_id' => $this->world_id,
            'nation_number' => $this->nation_number, 'name' => $this->name,
            'owner_name' => $this->owner_name,
            'comment' => $this->profile_comment,
            'money' => $this->when($isOwner, (int) $this->money),
            'money_display' => $this->when($isOwner, app(MoneyFormatter::class)->exact((int) $this->money)),
            'money_capacity' => $this->when($isOwner, $capacities?->money),
            'money_remaining_capacity' => $this->when(
                $isOwner,
                max(0, ($capacities->money ?? 0) - (int) $this->money),
            ),
            'money_is_at_capacity' => $this->when(
                $isOwner,
                (int) $this->money >= ($capacities->money ?? PHP_INT_MAX),
            ),
            'state' => $this->state,
            'current_turn' => (int) $this->world()->value('current_turn'),
            'total_population' => (int) $this->territoryCells()->sum('population'),
            'territory_cell_count' => $this->territoryCells()->count(),
            'owned_land_cells' => app(NationLandAreaCalculator::class)->forNation($this->resource),
            'total_food_tons' => $this->when($isOwner, $foodTotal),
            'food_total_tons' => $this->when($isOwner, $foodTotal),
            'food_capacity_tons' => $this->when($isOwner, $capacities?->foodTons),
            'food_remaining_capacity_tons' => $this->when(
                $isOwner,
                max(0, ($capacities->foodTons ?? 0) - ($foodTotal ?? 0)),
            ),
            'food_is_at_capacity' => $this->when(
                $isOwner,
                ($foodTotal ?? 0) >= ($capacities->foodTons ?? PHP_INT_MAX),
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
                    ...$this->capacityFields($balance, $capacities, $foodTotal),
                ])->values()->all() ?? []),
            'capital' => $this->whenLoaded('capital', fn (): ?array => $this->capital === null ? null : [
                'x' => $this->capital->x, 'y' => $this->capital->y,
            ]),
        ];
    }

    /** @return array{capacity: int|null, remaining_capacity: int|null, is_at_capacity: bool} */
    private function capacityFields(
        NationResourceBalance $balance,
        ?NationCapacities $capacities,
        ?int $foodTotal,
    ): array {
        if ($capacities === null) {
            return ['capacity' => null, 'remaining_capacity' => null, 'is_at_capacity' => false];
        }

        $isFood = $balance->definition->category === 'food';
        $capacity = $isFood
            ? $capacities->foodTons
            : $capacities->resource($balance->definition->key);
        $amountForCapacity = $isFood ? ($foodTotal ?? 0) : (int) $balance->amount;

        return [
            'capacity' => $capacity,
            'remaining_capacity' => $capacity === null ? null : max(0, $capacity - $amountForCapacity),
            'is_at_capacity' => $capacity !== null && $amountForCapacity >= $capacity,
        ];
    }
}
