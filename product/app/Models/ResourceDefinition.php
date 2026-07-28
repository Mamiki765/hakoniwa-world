<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $category
 * @property string $unit
 * @property string|null $unit_label
 * @property float|null $nutrition_per_unit
 * @property bool $storable
 * @property bool $tradable
 * @property string|null $sale_price_key
 * @property int $sort_order
 * @property array<string, mixed> $metadata
 */
class ResourceDefinition extends Model
{
    protected $fillable = [
        'key', 'name', 'category', 'unit', 'unit_label', 'nutrition_per_unit', 'storable', 'tradable',
        'sale_price_key', 'sort_order', 'metadata',
    ];

    /** @return HasMany<NationResource, $this> */
    public function nationBalances(): HasMany
    {
        return $this->hasMany(NationResource::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nutrition_per_unit' => 'float', 'storable' => 'boolean', 'tradable' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
