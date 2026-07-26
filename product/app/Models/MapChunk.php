<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $map_space_id
 * @property int $chunk_q
 * @property int $chunk_r
 * @property int $version
 */
class MapChunk extends Model
{
    protected $fillable = [
        'map_space_id', 'chunk_q', 'chunk_r', 'version', 'generated_at',
        'generator_id', 'generator_version', 'generation_seed',
    ];
}
