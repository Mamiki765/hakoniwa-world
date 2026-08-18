<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
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

    public function grant(
        Secretary $secretary,
        string $itemKey,
        int $level,
        ?int $equippedSlot,
        ?string $grantKey,
    ): ?SecretaryItemInstance {
        $definition = $this->catalog->definition($itemKey);
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
        ): ?SecretaryItemInstance {
            $locked = Secretary::query()->whereKey($secretary->id)->lockForUpdate()->firstOrFail();
            if ($grantKey !== null) {
                $existingGrant = $locked->itemInstances()->where('grant_key', $grantKey)->first();
                if ($existingGrant instanceof SecretaryItemInstance) {
                    if ($existingGrant->item_key !== $itemKey || $existingGrant->level !== $level) {
                        throw new DomainException("Secretary item grant {$grantKey} was already used for different state.");
                    }

                    return $existingGrant;
                }
            }
            if ($definition['unique_per_secretary'] && $locked->itemInstances()->where('item_key', $itemKey)->exists()) {
                throw new DomainException("Secretary already owns unique item {$itemKey} outside this grant.");
            }

            $used = $locked->itemInstances()->count();
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
                'obtained_at' => now(),
            ]);
        }, 3);
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
