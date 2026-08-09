<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable award occurrence for one Nation in one World.
 *
 * @property int $id
 * @property int $world_id
 * @property int $nation_id
 * @property string $award_key
 * @property int $awarded_turn
 * @property string $award_occurrence_key
 */
final class NationAward extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'world_id', 'nation_id', 'award_key', 'awarded_turn', 'award_occurrence_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['awarded_turn' => 'integer'];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}
