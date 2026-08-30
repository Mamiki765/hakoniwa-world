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
 * @property int $banked_shard_balance
 * @property int|null $current_hp
 * @property Carbon|null $next_battle_at
 * @property Carbon|null $underground_contract_completed_at
 * @property string|null $growth_path_key
 * @property string|null $growth_path_identity
 * @property Carbon|null $growth_path_selected_at
 * @property int $unspent_stp
 * @property int $allocated_vitality_stp
 * @property int $allocated_might_stp
 * @property int $allocated_finesse_stp
 * @property int $allocated_spirit_stp
 * @property int $allocated_agility_stp
 * @property int $skill_points_total
 * @property int $skill_points_unspent
 * @property string|null $skill_tree_identity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Secretary $secretary
 * @property-read Collection<int, UndergroundTrialProgress> $trialProgresses
 * @property-read UndergroundTrialRun|null $trialRun
 * @property-read Collection<int, UndergroundBattle> $battles
 * @property-read UndergroundIntroProgress|null $introProgress
 * @property-read Collection<int, UndergroundIntroRequest> $introRequests
 * @property-read Collection<int, UndergroundSkillAllocation> $skillAllocations
 * @property-read Collection<int, UndergroundOwnedEquipment> $ownedEquipment
 */
final class UndergroundProfile extends Model
{
    protected $fillable = [
        'secretary_id', 'unlocked_area_layers', 'combat_level', 'combat_xp', 'shard_balance', 'next_battle_at',
        'banked_shard_balance', 'current_hp',
        'underground_contract_completed_at', 'growth_path_key', 'growth_path_identity', 'growth_path_selected_at',
        'unspent_stp', 'allocated_vitality_stp', 'allocated_might_stp', 'allocated_finesse_stp',
        'allocated_spirit_stp', 'allocated_agility_stp',
        'skill_points_total', 'skill_points_unspent', 'skill_tree_identity',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_area_layers' => 'integer',
            'combat_level' => 'integer',
            'combat_xp' => 'integer',
            'shard_balance' => 'integer',
            'banked_shard_balance' => 'integer',
            'current_hp' => 'integer',
            'next_battle_at' => 'immutable_datetime',
            'underground_contract_completed_at' => 'immutable_datetime',
            'growth_path_selected_at' => 'immutable_datetime',
            'unspent_stp' => 'integer',
            'allocated_vitality_stp' => 'integer',
            'allocated_might_stp' => 'integer',
            'allocated_finesse_stp' => 'integer',
            'allocated_spirit_stp' => 'integer',
            'allocated_agility_stp' => 'integer',
            'skill_points_total' => 'integer',
            'skill_points_unspent' => 'integer',
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

    /** @return HasMany<UndergroundSkillAllocation, $this> */
    public function skillAllocations(): HasMany
    {
        return $this->hasMany(UndergroundSkillAllocation::class, 'underground_profile_id');
    }

    /** @return HasMany<UndergroundOwnedEquipment, $this> */
    public function ownedEquipment(): HasMany
    {
        return $this->hasMany(UndergroundOwnedEquipment::class, 'underground_profile_id');
    }

    public function facilitySlotCapacity(): int
    {
        return UndergroundAreaCapacity::forUnlockedLayers($this->unlocked_area_layers);
    }

    /** @return array{vitality: int, might: int, finesse: int, spirit: int, agility: int} */
    public function allocatedStp(): array
    {
        return [
            'vitality' => $this->allocated_vitality_stp,
            'might' => $this->allocated_might_stp,
            'finesse' => $this->allocated_finesse_stp,
            'spirit' => $this->allocated_spirit_stp,
            'agility' => $this->allocated_agility_stp,
        ];
    }

    /** @return array<string, array{rank: int, active_slot: int|null}> */
    public function skillAllocationMap(): array
    {
        $allocations = $this->relationLoaded('skillAllocations')
            ? $this->getRelation('skillAllocations')
            : $this->skillAllocations()->get();
        if (! $allocations instanceof Collection) {
            return [];
        }

        $result = [];
        foreach ($allocations as $allocation) {
            if (! $allocation instanceof UndergroundSkillAllocation) {
                continue;
            }
            $result[$allocation->node_key] = [
                'rank' => $allocation->rank,
                'active_slot' => $allocation->active_slot,
            ];
        }

        return $result;
    }
}
