<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SecretaryLendingSetting extends Model
{
    protected $fillable = ['secretary_id', 'is_public', 'is_available'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_available' => 'boolean'];
    }
}
