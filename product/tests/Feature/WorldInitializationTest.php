<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Domain\Hex\ChunkCoordinateService;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\ResourceDefinition;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WorldInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_world_initializes_as_3600_unowned_ocean_cells_and_is_idempotent(): void
    {
        $generator = app(OceanWorldGenerator::class);
        $generator->initialize();
        $generator->initialize();

        $this->assertSame(1, World::query()->count());
        $this->assertSame(1, MapSpace::query()->count());
        $this->assertSame(3600, MapCell::query()->count());
        $this->assertSame(3600, DB::table('map_cells')->join('terrain_definitions', 'terrain_definitions.id', '=', 'map_cells.terrain_definition_id')->where('terrain_definitions.key', 'sea')->count());
        $this->assertSame(0, MapCell::query()->whereNotNull('owner_nation_id')->count());
        $this->assertSame(0, MapCell::query()->whereNotNull('facility_definition_id')->count());
        $this->assertSame(0, MapCell::query()->where('population', '>', 0)->count());
        $this->assertSame(0, Nation::query()->count());
        $this->assertSame(0, NationCapital::query()->count());
        $this->assertSame(['fish', 'industrial_goods', 'minerals', 'monster_meat', 'wheat'], ResourceDefinition::query()->orderBy('key')->pluck('key')->all());
        $this->assertSame(2.0, ResourceDefinition::query()->where('key', 'monster_meat')->value('nutrition_per_unit'));
        $this->assertTrue(Schema::hasColumns('resource_definitions', [
            'unit', 'nutrition_per_unit', 'storable', 'tradable', 'sale_price_key', 'metadata',
        ]));
        $this->assertFalse(Schema::hasColumn('nations', 'food'));
    }

    public function test_failure_rolls_back_world_and_cells(): void
    {
        $generator = new class(app(ChunkCoordinateService::class)) extends OceanWorldGenerator
        {
            protected function afterBatchInserted(int $inserted): void
            {
                throw new RuntimeException('injected failure');
            }
        };

        try {
            $generator->initialize();
            $this->fail('Expected injected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected failure', $exception->getMessage());
        }

        $this->assertSame(0, World::query()->count());
        $this->assertSame(0, MapCell::query()->count());
    }
}
