<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $nation_id
 * @property int $resource_definition_id
 * @property int $amount
 * @property-read ResourceDefinition $definition
 */
class NationResource extends Model
{
    protected $fillable = ['nation_id', 'resource_definition_id', 'amount'];

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** @return BelongsTo<ResourceDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ResourceDefinition::class, 'resource_definition_id');
    }
}
