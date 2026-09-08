<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Normalized image paths retained while a battle's action log is viewable.
 *
 * A request-keyed row may be created during short snapshot preparation and is
 * promoted to a battle row when settlement creates the battle and log. The
 * explicit retained-until timestamp closes that preparation gap without
 * scanning battle JSON or retaining files forever.
 *
 * @property int $id
 * @property string $reference_key
 * @property int|null $underground_battle_id
 * @property Carbon $retained_until
 * @property string $path
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read UndergroundBattle|null $battle
 */
final class UndergroundBattleImageReference extends Model
{
    protected $fillable = ['reference_key', 'underground_battle_id', 'retained_until', 'path'];

    protected function casts(): array
    {
        return [
            'underground_battle_id' => 'integer',
            'retained_until' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UndergroundBattle, $this> */
    public function battle(): BelongsTo
    {
        return $this->belongsTo(UndergroundBattle::class, 'underground_battle_id');
    }
}
