<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $equipment_version
 * @property string|null $name
 * @property Carbon|null $named_at
 * @property-read User $user
 * @property-read Collection<int, SecretarySkill> $skills
 * @property-read Collection<int, SecretaryItemInstance> $itemInstances
 */
final class Secretary extends Model
{
    protected $fillable = ['user_id', 'name', 'named_at'];

    protected function casts(): array
    {
        return [
            'equipment_version' => 'integer',
            'named_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SecretarySkill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(SecretarySkill::class);
    }

    /** @return HasMany<SecretaryItemInstance, $this> */
    public function itemInstances(): HasMany
    {
        return $this->hasMany(SecretaryItemInstance::class);
    }
}
