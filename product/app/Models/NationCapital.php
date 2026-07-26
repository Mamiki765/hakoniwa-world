<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $nation_id
 * @property int $map_cell_id
 * @property int $x
 * @property int $y
 */
class NationCapital extends Model
{
    protected $fillable = ['nation_id', 'map_cell_id', 'x', 'y'];

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** @return BelongsTo<MapCell, $this> */
    public function cell(): BelongsTo
    {
        return $this->belongsTo(MapCell::class, 'map_cell_id');
    }
}
