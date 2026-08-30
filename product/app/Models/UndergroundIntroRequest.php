<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotency record for one Secretary-owned Underground intro mutation.
 *
 * @property int $id
 * @property int $underground_profile_id
 * @property string $request_id
 * @property string $request_fingerprint
 * @property string $operation
 * @property string $resulting_stage
 * @property int|null $underground_battle_id
 */
final class UndergroundIntroRequest extends Model
{
    protected $fillable = [
        'underground_profile_id', 'request_id', 'request_fingerprint', 'operation',
        'resulting_stage', 'underground_battle_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'underground_battle_id' => 'integer',
        ];
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }

    /** @return BelongsTo<UndergroundBattle, $this> */
    public function battle(): BelongsTo
    {
        return $this->belongsTo(UndergroundBattle::class, 'underground_battle_id');
    }
}
