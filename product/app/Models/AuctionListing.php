<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $world_id
 * @property string $seller_type
 * @property int|null $seller_nation_id
 * @property string $product_type
 * @property int|null $resource_definition_id
 * @property int|null $secretary_item_instance_id
 * @property string|null $item_key
 * @property int|null $item_level
 * @property int|null $quantity
 * @property int $start_price
 * @property int|null $current_price
 * @property int|null $highest_bidder_nation_id
 * @property int $bid_count
 * @property int $duration_turns
 * @property int $started_turn
 * @property int $ends_turn
 * @property bool $auto_relist
 * @property int $relist_count
 * @property string $status
 * @property int|null $completed_turn
 * @property-read World $world
 * @property-read Nation|null $sellerNation
 * @property-read Nation|null $highestBidderNation
 * @property-read ResourceDefinition|null $resourceDefinition
 * @property-read SecretaryItemInstance|null $secretaryItemInstance
 * @property-read Collection<int, AuctionBid> $bids
 */
final class AuctionListing extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SOLD = 'sold';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'world_id', 'seller_type', 'seller_nation_id', 'product_type', 'resource_definition_id',
        'secretary_item_instance_id', 'item_key', 'item_level', 'quantity', 'start_price',
        'current_price', 'highest_bidder_nation_id', 'bid_count', 'duration_turns', 'started_turn',
        'ends_turn', 'auto_relist', 'relist_count', 'status', 'completed_turn',
    ];

    protected function casts(): array
    {
        return [
            'item_level' => 'integer', 'quantity' => 'integer', 'start_price' => 'integer',
            'current_price' => 'integer', 'bid_count' => 'integer', 'duration_turns' => 'integer',
            'started_turn' => 'integer', 'ends_turn' => 'integer', 'auto_relist' => 'boolean',
            'relist_count' => 'integer', 'completed_turn' => 'integer',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<Nation, $this> */
    public function sellerNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'seller_nation_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function highestBidderNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'highest_bidder_nation_id');
    }

    /** @return BelongsTo<ResourceDefinition, $this> */
    public function resourceDefinition(): BelongsTo
    {
        return $this->belongsTo(ResourceDefinition::class);
    }

    /** @return BelongsTo<SecretaryItemInstance, $this> */
    public function secretaryItemInstance(): BelongsTo
    {
        return $this->belongsTo(SecretaryItemInstance::class);
    }

    /** @return HasMany<AuctionBid, $this> */
    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class);
    }
}
