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
 * @property string $name
 * @property int $money
 * @property string $state
 * @property-read NationCapital|null $capital
 * @property-read Collection<int, NationResource> $resourceBalances
 */
class Nation extends Model
{
    protected $fillable = ['world_id', 'name', 'money', 'state'];

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
}
