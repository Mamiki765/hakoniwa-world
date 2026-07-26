<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $nation_id
 * @property int $map_space_id
 * @property int $version
 * @property-read Collection<int, NationCommandQueueItem> $items
 */
class NationCommandQueue extends Model
{
    protected $fillable = ['nation_id', 'map_space_id', 'version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** @return BelongsTo<MapSpace, $this> */
    public function mapSpace(): BelongsTo
    {
        return $this->belongsTo(MapSpace::class);
    }

    /** @return HasMany<NationCommandQueueItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(NationCommandQueueItem::class);
    }
}
