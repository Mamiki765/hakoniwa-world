<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id, 'world_id' => $this->world_id, 'name' => $this->name,
            'money' => $this->money,
            'money_display' => app(MoneyFormatter::class)->exact((int) $this->money),
            'state' => $this->state,
            'current_turn' => (int) $this->world()->value('current_turn'),
            'total_population' => (int) $this->territoryCells()->sum('population'),
            'territory_cell_count' => $this->territoryCells()->count(),
            'resources' => $this->whenLoaded('resourceBalances', fn (): array => $this->resourceBalances
                ->sortBy(fn (NationResourceBalance $balance): int => $balance->definition->sort_order)
                ->map(fn (NationResourceBalance $balance): array => [
                    'key' => $balance->definition->key,
                    'name' => $balance->definition->name,
                    'category' => $balance->definition->category,
                    'unit' => $balance->definition->unit,
                    'nutrition_per_unit' => $balance->definition->nutrition_per_unit,
                    'storable' => $balance->definition->storable,
                    'tradable' => $balance->definition->tradable,
                    'amount' => $balance->amount,
                ])->values()->all()),
            'capital' => $this->whenLoaded('capital', fn (): ?array => $this->capital === null ? null : [
                'x' => $this->capital->x, 'y' => $this->capital->y,
            ]),
        ];
    }
}
