<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $map_space_id
 * @property int $chunk_x
 * @property int $chunk_y
 * @property int $version
 * @property string|null $weather_key
 * @property int|null $weather_turn
 */
class MapChunk extends Model
{
    protected $fillable = [
        'map_space_id', 'chunk_x', 'chunk_y', 'version', 'generated_at',
        'generator_id', 'generator_version', 'generation_seed',
        'weather_key', 'weather_turn',
    ];
}
