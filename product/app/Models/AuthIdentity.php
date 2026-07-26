<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string $display_name
 * @property Carbon|null $created_at
 */
class AuthIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'display_name', 'avatar_url'];

    protected $hidden = ['provider_user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
