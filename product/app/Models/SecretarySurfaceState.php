<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $secretary_id
 * @property int $monster_experience
 * @property int $equipment_version
 */
final class SecretarySurfaceState extends Model
{
    protected $primaryKey = 'secretary_id';

    public $incrementing = false;

    protected $fillable = ['secretary_id', 'monster_experience', 'equipment_version'];

    protected function casts(): array
    {
        return ['secretary_id' => 'integer', 'monster_experience' => 'integer', 'equipment_version' => 'integer'];
    }
}
