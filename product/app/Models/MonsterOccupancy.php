<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $monster_instance_id
 * @property int $map_cell_id
 * @property-read MonsterInstance $monster
 * @property-read MapCell $cell
 */
final class MonsterOccupancy extends Model
{
    protected $fillable = ['monster_instance_id', 'map_cell_id'];

    /** @return BelongsTo<MonsterInstance, $this> */
    public function monster(): BelongsTo
    {
        return $this->belongsTo(MonsterInstance::class, 'monster_instance_id');
    }

    /** @return BelongsTo<MapCell, $this> */
    public function cell(): BelongsTo
    {
        return $this->belongsTo(MapCell::class, 'map_cell_id');
    }
}
