<?php

namespace Tests\Feature;

use App\Application\DeferredMapCellWriter;
use App\Models\MapCell;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class DeferredMapCellWriterTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_pending_cells_are_written_before_any_read(): void
    {
        $world = $this->lightweightWorld();
        [$first, $second] = MapCell::query()
            ->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->orderBy('id')->limit(2)->get()->all();
        $writer = new DeferredMapCellWriter;
        $writer->begin();

        $first->population = 1_234;
        $first->version++;
        $this->assertTrue($writer->defer($first));
        $second->population = 4_321;
        $second->version++;
        $writer->defer($second);
        DB::table('audit_events')->insert([
            'event_type' => 'test.deferred', 'metadata' => '{}', 'occurred_at' => now(),
        ]);
        // Audit inserts never read map cells, so they leave the batch pending.
        $this->assertTrue($first->isDirty('population'));

        $this->assertSame(1_234, (int) MapCell::query()->whereKey($first->id)->value('population'));
        $this->assertSame(4_321, (int) MapCell::query()->whereKey($second->id)->value('population'));
        $this->assertFalse($first->isDirty());
        $this->assertFalse($second->isDirty());
        $writer->end();
    }

    public function test_end_discards_pending_cells_and_stops_deferring(): void
    {
        $world = $this->lightweightWorld();
        $cell = MapCell::query()
            ->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->orderBy('id')->firstOrFail();
        $before = (int) $cell->population;
        $writer = new DeferredMapCellWriter;
        $writer->begin();
        $cell->population = $before + 5;
        $writer->defer($cell);
        $writer->end();

        $this->assertSame($before, (int) MapCell::query()->whereKey($cell->id)->value('population'));
        $this->assertFalse($writer->defer($cell));
    }

    public function test_repeated_resolution_uses_one_connection_hook_and_flushes_each_attempt(): void
    {
        $world = $this->lightweightWorld();
        $cell = MapCell::query()
            ->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->orderBy('id')->firstOrFail();
        $population = (int) $cell->population;
        $connection = DB::connection();
        $callbacks = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
        $before = count($callbacks->getValue($connection));

        // Re-resolving a pipeline in one worker must not retain another query
        // listener per attempt on its long-lived database connection.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $writer = app(DeferredMapCellWriter::class);
            $writer->begin();
            try {
                $cell->population = $population + $attempt;
                $cell->version++;
                $this->assertTrue($writer->defer($cell));
                $this->assertSame($population + $attempt, (int) MapCell::query()->whereKey($cell->id)->value('population'));
            } finally {
                $writer->end();
            }
        }

        $this->assertCount($before + 1, $callbacks->getValue($connection));
    }
}
