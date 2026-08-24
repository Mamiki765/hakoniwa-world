<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $ruleset_version_id
 * @property string $key
 * @property string $name
 * @property string $asset_key
 * @property string|null $hardened_asset_key
 * @property int|null $display_order
 * @property int $base_hp
 * @property int $hp_variation
 * @property string $skill_key
 * @property int $movement_limit
 * @property int|null $natural_spawn_tier
 * @property int $wreckage_value_money
 * @property int $missile_base_experience
 * @property int|null $experience_per_damage
 * @property string $skill_description
 * @property string $visibility
 * @property array<string, mixed> $movement_terrain_contract
 * @property array<string, mixed> $trample_contract
 * @property array<string, mixed> $hardening_contract
 * @property array<string, mixed> $source_metadata
 */
final class MonsterDefinition extends Model
{
    protected $fillable = [
        'ruleset_version_id', 'key', 'name', 'asset_key', 'hardened_asset_key', 'display_order', 'base_hp',
        'hp_variation', 'skill_key', 'movement_limit', 'natural_spawn_tier',
        'wreckage_value_money', 'missile_base_experience', 'experience_per_damage', 'skill_description', 'visibility',
        'movement_terrain_contract', 'trample_contract', 'hardening_contract', 'source_metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer', 'base_hp' => 'integer', 'hp_variation' => 'integer', 'movement_limit' => 'integer',
            'natural_spawn_tier' => 'integer', 'wreckage_value_money' => 'integer',
            'missile_base_experience' => 'integer', 'experience_per_damage' => 'integer',
            'movement_terrain_contract' => 'array',
            'trample_contract' => 'array', 'hardening_contract' => 'array', 'source_metadata' => 'array',
        ];
    }

    /** @return BelongsTo<RulesetVersion, $this> */
    public function rulesetVersion(): BelongsTo
    {
        return $this->belongsTo(RulesetVersion::class);
    }

    /** @return HasMany<MonsterInstance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(MonsterInstance::class);
    }

    /** @return HasMany<NationMonsterKillStat, $this> */
    public function killStats(): HasMany
    {
        return $this->hasMany(NationMonsterKillStat::class);
    }
}
