<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionDefinition extends Model
{
    protected $fillable = [
        'ruleset_version_id', 'key', 'facility_definition_id', 'output_resource_definition_id',
        'production_per_scale', 'required_workforce_per_scale', 'operating_condition',
        'price_reference', 'enabled', 'metadata',
    ];

    /** @return BelongsTo<FacilityDefinition, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(FacilityDefinition::class, 'facility_definition_id');
    }

    /** @return BelongsTo<ResourceDefinition, $this> */
    public function outputResource(): BelongsTo
    {
        return $this->belongsTo(ResourceDefinition::class, 'output_resource_definition_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'production_per_scale' => 'float', 'required_workforce_per_scale' => 'integer',
            'enabled' => 'boolean', 'metadata' => 'array',
        ];
    }
}
