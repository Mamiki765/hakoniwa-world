<?php

namespace App\Application;

use App\Models\NationCommandQueueItem;
use Illuminate\Database\Eloquent\Collection;

final class LegacyCommandQueueOrder
{
    private const POSITION_OFFSET = 1000;

    /**
     * @param  Collection<int, NationCommandQueueItem>  $items
     * @return Collection<int, NationCommandQueueItem>
     */
    public function recover(Collection $items): Collection
    {
        $legacyStaged = $items->filter(
            static fn (NationCommandQueueItem $item): bool => $item->queue_position !== null
                && $item->queue_position > self::POSITION_OFFSET,
        );
        if ($legacyStaged->isEmpty()) {
            return $items;
        }

        // The legacy compactor parked the unchanged prefix at its original
        // position + 1000. Visible rows are the shifted suffix or plans added
        // later, so recover the staged prefix before ordinary compaction.
        $legacyStaged = $legacyStaged->sort(
            static fn (NationCommandQueueItem $left, NationCommandQueueItem $right): int => [
                (int) $left->queue_position - self::POSITION_OFFSET,
                $left->id,
            ] <=> [
                (int) $right->queue_position - self::POSITION_OFFSET,
                $right->id,
            ],
        );
        $visible = $items->reject(
            static fn (NationCommandQueueItem $item): bool => $item->queue_position !== null
                && $item->queue_position > self::POSITION_OFFSET,
        );

        return $legacyStaged->values()->concat($visible->values())->values();
    }

    /**
     * @param  Collection<int, NationCommandQueueItem>  $items
     * @return Collection<int, NationCommandQueueItem>
     */
    public function project(Collection $items): Collection
    {
        $ordered = $this->recover($items);
        if ($ordered === $items) {
            return $items;
        }

        return $ordered->values()->map(static function (NationCommandQueueItem $item, int $index): NationCommandQueueItem {
            $projected = clone $item;
            $projected->setAttribute('queue_position', $index + 1);
            $projected->syncOriginal();

            return $projected;
        });
    }
}
