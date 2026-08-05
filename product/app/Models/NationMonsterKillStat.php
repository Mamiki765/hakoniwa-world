<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authoritative aggregate for one Nation and monster species in one World.
 *
 * @property int $id
 * @property int $world_id
 * @property int $nation_id
 * @property int $monster_definition_id
 * @property int $kill_count
 * @property int $first_killed_turn
 * @property int $last_killed_turn
 * @property int $version
 */
final class NationMonsterKillStat extends Model
{
    protected $fillable = [
        'world_id', 'nation_id', 'monster_definition_id', 'kill_count',
        'first_killed_turn', 'last_killed_turn', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kill_count' => 'integer', 'first_killed_turn' => 'integer',
            'last_killed_turn' => 'integer', 'version' => 'integer',
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

    /** @return BelongsTo<MonsterDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MonsterDefinition::class, 'monster_definition_id');
    }
}
