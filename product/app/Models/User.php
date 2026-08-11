<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $display_name
 * @property string|null $visitor_code
 * @property Carbon|null $message_board_last_posted_at
 * @property Carbon|null $created_at
 * @property-read Collection<int, AuthIdentity> $authIdentities
 */
#[Fillable(['display_name'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'message_board_last_posted_at' => 'datetime',
        ];
    }

    /** @return HasMany<AuthIdentity, $this> */
    public function authIdentities(): HasMany
    {
        return $this->hasMany(AuthIdentity::class);
    }

    /** @return HasMany<NationMembership, $this> */
    public function nationMemberships(): HasMany
    {
        return $this->hasMany(NationMembership::class);
    }
}
