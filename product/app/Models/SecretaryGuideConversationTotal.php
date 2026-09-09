<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SecretaryGuideConversationTotal extends Model
{
    protected $fillable = [
        'secretary_id', 'topics_started', 'normal_replies', 'punch_count',
    ];

    protected function casts(): array
    {
        return [
            'secretary_id' => 'integer',
            'topics_started' => 'integer',
            'normal_replies' => 'integer',
            'punch_count' => 'integer',
        ];
    }
}
