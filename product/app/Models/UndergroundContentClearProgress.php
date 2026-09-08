<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UndergroundContentClearProgress extends Model
{
    protected $table = 'underground_content_clear_progress';

    protected $fillable = [
        'underground_profile_id', 'content_type', 'content_key',
        'actual_clear_count', 'total_clear_count',
    ];

    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'actual_clear_count' => 'integer',
            'total_clear_count' => 'integer',
        ];
    }
}
