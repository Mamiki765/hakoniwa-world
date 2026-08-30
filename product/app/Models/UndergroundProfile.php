<?php

namespace App\Models;

use App\Domain\Underground\Area\UndergroundAreaCapacity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $secretary_id
 * @property int $unlocked_area_layers
 * @property int $combat_level
 * @property int $combat_xp
 * @property int $shard_balance
 * @property Carbon|null $next_battle_at
 * @property Carbon|null $underground_contract_completed_at
 * @property string|null $growth_path_key
 * @property string|null $growth_path_identity
 * @property Carbon|null $growth_path_selected_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Secretary $secretary
 * @property-read Collection<int, UndergroundTrialProgress> $trialProgresses
 * @property-read UndergroundTrialRun|null $trialRun
 * @property-read Collection<int, UndergroundBattle> $battles
 * @property-read UndergroundIntroProgress|null $introProgress
 * @property-read Collection<int, UndergroundIntroRequest> $introRequests
 */
final class UndergroundProfile extends Model
{
    protected $fillable = [
        'secretary_id', 'unlocked_area_layers', 'combat_level', 'combat_xp', 'shard_balance', 'next_battle_at',
        'underground_contract_completed_at', 'growth_path_key', 'growth_path_identity', 'growth_path_selected_at',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_area_layers' => 'integer',
            'combat_level' => 'integer',
            'combat_xp' => 'integer',
            'shard_balance' => 'integer',
            'next_battle_at' => 'immutable_datetime',
            'underground_contract_completed_at' => 'immutable_datetime',
            'growth_path_selected_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Secretary, $this> */
    public function secretary(): BelongsTo
    {
        return $this->belongsTo(Secretary::class);
    }

    /** @return HasMany<UndergroundTrialProgress, $this> */
    public function trialProgresses(): HasMany
    {
        return $this->hasMany(UndergroundTrialProgress::class, 'underground_profile_id');
    }

    /** @return HasOne<UndergroundTrialRun, $this> */
    public function trialRun(): HasOne
    {
        return $this->hasOne(UndergroundTrialRun::class, 'underground_profile_id');
    }

    /** @return HasMany<UndergroundBattle, $this> */
    public function battles(): HasMany
    {
        return $this->hasMany(UndergroundBattle::class, 'underground_profile_id');
    }

    /** @return HasOne<UndergroundIntroProgress, $this> */
    public function introProgress(): HasOne
    {
        return $this->hasOne(UndergroundIntroProgress::class, 'underground_profile_id');
    }

    /** @return HasMany<UndergroundIntroRequest, $this> */
    public function introRequests(): HasMany
    {
        return $this->hasMany(UndergroundIntroRequest::class, 'underground_profile_id');
    }

    public function facilitySlotCapacity(): int
    {
        return UndergroundAreaCapacity::forUnlockedLayers($this->unlocked_area_layers);
    }
}
