<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Player-facing compact action log for one completed Underground battle.
 *
 * @property int $id
 * @property int $underground_battle_id
 * @property list<array<string, mixed>> $actions
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read UndergroundBattle $battle
 */
final class UndergroundBattleLog extends Model
{
    protected $fillable = ['underground_battle_id', 'actions', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'underground_battle_id' => 'integer',
            'actions' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UndergroundBattle, $this> */
    public function battle(): BelongsTo
    {
        return $this->belongsTo(UndergroundBattle::class, 'underground_battle_id');
    }
}
