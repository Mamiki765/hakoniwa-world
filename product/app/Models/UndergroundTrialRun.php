<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persistent trial run state. One row is retained for each Underground profile.
 *
 * @property int $id
 * @property int $underground_profile_id
 * @property string $run_key
 * @property string $trial_key
 * @property int $next_battle_index
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read UndergroundProfile $profile
 */
final class UndergroundTrialRun extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_DEFEATED = 'defeated';

    public const STATUS_CLEARED = 'cleared';

    protected $fillable = [
        'underground_profile_id', 'run_key', 'trial_key', 'next_battle_index', 'status', 'started_at', 'ended_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'next_battle_index' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }
}
