<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $world_id
 * @property string $key
 * @property string $name
 * @property string $coordinate_system
 * @property int $min_q
 * @property int $max_q
 * @property int $min_r
 * @property int $max_r
 */
class MapSpace extends Model
{
    protected $fillable = [
        'world_id', 'key', 'name', 'coordinate_system', 'min_q', 'max_q', 'min_r', 'max_r',
    ];

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return HasMany<MapCell, $this> */
    public function cells(): HasMany
    {
        return $this->hasMany(MapCell::class);
    }

    /** @return HasMany<MapChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(MapChunk::class);
    }
}
