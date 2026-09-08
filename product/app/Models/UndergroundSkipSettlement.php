<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $request_id
 * @property string $content_type
 * @property string $content_key
 * @property int $ticket_cost
 * @property int $xp_awarded
 * @property int $shard_awarded
 * @property int $combat_level_before
 * @property int $combat_level_after
 * @property array<string, mixed> $reward_snapshot
 * @property CarbonInterface $settled_at
 */
final class UndergroundSkipSettlement extends Model
{
    protected $fillable = [
        'underground_profile_id', 'user_id', 'request_id', 'request_fingerprint',
        'skip_identity', 'content_type', 'content_key', 'content_identity', 'ticket_cost',
        'xp_awarded', 'shard_awarded', 'combat_level_before', 'combat_level_after',
        'combat_xp_before', 'combat_xp_after', 'shard_balance_before', 'shard_balance_after',
        'private_seed', 'reward_snapshot', 'settled_at',
    ];

    protected $hidden = ['private_seed'];

    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'user_id' => 'integer',
            'ticket_cost' => 'integer',
            'xp_awarded' => 'integer',
            'shard_awarded' => 'integer',
            'combat_level_before' => 'integer',
            'combat_level_after' => 'integer',
            'combat_xp_before' => 'integer',
            'combat_xp_after' => 'integer',
            'shard_balance_before' => 'integer',
            'shard_balance_after' => 'integer',
            'private_seed' => 'integer',
            'reward_snapshot' => 'array',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
