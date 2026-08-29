<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Secretary-owned unlock and first-clear record for one Underground trial.
 *
 * @property int $id
 * @property int $underground_profile_id
 * @property string $trial_key
 * @property Carbon $unlocked_at
 * @property Carbon|null $first_cleared_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read UndergroundProfile $profile
 */
final class UndergroundTrialProgress extends Model
{
    protected $fillable = [
        'underground_profile_id', 'trial_key', 'unlocked_at', 'first_cleared_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'unlocked_at' => 'immutable_datetime',
            'first_cleared_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }
}
