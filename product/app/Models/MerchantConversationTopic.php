<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MerchantConversationTopic extends Model
{
    protected $fillable = ['question', 'answer', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
