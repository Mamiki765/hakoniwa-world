<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Authoritative total attributed final blows for one Nation and 100-turn interval.
 *
 * @property int $id
 * @property int $world_id
 * @property int $nation_id
 * @property int $cycle_start_turn
 * @property int $cycle_end_turn
 * @property int $kill_count
 * @property int $version
 * @property Carbon|null $seeded_at
 */
final class NationMonsterCycleStat extends Model
{
    protected $fillable = [
        'world_id', 'nation_id', 'cycle_start_turn', 'cycle_end_turn',
        'kill_count', 'version', 'seeded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cycle_start_turn' => 'integer', 'cycle_end_turn' => 'integer',
            'kill_count' => 'integer', 'version' => 'integer', 'seeded_at' => 'immutable_datetime',
        ];
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
