<?php

namespace App\Application\Underground;

use App\Domain\Turn\TurnState;
use App\Domain\Underground\Facility\UndergroundCommandCatalog;
use App\Models\NationUndergroundFacility;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class UndergroundFacilityBenefits
{
    public function __construct(private readonly UndergroundCommandCatalog $commands) {}

    public function capitalMaximumBonus(int $nationId): int
    {
        return $this->sumLiveEffects($nationId, 'underground_city', 'capital_maximum_population_bonus');
    }

    public function capitalMaximumBonusForTurn(TurnState $state, int $nationId): int
    {
        return $this->sumTurnEffects(
            $state,
            $nationId,
            'underground_city',
            'capital_maximum_population_bonus',
        );
    }

    public function farmCapacityBonus(int $nationId): int
    {
        return $this->sumLiveEffects($nationId, 'underground_farm', 'farm_capacity_people');
    }

    public function farmCapacityBonusForTurn(TurnState $state, int $nationId): int
    {
        return $this->sumTurnEffects($state, $nationId, 'underground_farm', 'farm_capacity_people');
    }

    public function factoryCapacityBonus(int $nationId): int
    {
        return $this->sumLiveEffects($nationId, 'underground_factory', 'factory_capacity_people');
    }

    public function factoryCapacityBonusForTurn(TurnState $state, int $nationId): int
    {
        return $this->sumTurnEffects($state, $nationId, 'underground_factory', 'factory_capacity_people');
    }

    /** @return list<array{id: int, ruleset_version_id: int, layer: int, slot_index: int, facility_key: string, effect: array<string, int>}> */
    public function factoryFacilities(int $nationId): array
    {
        return $this->liveSnapshots([$nationId], 'underground_factory')[$nationId] ?? [];
    }

    /** @return list<array{id: int, ruleset_version_id: int, layer: int, slot_index: int, facility_key: string, effect: array<string, int>}> */
    public function factoryFacilitiesForTurn(TurnState $state, int $nationId): array
    {
        return $this->facilitiesForTurn($state, $nationId, 'underground_factory');
    }

    /** @param array{id: int, ruleset_version_id: int, layer: int, slot_index: int, facility_key: string, effect: array<string, int>} $facility */
    public function effectValue(array $facility, string $effectKey): int
    {
        $value = $facility['effect'][$effectKey] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new DomainException("Underground facility effect {$facility['facility_key']}.{$effectKey} is invalid.");
        }

        return $value;
    }

    /**
     * @param  list<int>  $nationIds
     * @return list<int>
     */
    public function missileBaseIdsForTurn(TurnState $state, array $nationIds): array
    {
        sort($nationIds, SORT_NUMERIC);
        $baseIds = [];
        foreach ($nationIds as $nationId) {
            foreach ($this->facilitiesForTurn($state, $nationId, 'underground_missile_base') as $facility) {
                if ($this->effectValue($facility, 'missile_launch_capacity') !== 1) {
                    throw new DomainException('Each Underground missile base must provide exactly one launch-capacity shot.');
                }
                $baseIds[] = $facility['id'];
            }
        }

        return $baseIds;
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, list<array{id: int, ruleset_version_id: int, layer: int, slot_index: int, facility_key: string, effect: array<string, int>}>>
     */
    public function loadTurnSnapshots(array $nationIds): array
    {
        return $this->liveSnapshots($nationIds);
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, array{farm_capacity_people: int, factory_capacity_people: int}>
     */
    public function workforceCapacityBonuses(array $nationIds): array
    {
        $bonuses = [];
        foreach ($nationIds as $nationId) {
            $bonuses[$nationId] = ['farm_capacity_people' => 0, 'factory_capacity_people' => 0];
        }
        foreach ($this->liveSnapshots($nationIds) as $nationId => $facilities) {
            foreach ($facilities as $facility) {
                if ($facility['facility_key'] === 'underground_farm') {
                    $bonuses[$nationId]['farm_capacity_people'] += $this->effectValue(
                        $facility,
                        'farm_capacity_people',
                    );
                } elseif ($facility['facility_key'] === 'underground_factory') {
                    $bonuses[$nationId]['factory_capacity_people'] += $this->effectValue(
                        $facility,
                        'factory_capacity_people',
                    );
                }
            }
        }

        return $bonuses;
    }

    /** @return Collection<int, NationUndergroundFacility> */
    public function missileBases(int $nationId): Collection
    {
        return NationUndergroundFacility::query()
            ->where('nation_id', $nationId)
            ->where('facility_key', 'underground_missile_base')
            ->orderBy('layer')
            ->orderBy('slot_index')
            ->get();
    }

    private function sumLiveEffects(int $nationId, string $facilityKey, string $effectKey): int
    {
        return array_sum(array_map(
            fn (array $facility): int => $this->effectValue($facility, $effectKey),
            $this->liveSnapshots([$nationId], $facilityKey)[$nationId] ?? [],
        ));
    }

    private function sumTurnEffects(
        TurnState $state,
        int $nationId,
        string $facilityKey,
        string $effectKey,
    ): int {
        return array_sum(array_map(
            fn (array $facility): int => $this->effectValue($facility, $effectKey),
            $this->facilitiesForTurn($state, $nationId, $facilityKey),
        ));
    }

    /** @return list<array{id: int, ruleset_version_id: int, layer: int, slot_index: int, facility_key: string, effect: array<string, int>}> */
    private function facilitiesForTurn(TurnState $state, int $nationId, string $facilityKey): array
    {
        if (! $state->hasUndergroundFacilitySnapshots()) {
            return $this->liveSnapshots([$nationId], $facilityKey)[$nationId] ?? [];
        }

        return array_values(array_filter(
            $state->undergroundFacilitySnapshotsForNation($nationId),
            static fn (array $facility): bool => $facility['facility_key'] === $facilityKey,
        ));
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, list<array{id: int, ruleset_version_id: int, layer: int, slot_index: int, facility_key: string, effect: array<string, int>}>>
     */
    private function liveSnapshots(array $nationIds, ?string $facilityKey = null): array
    {
        $snapshots = [];
        foreach ($nationIds as $nationId) {
            $snapshots[$nationId] = [];
        }
        if ($snapshots === []) {
            return [];
        }

        $query = NationUndergroundFacility::query()
            ->whereIn('nation_id', $nationIds)
            ->with('rulesetVersion')
            ->orderBy('nation_id')
            ->orderBy('layer')
            ->orderBy('slot_index');
        if ($facilityKey !== null) {
            $query->where('facility_key', $facilityKey);
        }
        foreach ($query->get(['id', 'nation_id', 'ruleset_version_id', 'layer', 'slot_index', 'facility_key']) as $facility) {
            $ruleset = $facility->rulesetVersion;
            $effect = $this->commands->forFacility($ruleset->settings, $facility->facility_key)->effect;
            $snapshots[(int) $facility->nation_id][] = [
                'id' => (int) $facility->id,
                'ruleset_version_id' => (int) $facility->ruleset_version_id,
                'layer' => (int) $facility->layer,
                'slot_index' => (int) $facility->slot_index,
                'facility_key' => (string) $facility->facility_key,
                'effect' => $effect,
            ];
        }

        return $snapshots;
    }
}
