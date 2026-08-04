<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCommandQueueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoordinateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_and_remigrate_preserve_ids_and_backfill_every_coordinate_owner(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '座標移行国', '試験島主');
        $mapSpace = MapSpace::query()->firstOrFail();
        $target = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $queued = app(CommandQueueService::class)->add(
            $user,
            $nation,
            $mapSpace,
            'land_clear',
            $target->x,
            $target->y,
            (string) Str::uuid(),
            1,
        )['item'];
        $cellId = $target->id;
        $capitalId = $nation->capital->id;
        $coordinate = [$target->x, $target->y];
        $migration = require database_path('migrations/2026_07_26_020000_replace_axial_coordinates_with_staggered_xy.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('map_cells', 'x'));
        $this->assertTrue(Schema::hasColumns('map_cells', ['q', 'r', 'chunk_q', 'chunk_r', 'local_q', 'local_r']));
        $this->assertFalse(Schema::hasColumn('nation_command_queue_items', 'target_x'));
        $this->assertSame(3600, DB::table('map_cells')->count());

        $migration->up();
        $this->assertTrue(Schema::hasColumns('map_cells', ['x', 'y', 'chunk_x', 'chunk_y', 'local_x', 'local_y']));
        $this->assertFalse(Schema::hasColumn('map_cells', 'q'));
        $this->assertFalse(Schema::hasColumn('map_spaces', 'min_q'));
        $this->assertFalse(Schema::hasColumn('nation_capitals', 'q'));
        $this->assertFalse(Schema::hasColumn('nation_creation_requests', 'reserved_q'));
        $this->assertFalse(Schema::hasColumn('nation_command_queue_items', 'target_q'));
        $this->assertSame($coordinate, array_values((array) DB::table('map_cells')->where('id', $cellId)->first(['x', 'y'])));
        $this->assertSame($capitalId, DB::table('nation_capitals')->value('id'));
        $this->assertSame($coordinate, [
            NationCommandQueueItem::query()->findOrFail($queued->id)->target_x,
            NationCommandQueueItem::query()->findOrFail($queued->id)->target_y,
        ]);
        $this->assertSame(0, DB::table('map_cells')->whereColumn('map_cells.chunk_x', '!=', DB::raw('FLOOR(map_cells.x / 16.0)'))->count());
        $this->assertSame(0, DB::table('map_cells')->whereNotBetween('local_x', [0, 15])->count());
        $this->assertSame(0, DB::table('map_cells')->whereNotBetween('local_y', [0, 15])->count());
        $this->assertSame(3600, DB::table('map_cells')->select(['map_space_id', 'x', 'y'])->distinct()->get()->count());

        $audit = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.queued')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($coordinate[0], $audit['x']);
        $this->assertSame($coordinate[1], $audit['y']);
        $this->assertArrayNotHasKey('q', $audit);
        $this->assertArrayNotHasKey('r', $audit);
    }
}
