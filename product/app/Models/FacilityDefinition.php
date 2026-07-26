<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $asset_key
 */
class FacilityDefinition extends Model
{
    protected $fillable = ['key', 'name', 'asset_key'];
}
