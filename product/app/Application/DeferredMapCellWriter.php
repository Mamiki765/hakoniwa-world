<?php

namespace App\Application;

use App\Models\MapCell;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Batches the per-cell growth UPDATEs of the surface-cell pass.
 *
 * Deferred cells are written before the next query of any other kind, so
 * every read, lock, trigger or refresh() still sees exactly the rows a
 * per-cell save() would have left. Only audit-event inserts, which never
 * read map cells, may run while writes are pending.
 */
final class DeferredMapCellWriter
{
    private const BATCHED_COLUMNS = ['population', 'terrain_quantity', 'version'];

    private const BATCH_SIZE = 500;

    /** @var array<int, MapCell> */
    private array $pending = [];

    private bool $active = false;

    private bool $flushing = false;

    /** @var WeakMap<Connection, true>|null */
    private ?WeakMap $registeredConnections = null;

    public function begin(): void
    {
        $this->pending = [];
        $this->active = true;
        $connection = DB::connection();
        $this->registeredConnections ??= new WeakMap;
        if (! isset($this->registeredConnections[$connection])) {
            $this->registeredConnections[$connection] = true;
            $connection->beforeExecuting(function (string $query, array $bindings): void {
                if ($this->pending === [] || $this->flushing || $this->independentOfPendingCells($query, $bindings)) {
                    return;
                }
                $this->flush();
            });
        }
    }

    /**
     * Audit inserts and an Eloquent save() of another single cell neither read
     * nor overwrite pending rows (the map_cells ship guard trigger reads ships
     * and definitions only), so they need not split the batch.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    private function independentOfPendingCells(string $query, array $bindings): bool
    {
        if (str_starts_with($query, 'insert into "audit_events" ')) {
            return true;
        }
        if (! str_starts_with($query, 'update "map_cells" set ') || ! str_ends_with($query, ' where "id" = ?')
            || str_contains($query, ' from ') || str_contains($query, 'select')) {
            return false;
        }
        $id = end($bindings);

        return is_int($id) && ! isset($this->pending[$id]);
    }

    public function defer(MapCell $cell): bool
    {
        if (! $this->active) {
            return false;
        }
        $this->pending[(int) $cell->id] = $cell;

        return true;
    }

    public function flush(): void
    {
        if ($this->pending === [] || $this->flushing) {
            return;
        }
        $cells = $this->pending;
        $this->pending = [];
        $this->flushing = true;
        try {
            $batched = [];
            foreach ($cells as $cell) {
                $dirty = array_keys($cell->getDirty());
                if ($dirty === []) {
                    continue;
                }
                if (array_diff($dirty, self::BATCHED_COLUMNS) !== []) {
                    $cell->save();

                    continue;
                }
                $batched[] = $cell;
            }
            if ($batched === []) {
                return;
            }
            $timestamp = $batched[0]->freshTimestamp();
            foreach (array_chunk($batched, self::BATCH_SIZE) as $chunk) {
                $bindings = [$timestamp];
                foreach ($chunk as $cell) {
                    array_push($bindings, $cell->id, $cell->population, $cell->terrain_quantity, $cell->version);
                }
                $values = implode(', ', array_fill(0, count($chunk), '(?::bigint, ?::bigint, ?::bigint, ?::bigint)'));
                DB::update(
                    'update "map_cells" as c set "population" = v.population, '
                    .'"terrain_quantity" = v.terrain_quantity, "version" = v.version, "updated_at" = ? '
                    ."from (values {$values}) as v(id, population, terrain_quantity, version) where c.id = v.id",
                    $bindings,
                );
            }
            foreach ($batched as $cell) {
                $cell->setUpdatedAt($timestamp);
                $cell->syncOriginal();
            }
        } finally {
            $this->flushing = false;
        }
    }

    /** Drop pending writes after a failed attempt; its transaction rolls back. */
    public function end(): void
    {
        $this->pending = [];
        $this->active = false;
    }
}
