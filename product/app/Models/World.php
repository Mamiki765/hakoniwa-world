<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $ruleset_version_id
 * @property int $current_turn
 * @property CarbonImmutable|null $turn_schedule_origin_at
 */
class World extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['current_turn' => 1];

    protected $fillable = ['key', 'name', 'ruleset_version_id', 'current_turn', 'turn_schedule_origin_at'];

    protected function casts(): array
    {
        return ['turn_schedule_origin_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<RulesetVersion, $this> */
    public function rulesetVersion(): BelongsTo
    {
        return $this->belongsTo(RulesetVersion::class);
    }

    /** @return HasMany<MapSpace, $this> */
    public function mapSpaces(): HasMany
    {
        return $this->hasMany(MapSpace::class);
    }

    /** @return HasMany<Nation, $this> */
    public function nations(): HasMany
    {
        return $this->hasMany(Nation::class);
    }

    /** @return HasMany<TurnRun, $this> */
    public function turnRuns(): HasMany
    {
        return $this->hasMany(TurnRun::class);
    }

    /** @return HasMany<NationAward, $this> */
    public function nationAwards(): HasMany
    {
        return $this->hasMany(NationAward::class);
    }

    /** @return HasMany<NationMonsterCycleStat, $this> */
    public function nationMonsterCycleStats(): HasMany
    {
        return $this->hasMany(NationMonsterCycleStat::class);
    }

    /** @return HasMany<Ship, $this> */
    public function ships(): HasMany
    {
        return $this->hasMany(Ship::class);
    }
}
