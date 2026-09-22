<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Source IDs are durable provenance, not foreign keys to live receipts.
 * The exclusive non-null source column identifies battle / skip / bulk skip.
 *
 * @property int $id
 * @property int $underground_profile_id
 * @property string $definition_key
 * @property string $catalog_identity
 * @property string|null $equipped_slot
 * @property string|null $grant_key
 * @property string $instance_kind
 * @property string|null $instance_identity
 * @property string|null $generator_identity
 * @property array<string, mixed>|null $generated_payload
 * @property int|null $source_battle_id
 * @property int|null $source_skip_settlement_id
 * @property int|null $source_skip_batch_id
 * @property int|null $source_reward_index
 * @property Carbon $acquired_at
 * @property-read UndergroundProfile $profile
 */
final class UndergroundOwnedEquipment extends Model
{
    protected $table = 'underground_owned_equipment';

    protected $fillable = [
        'underground_profile_id', 'definition_key', 'catalog_identity',
        'equipped_slot', 'grant_key', 'instance_kind', 'instance_identity',
        'generator_identity', 'generated_payload', 'source_battle_id',
        'source_skip_settlement_id', 'source_skip_batch_id', 'source_reward_index', 'acquired_at',
    ];

    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'source_battle_id' => 'integer',
            'source_skip_settlement_id' => 'integer',
            'source_skip_batch_id' => 'integer',
            'source_reward_index' => 'integer',
            'generated_payload' => 'array',
            'acquired_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<static> $query */
    public function scopeInventory(Builder $query, string $inventory = 'equipment'): void
    {
        if ($inventory === 'resonance') {
            $query->where('generated_payload->category', 'resonance');
        } else {
            $query->where(function (Builder $items): void {
                $items->whereNull('generated_payload')
                    ->orWhere('generated_payload->category', '!=', 'resonance');
            });
        }
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }
}
