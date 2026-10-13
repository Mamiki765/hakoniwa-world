<?php

namespace App\Application;

use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;

final class CommandQueueCompactor
{
    /** The caller owns the transaction, queue lock, version and audit updates. */
    public function compact(NationCommandQueue $queue): void
    {
        $items = NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)
            ->where('status', 'queued')
            ->orderBy('queue_position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        NationCommandQueueItem::query()->whereIn('id', $items->modelKeys())
            ->update(['queue_position' => null]);
        foreach ($items as $index => $item) {
            NationCommandQueueItem::query()->whereKey($item->id)
                ->update(['queue_position' => $index + 1]);
        }
    }
}
