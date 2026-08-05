<?php

namespace App\Domain\Turn;

use InvalidArgumentException;

final class TurnState
{
    /** @var list<int> */
    private array $stableNationIds = [];

    /** @var list<int> */
    private array $developmentNationIds = [];

    /** @var list<int> */
    private array $surfaceCellIds = [];

    /** @var array<int, int> */
    private array $seaEdgeByCellId = [];

    /** @var array<int, true> */
    private array $famineNationIds = [];

    /** @var array<int, true> */
    private array $attractionNationIds = [];

    /** @var array<int, true> */
    private array $changedMapChunkIds = [];

    /** @var array<int, array{population: int, farm_capacity: int, factory_capacity: int, mine_capacity: int, owned_land_cells: int}> */
    private array $nationAggregates = [];

    /** @var list<LaunchIntent> */
    private array $launchIntents = [];

    /** @param array<array-key, mixed> $nationIds */
    public function setStableNationIds(array $nationIds): void
    {
        $this->stableNationIds = $this->positiveIntegerList($nationIds, 'Stable Nation order');
    }

    /** @return list<int> */
    public function stableNationIds(): array
    {
        return $this->stableNationIds;
    }

    /** @param array<array-key, mixed> $nationIds */
    public function setDevelopmentNationIds(array $nationIds): void
    {
        $this->developmentNationIds = $this->positiveIntegerList($nationIds, 'Development Nation order');
    }

    /** @return list<int> */
    public function developmentNationIds(): array
    {
        return $this->developmentNationIds;
    }

    /** @param array<array-key, mixed> $cellIds */
    public function setSurfaceCellIds(array $cellIds): void
    {
        $this->surfaceCellIds = $this->positiveIntegerList($cellIds, 'Surface cell order');
    }

    /** @return list<int> */
    public function surfaceCellIds(): array
    {
        return $this->surfaceCellIds;
    }

    /** @param array<array-key, mixed> $seaEdgeByCellId */
    public function setSeaEdgeByCellId(array $seaEdgeByCellId): void
    {
        $validated = [];
        foreach ($seaEdgeByCellId as $cellId => $seaEdge) {
            if (! is_int($cellId) || $cellId < 1 || ! is_int($seaEdge) || $seaEdge < 0) {
                throw new InvalidArgumentException('Sea-edge context must map positive cell IDs to non-negative integers.');
            }
            $validated[$cellId] = $seaEdge;
        }
        $this->seaEdgeByCellId = $validated;
    }

    public function seaEdgeForCell(int $cellId): int
    {
        if (! array_key_exists($cellId, $this->seaEdgeByCellId)) {
            throw new InvalidArgumentException("Sea-edge context is missing cell {$cellId}.");
        }

        return $this->seaEdgeByCellId[$cellId];
    }

    public function markFamine(int $nationId): void
    {
        if ($nationId < 1) {
            throw new InvalidArgumentException('Famine Nation ID must be positive.');
        }
        $this->famineNationIds[$nationId] = true;
    }

    public function isFamine(int $nationId): bool
    {
        return isset($this->famineNationIds[$nationId]);
    }

    /** @return list<int> */
    public function famineNationIds(): array
    {
        return array_map('intval', array_keys($this->famineNationIds));
    }

    public function markAttraction(int $nationId): void
    {
        if ($nationId < 1) {
            throw new InvalidArgumentException('Attraction Nation ID must be positive.');
        }
        $this->attractionNationIds[$nationId] = true;
    }

    public function hasAttraction(int $nationId): bool
    {
        return isset($this->attractionNationIds[$nationId]);
    }

    public function markMapChunkChanged(int $mapChunkId): void
    {
        if ($mapChunkId < 1) {
            throw new InvalidArgumentException('Changed MapChunk ID must be positive.');
        }
        $this->changedMapChunkIds[$mapChunkId] = true;
    }

    /** @return list<int> */
    public function changedMapChunkIds(): array
    {
        $ids = array_map('intval', array_keys($this->changedMapChunkIds));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  array{population: int, farm_capacity: int, factory_capacity: int, mine_capacity: int, owned_land_cells: int}  $aggregate
     */
    public function setNationAggregate(int $nationId, array $aggregate): void
    {
        if ($nationId < 1 || min($aggregate) < 0) {
            throw new InvalidArgumentException('Nation aggregate values must be non-negative integers.');
        }
        $this->nationAggregates[$nationId] = $aggregate;
    }

    /** @return array<int, array{population: int, farm_capacity: int, factory_capacity: int, mine_capacity: int, owned_land_cells: int}> */
    public function nationAggregates(): array
    {
        return $this->nationAggregates;
    }

    public function registerLaunchIntent(
        mixed $nationId,
        mixed $definitionKey,
        mixed $targetX,
        mixed $targetY,
        mixed $requestedShots,
        mixed $queueItemId = null,
    ): LaunchIntent {
        $intent = new LaunchIntent($nationId, $definitionKey, $targetX, $targetY, $requestedShots, $queueItemId);
        $this->launchIntents[] = $intent;

        return $intent;
    }

    /** @return list<LaunchIntent> */
    public function launchIntents(): array
    {
        return $this->launchIntents;
    }

    /** @return list<LaunchIntent> */
    public function launchIntentsForNation(mixed $nationId): array
    {
        if (! is_int($nationId) || $nationId < 1) {
            throw new InvalidArgumentException('Launch intent Nation ID must be a positive integer.');
        }

        return array_values(array_filter(
            $this->launchIntents,
            static fn (LaunchIntent $intent): bool => $intent->nationId === $nationId,
        ));
    }

    public function consumeLaunchIntentShots(LaunchIntent $intent, mixed $shots): void
    {
        if (! in_array($intent, $this->launchIntents, true)) {
            throw new InvalidArgumentException('Launch intent does not belong to this turn state.');
        }

        $intent->consumeShots($shots);
    }

    /** @param array<array-key, mixed> $values
     * @return list<int>
     */
    private function positiveIntegerList(array $values, string $label): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException("{$label} must be a list.");
        }
        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException("{$label} must contain positive integers.");
            }
        }

        return $values;
    }
}
