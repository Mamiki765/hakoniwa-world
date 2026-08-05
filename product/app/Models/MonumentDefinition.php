<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MonumentDefinition extends Model
{
    protected $fillable = [
        'key', 'name', 'asset_key', 'description', 'effect_key', 'enabled', 'sort_order', 'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort_order' => 'integer', 'metadata' => 'array'];
    }
}
