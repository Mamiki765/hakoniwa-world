<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MapChunkResource extends JsonResource
{
    /** @return array<array-key, mixed> */
    public function toArray(Request $request): array
    {
        if (! is_array($this->resource)) {
            throw new \LogicException('MapChunkResource expects an array payload.');
        }

        return $this->resource;
    }
}
