<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $world_id
 * @property int $monster_definition_id
 * @property int $current_hp
 * @property int $spawned_max_hp
 * @property string $state
 * @property int $spawned_target_turn
 * @property int $version
 * @property string|null $removal_reason
 * @property Carbon|null $removed_at
 * @property-read MonsterDefinition $definition
 * @property-read MonsterOccupancy|null $occupancy
 */
final class MonsterInstance extends Model
{
    protected $fillable = [
        'world_id', 'monster_definition_id', 'current_hp', 'spawned_max_hp', 'state',
        'spawned_target_turn', 'version', 'removal_reason', 'removed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_hp' => 'integer', 'spawned_max_hp' => 'integer',
            'spawned_target_turn' => 'integer', 'version' => 'integer', 'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<MonsterDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MonsterDefinition::class, 'monster_definition_id');
    }

    /** @return HasOne<MonsterOccupancy, $this> */
    public function occupancy(): HasOne
    {
        return $this->hasOne(MonsterOccupancy::class);
    }

    /** @return HasOne<MonsterKillRecord, $this> */
    public function killRecord(): HasOne
    {
        return $this->hasOne(MonsterKillRecord::class);
    }
}
