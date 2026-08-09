<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $world_id
 * @property int $nation_number
 * @property int $registered_turn
 * @property string $name
 * @property string $owner_name
 * @property string $profile_comment
 * @property int $money
 * @property string $state
 * @property int $idle_counter
 * @property-read NationCapital|null $capital
 * @property-read Collection<int, NationResource> $resourceBalances
 */
class Nation extends Model
{
    protected $fillable = [
        'world_id', 'nation_number', 'registered_turn', 'name', 'owner_name', 'profile_comment', 'money', 'state',
        'idle_counter',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nation_number' => 'integer',
            'registered_turn' => 'integer',
            'idle_counter' => 'integer',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return HasOne<NationCapital, $this> */
    public function capital(): HasOne
    {
        return $this->hasOne(NationCapital::class);
    }

    /** @return HasMany<NationResource, $this> */
    public function resourceBalances(): HasMany
    {
        return $this->hasMany(NationResource::class);
    }

    /** @return HasOne<NationCommandQueue, $this> */
    public function commandQueue(): HasOne
    {
        return $this->hasOne(NationCommandQueue::class);
    }

    /** @return HasMany<MapCell, $this> */
    public function territoryCells(): HasMany
    {
        return $this->hasMany(MapCell::class, 'owner_nation_id');
    }

    /** @return HasMany<NationResourceSalePolicy, $this> */
    public function salePolicies(): HasMany
    {
        return $this->hasMany(NationResourceSalePolicy::class);
    }

    /** @return HasMany<NationMonsterKillStat, $this> */
    public function monsterKillStats(): HasMany
    {
        return $this->hasMany(NationMonsterKillStat::class);
    }

    /** @return HasMany<NationAward, $this> */
    public function awards(): HasMany
    {
        return $this->hasMany(NationAward::class);
    }

    /** @return HasMany<NationMonsterCycleStat, $this> */
    public function monsterCycleStats(): HasMany
    {
        return $this->hasMany(NationMonsterCycleStat::class);
    }
}
