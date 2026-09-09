<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $underground_profile_id
 * @property int $user_id
 * @property string $request_id
 * @property string $request_fingerprint
 * @property string $skip_identity
 * @property string $content_type
 * @property string $content_key
 * @property string $content_identity
 * @property int $execution_count
 * @property int $ticket_cost
 * @property int $xp_awarded
 * @property int $shard_awarded
 * @property int $combat_level_before
 * @property int $combat_level_after
 * @property int $combat_xp_before
 * @property int $combat_xp_after
 * @property int $shard_balance_before
 * @property int $shard_balance_after
 * @property array<string, mixed> $reward_snapshot
 * @property CarbonInterface $settled_at
 */
final class UndergroundSkipBatch extends Model
{
    protected $fillable = [
        'underground_profile_id', 'user_id', 'request_id', 'request_fingerprint',
        'skip_identity', 'content_type', 'content_key', 'content_identity',
        'execution_count', 'ticket_cost', 'xp_awarded', 'shard_awarded',
        'combat_level_before', 'combat_level_after', 'combat_xp_before', 'combat_xp_after',
        'shard_balance_before', 'shard_balance_after', 'reward_snapshot', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'user_id' => 'integer',
            'execution_count' => 'integer',
            'ticket_cost' => 'integer',
            'xp_awarded' => 'integer',
            'shard_awarded' => 'integer',
            'combat_level_before' => 'integer',
            'combat_level_after' => 'integer',
            'combat_xp_before' => 'integer',
            'combat_xp_after' => 'integer',
            'shard_balance_before' => 'integer',
            'shard_balance_after' => 'integer',
            'reward_snapshot' => 'array',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
