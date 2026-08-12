<?php

namespace App\Models;

use App\Domain\World\MapBounds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $world_id
 * @property string $key
 * @property string $name
 * @property string $coordinate_system
 * @property int $min_x
 * @property int $max_x
 * @property int $min_y
 * @property int $max_y
 */
class MapSpace extends Model
{
    protected $fillable = [
        'world_id', 'key', 'name', 'coordinate_system', 'min_x', 'max_x', 'min_y', 'max_y',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_x' => 'integer',
            'max_x' => 'integer',
            'min_y' => 'integer',
            'max_y' => 'integer',
        ];
    }

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

    public function currentBounds(): MapBounds
    {
        return new MapBounds(
            $this->min_x,
            $this->max_x,
            $this->min_y,
            $this->max_y,
            (int) config('hakoniwa.ruleset.chunk_size'),
        );
    }

    public function boundsRevision(): string
    {
        return $this->currentBounds()->revision();
    }
}
