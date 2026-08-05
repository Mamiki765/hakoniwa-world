<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $asset_key
 * @property bool $enabled
 * @property string|null $build_command_key
 * @property string $visibility_policy
 * @property string|null $disguise_terrain_key
 * @property string|null $disguise_asset_key
 * @property string|null $disguise_ownership_policy
 * @property int|null $scale_unit_people
 * @property int|null $initial_scale
 * @property int|null $scale_increment
 * @property int|null $maximum_scale
 * @property int|null $workforce_per_scale_people
 * @property string|null $production_definition_key
 * @property array<int, string> $buildable_terrain_keys
 * @property array<string, mixed> $metadata
 */
class FacilityDefinition extends Model
{
    protected $fillable = [
        'key', 'name', 'asset_key', 'enabled', 'build_command_key', 'visibility_policy', 'disguise_terrain_key',
        'disguise_asset_key', 'disguise_ownership_policy', 'scale_unit_people', 'initial_scale', 'scale_increment', 'maximum_scale',
        'workforce_per_scale_people', 'production_definition_key', 'buildable_terrain_keys', 'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'scale_unit_people' => 'integer', 'initial_scale' => 'integer', 'scale_increment' => 'integer',
            'maximum_scale' => 'integer', 'workforce_per_scale_people' => 'integer',
            'buildable_terrain_keys' => 'array', 'metadata' => 'array',
        ];
    }
}
