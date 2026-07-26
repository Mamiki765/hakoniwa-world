<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $asset_key
 * @property bool $is_water
 * @property bool $is_buildable
 * @property string|null $quantity_key
 * @property string|null $quantity_label
 * @property string|null $quantity_unit
 * @property int|null $initial_quantity
 * @property int|null $minimum_quantity
 * @property int|null $maximum_quantity
 * @property string|null $growth_rule_key
 * @property array<string, mixed> $metadata
 */
class TerrainDefinition extends Model
{
    protected $fillable = [
        'key', 'name', 'asset_key', 'is_water', 'is_buildable', 'quantity_key', 'quantity_label',
        'quantity_unit', 'initial_quantity', 'minimum_quantity', 'maximum_quantity', 'growth_rule_key', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_water' => 'boolean', 'is_buildable' => 'boolean', 'initial_quantity' => 'integer',
            'minimum_quantity' => 'integer', 'maximum_quantity' => 'integer', 'metadata' => 'array',
        ];
    }
}
