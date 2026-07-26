<?php

namespace App\Http\Resources;

use App\Models\MapSpace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MapSpace */
class MapSpaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'world_id' => $this->world_id,
            'key' => $this->key, 'name' => $this->name,
            'coordinate_system' => $this->coordinate_system,
            'bounds' => ['min_q' => $this->min_q, 'max_q' => $this->max_q, 'min_r' => $this->min_r, 'max_r' => $this->max_r],
        ];
    }
}
