<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationResourceSalePolicy extends Model
{
    protected $fillable = ['nation_id', 'resource_definition_id', 'policy', 'keep_amount', 'version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['keep_amount' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** @return BelongsTo<ResourceDefinition, $this> */
    public function resourceDefinition(): BelongsTo
    {
        return $this->belongsTo(ResourceDefinition::class);
    }
}
