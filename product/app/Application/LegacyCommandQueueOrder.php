<?php

namespace App\Application;

use App\Models\NationCommandQueueItem;
use Illuminate\Database\Eloquent\Collection;

final class LegacyCommandQueueOrder
{
    private const POSITION_OFFSET = 1000;

    public const DISCARD_REASON = 'legacy_staged_position_discarded';

    /**
     * @param  Collection<int, NationCommandQueueItem>  $items
     * @return Collection<int, NationCommandQueueItem>
     */
    public function discard(Collection $items): Collection
    {
        $legacyStaged = $items->filter(
            static fn (NationCommandQueueItem $item): bool => $item->queue_position !== null
                && $item->queue_position > self::POSITION_OFFSET,
        );

        foreach ($legacyStaged as $item) {
            $originalPosition = (int) $item->queue_position;
            $metadata = $item->failure_metadata ?? [];
            $item->update([
                'status' => 'cancelled',
                'queue_position' => null,
                'cancelled_at' => now(),
                'failure_metadata' => [
                    ...$metadata,
                    'reason' => self::DISCARD_REASON,
                    'original_queue_position' => $originalPosition,
                ],
            ]);
            $item->setAttribute('legacy_original_queue_position', $originalPosition);
        }

        return $legacyStaged->values();
    }

    /**
     * @param  Collection<int, NationCommandQueueItem>  $items
     * @return Collection<int, NationCommandQueueItem>
     */
    public function project(Collection $items): Collection
    {
        $visible = $items->reject(
            static fn (NationCommandQueueItem $item): bool => $item->queue_position !== null
                && $item->queue_position > self::POSITION_OFFSET,
        )->values();
        if ($visible->count() === $items->count()) {
            return $items;
        }

        return $visible->map(static function (NationCommandQueueItem $item, int $index): NationCommandQueueItem {
            $projected = clone $item;
            $projected->setAttribute('queue_position', $index + 1);
            $projected->syncOriginal();

            return $projected;
        });
    }
}
