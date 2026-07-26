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
 */
class TerrainDefinition extends Model
{
    protected $fillable = ['key', 'name', 'asset_key', 'is_water', 'is_buildable'];

    protected function casts(): array
    {
        return ['is_water' => 'boolean', 'is_buildable' => 'boolean'];
    }
}
