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
            'bounds_revision' => $this->boundsRevision(),
            'bounds' => ['min_x' => $this->min_x, 'max_x' => $this->max_x, 'min_y' => $this->min_y, 'max_y' => $this->max_y],
        ];
    }
}
