<?php

namespace App\Http\Resources;

use App\Models\Nation;
use App\Models\NationResource as NationResourceBalance;
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
            'money' => $this->money, 'state' => $this->state,
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
                'q' => $this->capital->q, 'r' => $this->capital->r,
            ]),
        ];
    }
}
