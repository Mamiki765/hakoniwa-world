<?php

namespace Tests\Feature;

use App\Application\MapSpaceCoveragePreflight;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapCell;
use App\Models\MapChunk;
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
        $this->assertSame(1, World::query()->value('current_turn'));
        $this->assertSame(1, MapSpace::query()->count());
        $this->assertSame(3600, MapCell::query()->count());
        $this->assertSame('staggered_square_offset', MapSpace::query()->value('coordinate_system'));
        $this->assertSame(
            ['min_x' => 0, 'max_x' => 59, 'min_y' => 0, 'max_y' => 59],
            MapSpace::query()->firstOrFail()->only(['min_x', 'max_x', 'min_y', 'max_y']),
        );
        $this->assertSame(range(0, 59), MapCell::query()->distinct()->orderBy('y')->pluck('y')->all());
        $this->assertSame(60, DB::table('map_cells')->select('y')->groupBy('y')->havingRaw('COUNT(*) = 60')->get()->count());
        $this->assertSame(range(0, 59), MapCell::query()->where('y', 20)->orderBy('x')->pluck('x')->all());
        $this->assertSame(3600, DB::table('map_cells')->select(['map_space_id', 'x', 'y'])->distinct()->get()->count());
        $this->assertSame(16, MapChunk::query()->count());
        $this->assertSame(range(0, 3), MapChunk::query()->distinct()->orderBy('chunk_x')->pluck('chunk_x')->all());
        $this->assertSame(range(0, 3), MapChunk::query()->distinct()->orderBy('chunk_y')->pluck('chunk_y')->all());
        $this->assertSame(0, DB::table('map_cells')
            ->whereColumn('chunk_x', '!=', DB::raw('FLOOR(x / 16.0)'))
            ->orWhereColumn('chunk_y', '!=', DB::raw('FLOOR(y / 16.0)'))
            ->count());
        $this->assertSame(0, MapCell::query()->whereNotBetween('local_x', [0, 15])->count());
        $this->assertSame(0, MapCell::query()->whereNotBetween('local_y', [0, 15])->count());
        $this->assertSame(3600, DB::table('map_cells')->join('terrain_definitions', 'terrain_definitions.id', '=', 'map_cells.terrain_definition_id')->where('terrain_definitions.key', 'sea')->count());
        $this->assertSame(0, MapCell::query()->whereNotNull('owner_nation_id')->count());
        $this->assertSame(0, MapCell::query()->whereNotNull('facility_definition_id')->count());
        $this->assertSame(0, MapCell::query()->where('population', '>', 0)->count());
        $this->assertSame(0, Nation::query()->count());
        $this->assertSame(0, NationCapital::query()->count());
        $this->assertSame(['fish', 'industrial_goods', 'minerals', 'monster_meat', 'wheat'], ResourceDefinition::query()->orderBy('key')->pluck('key')->all());
        $this->assertSame(2.0, ResourceDefinition::query()->where('key', 'monster_meat')->value('nutrition_per_unit'));
        $this->assertTrue(Schema::hasColumns('resource_definitions', [
            'unit', 'unit_label', 'nutrition_per_unit', 'storable', 'tradable', 'sale_price_key', 'metadata',
        ]));
        $this->assertSame('ton', ResourceDefinition::query()->where('key', 'wheat')->value('unit'));
        $this->assertSame('トン', ResourceDefinition::query()->where('key', 'wheat')->value('unit_label'));
        $this->assertFalse(Schema::hasColumn('nations', 'food'));
    }

    public function test_failure_rolls_back_world_and_cells(): void
    {
        $generator = new class(app(ChunkCoordinateService::class), app(RulesetPublisher::class), app(CurrentRulesetGuard::class), app(MapSpaceCoveragePreflight::class)) extends OceanWorldGenerator
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

    public function test_debug_profile_generates_32_by_32_without_changing_published_ruleset_settings(): void
    {
        $configuredRuleset = config('hakoniwa.ruleset');
        $published = app(RulesetPublisher::class)->publish($configuredRuleset);
        $settingsFingerprint = hash('sha256', (string) $published->getRawOriginal('settings'));
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $worldRuleset = $world->rulesetVersion()->firstOrFail();

        $this->assertSame($published->id, $worldRuleset->id);
        $this->assertSame('hakoniwa-2s-plus-v11', $worldRuleset->key);
        $this->assertSame($settingsFingerprint, hash('sha256', (string) $worldRuleset->getRawOriginal('settings')));
        $this->assertSame(59, $worldRuleset->settings['initial_x_max']);
        $this->assertSame(59, $worldRuleset->settings['initial_y_max']);
        $this->assertArrayNotHasKey('generation_profile', $worldRuleset->settings);
        $this->assertSame(
            ['min_x' => 0, 'max_x' => 31, 'min_y' => 0, 'max_y' => 31],
            $mapSpace->only(['min_x', 'max_x', 'min_y', 'max_y']),
        );
        $this->assertSame(1024, $mapSpace->cells()->count());
        $this->assertSame(4, $mapSpace->chunks()->count());
        $this->assertSame(range(0, 31), $mapSpace->cells()->distinct()->orderBy('x')->pluck('x')->all());
        $this->assertSame(range(0, 31), $mapSpace->cells()->distinct()->orderBy('y')->pluck('y')->all());
        $this->assertSame(
            [256, 256, 256, 256],
            DB::table('map_cells')->where('map_space_id', $mapSpace->id)
                ->selectRaw('map_chunk_id, COUNT(*) AS cell_count')
                ->groupBy('map_chunk_id')
                ->orderBy('map_chunk_id')
                ->pluck('cell_count')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all(),
        );
        $this->assertSame(
            ['chunk_x' => 0, 'chunk_y' => 0, 'local_x' => 15, 'local_y' => 15],
            MapCell::query()->where('map_space_id', $mapSpace->id)->where('x', 15)->where('y', 15)
                ->firstOrFail()->only(['chunk_x', 'chunk_y', 'local_x', 'local_y']),
        );
        $this->assertSame(
            ['chunk_x' => 1, 'chunk_y' => 1, 'local_x' => 0, 'local_y' => 0],
            MapCell::query()->where('map_space_id', $mapSpace->id)->where('x', 16)->where('y', 16)
                ->firstOrFail()->only(['chunk_x', 'chunk_y', 'local_x', 'local_y']),
        );
        $this->assertSame(
            ['chunk_x' => 1, 'chunk_y' => 1, 'local_x' => 15, 'local_y' => 15],
            MapCell::query()->where('map_space_id', $mapSpace->id)->where('x', 31)->where('y', 31)
                ->firstOrFail()->only(['chunk_x', 'chunk_y', 'local_x', 'local_y']),
        );
    }

    public function test_standard_world_init_remains_idempotent_for_an_existing_debug_world(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
        $generationRunCount = DB::table('world_generation_runs')->count();

        $this->artisan('hakoniwa:world:init')
            ->expectsOutputToContain('ready with 1024 ocean cells')
            ->assertSuccessful();

        $this->assertSame($world->id, World::query()->where('key', $world->key)->value('id'));
        $this->assertSame(1024, MapCell::query()->count());
        $this->assertSame($generationRunCount, DB::table('world_generation_runs')->count());
    }
}
