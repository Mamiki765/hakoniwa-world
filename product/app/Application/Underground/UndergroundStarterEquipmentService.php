<?php

namespace App\Application\Underground;

use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class UndergroundStarterEquipmentService
{
    public const GRANT_KEY = 'starter-knife-alpha-v1';

    public function __construct(private UndergroundEquipmentCatalog $catalog) {}

    public function reconcile(UndergroundProfile $profile): UndergroundOwnedEquipment
    {
        if ($profile->growth_path_key === null) {
            throw new RuntimeException('Underground starter equipment requires selected growth.');
        }
        $starters = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', UndergroundEquipmentCatalog::STARTER_KEY)
            ->lockForUpdate()
            ->get();
        if ($starters->count() > 1) {
            throw new RuntimeException('Underground starter equipment is duplicated.');
        }
        $starter = $starters->first();
        if (! $starter instanceof UndergroundOwnedEquipment) {
            $starter = UndergroundOwnedEquipment::query()->create([
                'underground_profile_id' => $profile->id,
                'definition_key' => UndergroundEquipmentCatalog::STARTER_KEY,
                'catalog_identity' => $this->catalog->identity(),
                'equipped_slot' => null,
                'grant_key' => self::GRANT_KEY,
                'instance_kind' => 'fixed',
                'acquired_at' => Carbon::now(),
            ]);
        }
        if (! $this->catalog->supportsIdentity($starter->catalog_identity)
            || $starter->instance_kind !== 'fixed'
            || $starter->grant_key !== self::GRANT_KEY) {
            throw new RuntimeException('Underground starter equipment identity is invalid.');
        }
        $equippedWeapon = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('equipped_slot', 'weapon')
            ->lockForUpdate()
            ->first();
        if (! $equippedWeapon instanceof UndergroundOwnedEquipment) {
            $starter->equipped_slot = 'weapon';
            $starter->save();
        }

        return $starter->refresh();
    }
}
