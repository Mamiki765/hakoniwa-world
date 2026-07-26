<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $ruleset_version_id
 * @property int $current_turn
 */
class World extends Model
{
    protected $fillable = ['key', 'name', 'ruleset_version_id', 'current_turn'];

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
}
