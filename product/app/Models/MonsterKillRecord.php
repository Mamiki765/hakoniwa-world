<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable authoritative fact for one Nation-attributed final blow.
 *
 * @property int $id
 * @property int $world_id
 * @property int $monster_instance_id
 * @property int $monster_definition_id
 * @property int $killer_nation_id
 * @property int|null $host_nation_id
 * @property int|null $firing_base_id
 * @property int $target_turn
 * @property string $kill_cause
 */
final class MonsterKillRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'world_id', 'monster_instance_id', 'monster_definition_id', 'killer_nation_id',
        'host_nation_id', 'firing_base_id', 'target_turn', 'kill_cause', 'wreckage_value_money',
        'killer_money_requested', 'killer_money_applied', 'killer_money_overflow',
        'host_meat_food_requested', 'host_meat_food_applied', 'host_meat_food_overflow',
        'firing_base_experience_applied',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_turn' => 'integer', 'wreckage_value_money' => 'integer',
            'killer_money_requested' => 'integer', 'killer_money_applied' => 'integer',
            'killer_money_overflow' => 'integer', 'host_meat_food_requested' => 'integer',
            'host_meat_food_applied' => 'integer', 'host_meat_food_overflow' => 'integer',
            'firing_base_experience_applied' => 'integer',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<MonsterInstance, $this> */
    public function monster(): BelongsTo
    {
        return $this->belongsTo(MonsterInstance::class, 'monster_instance_id');
    }

    /** @return BelongsTo<MonsterDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MonsterDefinition::class, 'monster_definition_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function killerNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'killer_nation_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function hostNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'host_nation_id');
    }
}
