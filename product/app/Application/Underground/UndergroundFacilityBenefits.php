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
        return $this->count($nationId, 'underground_city')
            * $this->perFacility('underground_city', 'capital_maximum_population_bonus');
    }

    public function capitalMaximumBonusForTurn(TurnState $state, int $nationId): int
    {
        return $this->countForTurn($state, $nationId, 'underground_city')
            * $this->perFacility('underground_city', 'capital_maximum_population_bonus');
    }

    public function farmCapacityBonus(int $nationId): int
    {
        return $this->count($nationId, 'underground_farm')
            * $this->perFacility('underground_farm', 'farm_capacity_people');
    }

    public function farmCapacityBonusForTurn(TurnState $state, int $nationId): int
    {
        return $this->countForTurn($state, $nationId, 'underground_farm')
            * $this->perFacility('underground_farm', 'farm_capacity_people');
    }

    public function factoryCapacityPerFacility(): int
    {
        return $this->perFacility('underground_factory', 'factory_capacity_people');
    }

    public function factoryCapacityBonus(int $nationId): int
    {
        return $this->count($nationId, 'underground_factory') * $this->factoryCapacityPerFacility();
    }

    public function factoryCapacityBonusForTurn(TurnState $state, int $nationId): int
    {
        return $this->countForTurn($state, $nationId, 'underground_factory')
            * $this->factoryCapacityPerFacility();
    }

    /** @return list<array{id: int, layer: int, slot_index: int, facility_key: string}> */
    public function factoryFacilitiesForTurn(TurnState $state, int $nationId): array
    {
        return $this->facilitiesForTurn($state, $nationId, 'underground_factory');
    }

    /**
     * @param  list<int>  $nationIds
     * @return list<int>
     */
    public function missileBaseIdsForTurn(TurnState $state, array $nationIds): array
    {
        if (! $state->hasUndergroundFacilitySnapshots()) {
            return NationUndergroundFacility::query()
                ->whereIn('nation_id', $nationIds)
                ->where('facility_key', 'underground_missile_base')
                ->orderBy('nation_id')
                ->orderBy('layer')
                ->orderBy('slot_index')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        sort($nationIds, SORT_NUMERIC);
        $baseIds = [];
        foreach ($nationIds as $nationId) {
            foreach ($this->facilitiesForTurn($state, $nationId, 'underground_missile_base') as $facility) {
                $baseIds[] = $facility['id'];
            }
        }

        return $baseIds;
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, list<array{id: int, layer: int, slot_index: int, facility_key: string}>>
     */
    public function loadTurnSnapshots(array $nationIds): array
    {
        $snapshots = [];
        foreach ($nationIds as $nationId) {
            $snapshots[$nationId] = [];
        }
        if ($snapshots === []) {
            return [];
        }

        $facilities = NationUndergroundFacility::query()
            ->whereIn('nation_id', $nationIds)
            ->orderBy('nation_id')
            ->orderBy('layer')
            ->orderBy('slot_index')
            ->get(['id', 'nation_id', 'layer', 'slot_index', 'facility_key']);
        foreach ($facilities as $facility) {
            $snapshots[(int) $facility->nation_id][] = [
                'id' => (int) $facility->id,
                'layer' => (int) $facility->layer,
                'slot_index' => (int) $facility->slot_index,
                'facility_key' => (string) $facility->facility_key,
            ];
        }

        return $snapshots;
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, array{farm_capacity_people: int, factory_capacity_people: int}>
     */
    public function workforceCapacityBonuses(array $nationIds): array
    {
        $bonuses = [];
        foreach ($nationIds as $nationId) {
            $bonuses[$nationId] = [
                'farm_capacity_people' => 0,
                'factory_capacity_people' => 0,
            ];
        }
        if ($bonuses === []) {
            return [];
        }

        $rows = NationUndergroundFacility::query()
            ->selectRaw('nation_id, facility_key, COUNT(*) AS aggregate')
            ->whereIn('nation_id', $nationIds)
            ->whereIn('facility_key', ['underground_farm', 'underground_factory'])
            ->groupBy('nation_id', 'facility_key')
            ->get();
        foreach ($rows as $row) {
            $field = match ($row->facility_key) {
                'underground_farm' => 'farm_capacity_people',
                'underground_factory' => 'factory_capacity_people',
                default => throw new DomainException('Underground workforce facility key is invalid.'),
            };
            $effectKey = $row->facility_key === 'underground_farm'
                ? 'farm_capacity_people'
                : 'factory_capacity_people';
            $bonuses[$row->nation_id][$field] = (int) $row->getRawOriginal('aggregate')
                * $this->perFacility($row->facility_key, $effectKey);
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

    public function missileCapacityPerFacility(): int
    {
        return $this->perFacility('underground_missile_base', 'missile_launch_capacity');
    }

    private function count(int $nationId, string $facilityKey): int
    {
        return NationUndergroundFacility::query()
            ->where('nation_id', $nationId)
            ->where('facility_key', $facilityKey)
            ->count();
    }

    private function countForTurn(TurnState $state, int $nationId, string $facilityKey): int
    {
        return count($this->facilitiesForTurn($state, $nationId, $facilityKey));
    }

    /** @return list<array{id: int, layer: int, slot_index: int, facility_key: string}> */
    private function facilitiesForTurn(TurnState $state, int $nationId, string $facilityKey): array
    {
        if (! $state->hasUndergroundFacilitySnapshots()) {
            return NationUndergroundFacility::query()
                ->where('nation_id', $nationId)
                ->where('facility_key', $facilityKey)
                ->orderBy('layer')
                ->orderBy('slot_index')
                ->get(['id', 'layer', 'slot_index', 'facility_key'])
                ->map(static fn (NationUndergroundFacility $facility): array => [
                    'id' => (int) $facility->id,
                    'layer' => (int) $facility->layer,
                    'slot_index' => (int) $facility->slot_index,
                    'facility_key' => (string) $facility->facility_key,
                ])->all();
        }

        return array_values(array_filter(
            $state->undergroundFacilitySnapshotsForNation($nationId),
            static fn (array $facility): bool => $facility['facility_key'] === $facilityKey,
        ));
    }

    private function perFacility(string $facilityKey, string $effectKey): int
    {
        $value = $this->commands->forFacility($facilityKey)->effect[$effectKey] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new DomainException("Underground facility catalog effect {$facilityKey}.{$effectKey} is invalid.");
        }

        return $value;
    }
}
