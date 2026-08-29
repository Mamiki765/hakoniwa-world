<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Immutable result of one atomic Underground combat settlement.
 *
 * @property int $id
 * @property int $underground_profile_id
 * @property string $request_id
 * @property string $request_fingerprint
 * @property string $runtime_identity
 * @property string $activity_type
 * @property string $activity_key
 * @property string $encounter_key
 * @property string|null $trial_run_key
 * @property int|null $trial_battle_index
 * @property string $result
 * @property int $rounds
 * @property int $damage_dealt
 * @property int $damage_received
 * @property int $healing_done
 * @property int $xp_awarded
 * @property int $shard_delta
 * @property int $combat_level_before
 * @property int $combat_level_after
 * @property int $combat_xp_before
 * @property int $combat_xp_after
 * @property int $shard_balance_before
 * @property int $shard_balance_after
 * @property int $private_seed
 * @property array<string, mixed> $snapshot
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read UndergroundProfile $profile
 * @property-read UndergroundBattleLog|null $log
 */
final class UndergroundBattle extends Model
{
    public const ACTIVITY_EXPLORATION = 'exploration';

    public const ACTIVITY_TRIAL = 'trial';

    public const RESULT_VICTORY = 'victory';

    public const RESULT_DEFEAT = 'defeat';

    public const RESULT_WITHDRAWAL = 'withdrawal';

    protected $fillable = [
        'underground_profile_id', 'request_id', 'request_fingerprint', 'runtime_identity', 'activity_type', 'activity_key',
        'encounter_key', 'trial_run_key', 'trial_battle_index', 'result', 'rounds',
        'damage_dealt', 'damage_received', 'healing_done', 'xp_awarded', 'shard_delta',
        'combat_level_before', 'combat_level_after', 'combat_xp_before', 'combat_xp_after',
        'shard_balance_before', 'shard_balance_after', 'private_seed', 'snapshot', 'started_at', 'finished_at',
    ];

    protected $hidden = ['private_seed'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'trial_battle_index' => 'integer',
            'rounds' => 'integer',
            'damage_dealt' => 'integer',
            'damage_received' => 'integer',
            'healing_done' => 'integer',
            'xp_awarded' => 'integer',
            'shard_delta' => 'integer',
            'combat_level_before' => 'integer',
            'combat_level_after' => 'integer',
            'combat_xp_before' => 'integer',
            'combat_xp_after' => 'integer',
            'shard_balance_before' => 'integer',
            'shard_balance_after' => 'integer',
            'private_seed' => 'integer',
            'snapshot' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }

    /** @return HasOne<UndergroundBattleLog, $this> */
    public function log(): HasOne
    {
        return $this->hasOne(UndergroundBattleLog::class, 'underground_battle_id');
    }
}
