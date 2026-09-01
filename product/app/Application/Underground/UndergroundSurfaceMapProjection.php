<?php

namespace App\Application\Underground;

use App\Domain\Underground\Area\UndergroundAreaCapacity;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationMembership;
use App\Models\NationUndergroundFacility;
use App\Models\Secretary;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use App\Services\AssetManifestResolver;

final readonly class UndergroundSurfaceMapProjection
{
    /** @var list<int> */
    public const SLOT_X_OFFSETS = [-2, -1, 1, 2];

    public function __construct(private AssetManifestResolver $assets) {}

    /** @return array<string, mixed>|null */
    public function forUser(User $user): ?array
    {
        $secretary = Secretary::query()
            ->where('user_id', $user->id)
            ->with(['undergroundProfile.trialProgresses'])
            ->first();
        $nationId = NationMembership::query()
            ->join('nations', 'nations.id', '=', 'nation_memberships.nation_id')
            ->where('nation_memberships.user_id', $user->id)
            ->where('nation_memberships.role', 'owner')
            ->whereIn('nations.state', ['active', 'dormant', 'recovery'])
            ->orderBy('nation_memberships.id')
            ->value('nation_memberships.nation_id');
        if ($nationId === null) {
            return null;
        }

        return $this->project($secretary, (int) $nationId);
    }

    /** @return array<string, mixed>|null */
    public function forNation(Nation $nation): ?array
    {
        $ownerUserId = NationMembership::query()
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')
            ->value('user_id');
        if ($ownerUserId === null) {
            return null;
        }
        $secretary = Secretary::query()
            ->where('user_id', (int) $ownerUserId)
            ->with(['undergroundProfile.trialProgresses'])
            ->first();

        return $this->project($secretary, (int) $nation->id);
    }

    /** @return array<string, mixed>|null */
    private function project(?Secretary $secretary, int $nationId): ?array
    {
        $profile = $secretary?->undergroundProfile;
        if (! $profile instanceof UndergroundProfile || $profile->unlocked_area_layers < 1) {
            return null;
        }
        $trialOne = $profile->trialProgresses
            ->first(fn (UndergroundTrialProgress $progress): bool => $progress->trial_key === 'trial_01');
        if (! $trialOne instanceof UndergroundTrialProgress || $trialOne->first_cleared_at === null) {
            return null;
        }

        $capital = NationCapital::query()->where('nation_id', $nationId)->first();
        if (! $capital instanceof NationCapital) {
            return null;
        }
        $facilities = NationUndergroundFacility::query()
            ->where('nation_id', $nationId)
            ->get()
            ->keyBy(static fn (NationUndergroundFacility $facility): string => $facility->layer.':'.$facility->slot_index);
        $layers = [];
        for ($layer = 1; $layer <= $profile->unlocked_area_layers; $layer++) {
            $z = -($layer + 1);
            $slots = [];
            foreach (self::SLOT_X_OFFSETS as $slotIndex => $offset) {
                $facilityKey = $facilities->get($layer.':'.$slotIndex)?->facility_key;
                $relativeX = $offset > 0 ? 'X+'.$offset : 'X'.$offset;
                $slots[] = [
                    'slot_index' => $slotIndex,
                    'offset_x' => $offset,
                    'coordinate' => [
                        'x' => $capital->x + $offset,
                        'y' => $capital->y,
                        'z' => $z,
                    ],
                    'coordinate_label' => sprintf('(%d, %d, %d)', $capital->x + $offset, $capital->y, $z),
                    'relative_label' => sprintf('(%s, Y, %d)', $relativeX, $z),
                    'facility_key' => $facilityKey,
                    'asset_key' => match ($facilityKey) {
                        'underground_city' => 'underground.city',
                        'underground_farm' => 'underground.farm',
                        'underground_factory' => 'underground.factory',
                        'underground_missile_base' => 'underground.missile_base',
                        default => 'underground.road',
                    },
                ];
            }
            $layers[] = [
                'layer' => $layer,
                'z' => $z,
                'ladder' => ['asset_key' => 'underground.ladder', 'counts_as_facility_slot' => false],
                'slots' => $slots,
            ];
        }

        return [
            'unlocked_layers' => $profile->unlocked_area_layers,
            'facility_slots_per_layer' => UndergroundAreaCapacity::FACILITY_SLOTS_PER_LAYER,
            'total_facility_slots' => $profile->facilitySlotCapacity(),
            'capital' => ['x' => $capital->x, 'y' => $capital->y],
            'entrance' => ['asset_key' => 'underground.entrance', 'counts_as_facility_slot' => false],
            'assets' => [
                'soil' => $this->assets->resolve('underground.soil', '土', 'underground'),
                'entrance' => $this->assets->resolve('underground.entrance', '入口', 'underground'),
                'ladder' => $this->assets->resolve('underground.ladder', '梯', 'underground'),
                'road' => $this->assets->resolve('underground.road', '空', 'underground'),
                'underground_city' => $this->assets->resolve('underground.city', '都', 'underground'),
                'underground_farm' => $this->assets->resolve('underground.farm', '農', 'underground'),
                'underground_factory' => $this->assets->resolve('underground.factory', '工', 'underground'),
                'underground_missile_base' => $this->assets->resolve('underground.missile_base', '基', 'underground'),
            ],
            'layers' => $layers,
        ];
    }
}
