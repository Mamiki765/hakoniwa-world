<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $reward_snapshot
 * @property-read MapCell $cell
 */
final class BuriedTreasure extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_COLLECTED = 'collected';

    public const STATE_REMOVED = 'removed';

    protected $fillable = [
        'world_id', 'map_cell_id', 'source', 'reward_snapshot', 'created_turn', 'state',
        'resolved_by_nation_id', 'resolved_turn', 'resolution_reason', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_snapshot' => 'array',
            'created_turn' => 'integer',
            'resolved_turn' => 'integer',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MapCell, $this> */
    public function cell(): BelongsTo
    {
        return $this->belongsTo(MapCell::class, 'map_cell_id');
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
