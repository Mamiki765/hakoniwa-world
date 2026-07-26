<?php

namespace App\Http\Resources;

use App\Models\World;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin World */
class WorldResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'key' => $this->key, 'name' => $this->name, 'turn' => $this->current_turn];
    }
}
