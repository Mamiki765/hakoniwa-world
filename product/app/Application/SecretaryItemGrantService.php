<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SecretaryItemGrantService
{
    public const INVENTORY_CAPACITY = 50;

    public const EQUIPMENT_SLOT_COUNT = 5;

    public const STARTER_OLD_BOW_GRANT = 'starter:old_bow';

    public function __construct(private readonly SecretaryItemCatalog $catalog) {}

    public function grantStarterOldBow(Secretary $secretary): ?SecretaryItemInstance
    {
        return $this->grant(
            $secretary,
            SecretaryItemCatalog::OLD_BOW,
            1,
            1,
            self::STARTER_OLD_BOW_GRANT,
        );
    }

    /** @param array<array-key, mixed>|null $resolvedEconomics */
    public function grant(
        Secretary $secretary,
        string $itemKey,
        int $level,
        ?int $equippedSlot,
        ?string $grantKey,
        ?array $resolvedEconomics = null,
    ): ?SecretaryItemInstance {
        $resolvedRarity = $resolvedEconomics['rarity'] ?? null;
        $resolvedFixedSalePriceMoney = $resolvedEconomics['fixed_sale_price_money'] ?? null;
        if (($resolvedEconomics !== null
                && (count($resolvedEconomics) !== 2
                    || array_diff(array_keys($resolvedEconomics), ['rarity', 'fixed_sale_price_money']) !== []))
            || ($resolvedRarity !== null && ! is_string($resolvedRarity))
            || ($resolvedFixedSalePriceMoney !== null && ! is_int($resolvedFixedSalePriceMoney))) {
            throw new DomainException('Secretary item resolved economics are invalid.');
        }
        $definition = $this->catalog->definitionWithResolvedEconomics(
            $itemKey,
            $resolvedRarity,
            $resolvedFixedSalePriceMoney,
        );
        if ($level < 1 || $level > $definition['max_level']) {
            throw new DomainException("Invalid level {$level} for Secretary item {$itemKey}.");
        }
        if ($equippedSlot !== null && ($equippedSlot < 1 || $equippedSlot > self::EQUIPMENT_SLOT_COUNT)) {
            throw new DomainException('Secretary equipment slot must be between 1 and 5.');
        }
        if ($grantKey !== null && (trim($grantKey) === '' || strlen($grantKey) > 128)) {
            throw new DomainException('Secretary item grant key must be 1-128 characters when present.');
        }

        return DB::transaction(function () use (
            $secretary,
            $itemKey,
            $level,
            $equippedSlot,
            $grantKey,
            $definition,
            $resolvedRarity,
            $resolvedFixedSalePriceMoney,
        ): ?SecretaryItemInstance {
            $locked = Secretary::query()->whereKey($secretary->id)->firstOrFail();
            $locked->lockSurfaceState();
            if ($grantKey !== null) {
                $existingGrant = $locked->itemInstances()->where('grant_key', $grantKey)->first();
                if ($existingGrant instanceof SecretaryItemInstance) {
                    if ($existingGrant->item_key !== $itemKey || $existingGrant->level !== $level
                        || $existingGrant->resolved_rarity !== $resolvedRarity
                        || $existingGrant->resolved_fixed_sale_price_money !== $resolvedFixedSalePriceMoney) {
                        throw new DomainException("Secretary item grant {$grantKey} was already used for different state.");
                    }

                    return $existingGrant;
                }
            }

            return $this->persistLockedGrant(
                $locked, $itemKey, $level, $equippedSlot, $grantKey,
                $definition, $resolvedRarity, $resolvedFixedSalePriceMoney,
            );
        }, 3);
    }

    /**
     * Generate an unequipped drop only after its locked duplicate/capacity checks.
     * The callback only draws an Item and level; it must not mutate inventory.
     *
     * @param  Closure(): array{item_key: string, level: int}  $generate
     * @return array{status: 'granted'|'already_granted', item: SecretaryItemInstance}|array{status: 'inventory_full', inventory_used: int}
     */
    public function grantGenerated(int $secretaryId, string $grantKey, Closure $generate): array
    {
        if (trim($grantKey) === '' || strlen($grantKey) > 128) {
            throw new DomainException('Secretary item grant key must be 1-128 characters when present.');
        }

        // The enclosing Turn attempt, not this RNG callback, owns deadlock retries.
        return DB::transaction(function () use ($secretaryId, $grantKey, $generate): array {
            $locked = Secretary::query()->whereKey($secretaryId)->firstOrFail();
            $locked->lockSurfaceState();
            $existing = $locked->itemInstances()->where('grant_key', $grantKey)->first();
            if ($existing instanceof SecretaryItemInstance) {
                return ['status' => 'already_granted', 'item' => $existing];
            }
            $used = $locked->itemInstances()->count();
            if ($used >= self::INVENTORY_CAPACITY) {
                return ['status' => 'inventory_full', 'inventory_used' => $used];
            }

            $generated = $generate();
            $itemKey = $generated['item_key'];
            $level = $generated['level'];
            $definition = $this->catalog->definitionWithResolvedEconomics($itemKey, null, null);
            if ($level < 1 || $level > $definition['max_level']) {
                throw new DomainException("Invalid level {$level} for Secretary item {$itemKey}.");
            }
            $item = $this->persistLockedGrant(
                $locked, $itemKey, $level, null, $grantKey, $definition, null, null, $used,
            );
            if (! $item instanceof SecretaryItemInstance) {
                throw new DomainException('Monster drop inventory changed after its locked pre-draw capacity check.');
            }

            return ['status' => 'granted', 'item' => $item];
        }, 1);
    }

    /** @param array<string, mixed> $definition */
    private function persistLockedGrant(
        Secretary $locked,
        string $itemKey,
        int $level,
        ?int $equippedSlot,
        ?string $grantKey,
        array $definition,
        ?string $resolvedRarity,
        ?int $resolvedFixedSalePriceMoney,
        ?int $knownInventoryUsage = null,
    ): ?SecretaryItemInstance {
        if ($definition['unique_per_secretary'] && $locked->itemInstances()->where('item_key', $itemKey)->exists()) {
            throw new DomainException("Secretary already owns unique item {$itemKey} outside this grant.");
        }

        $used = $knownInventoryUsage ?? $locked->itemInstances()->count();
        if ($used >= self::INVENTORY_CAPACITY) {
            $this->recordInventoryFull($locked, $itemKey, $grantKey, $used);

            return null;
        }
        if ($equippedSlot !== null && $locked->itemInstances()->where('equipped_slot', $equippedSlot)->exists()) {
            throw new DomainException("Secretary equipment slot {$equippedSlot} is already occupied.");
        }
        if ($equippedSlot !== null) {
            $equippedInCategory = $locked->itemInstances()
                ->whereNotNull('equipped_slot')
                ->get(['item_key'])
                ->filter(fn (SecretaryItemInstance $item): bool => (
                    $this->catalog->definition($item->item_key)['category'] === $definition['category']
                ))
                ->count();
            if ($equippedInCategory >= $this->catalog->maximumEquipped($definition['category'])) {
                throw new DomainException("Secretary cannot equip another {$definition['category']} item.");
            }
        }

        return $locked->itemInstances()->create([
            'item_key' => $itemKey,
            'level' => $level,
            'equipped_slot' => $equippedSlot,
            'grant_key' => $grantKey,
            ...($resolvedRarity === null ? [] : [
                'resolved_rarity' => $resolvedRarity,
                'resolved_fixed_sale_price_money' => $resolvedFixedSalePriceMoney,
            ]),
            'obtained_at' => now(),
        ]);
    }

    private function recordInventoryFull(Secretary $secretary, string $itemKey, ?string $grantKey, int $used): void
    {
        $occurredAt = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $secretary->user_id,
            'world_id' => null,
            'turn' => null,
            'nation_id' => null,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'private',
            'event_type' => 'secretary.inventory_full',
            'severity' => 'warning',
            'subject_type' => Secretary::class,
            'subject_id' => $secretary->id,
            'metadata' => json_encode([
                'secretary_id' => $secretary->id,
                'user_id' => $secretary->user_id,
                'item_key' => $itemKey,
                'grant_key' => $grantKey,
                'capacity' => self::INVENTORY_CAPACITY,
                'used' => $used,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
