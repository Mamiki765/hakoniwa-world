<?php

namespace Tests\Feature;

use App\Application\DisasterTurnService;
use App\Application\SeaAreaWeatherService;
use App\Application\WorldExpansionService;
use App\Domain\Disaster\HugeMeteorWeatherDistribution;
use App\Domain\Disaster\SeaAreaWeatherLottery;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Domain\World\MapBounds;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class SeaAreaWeatherTurnTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_partial_terminal_weather_replays_its_center_and_damage_without_an_old_global_draw(): void
    {
        $world = $this->lightweightWorld();
        $space = $this->surfaceMapSpace($world);
        $space->update(['max_x' => 0, 'max_y' => 0]);
        $target = MapCell::query()->where('map_space_id', $space->id)->where('x', 0)->where('y', 0)->sole();
        app(MapCellStateService::class)->transitionTerrain($target, TerrainDefinition::query()->where('key', 'plain')->sole());
        $target->save();
        $ruleset = $world->rulesetVersion()->sole();
        $settings = $ruleset->settings;
        foreach (['earthquake', 'tsunami', 'eruption'] as $key) {
            $settings['turn_processing']['disasters'][$key]['probability'] = ['numerator' => 0, 'denominator' => 1];
        }
        // Raise only the legacy expectation input for this small fixture, retaining real padding/area math.
        $settings['turn_processing']['disasters']['huge_meteor']['probability'] = ['numerator' => 1, 'denominator' => 1];
        $ruleset->update(['settings' => $settings]);
        $weather = $settings['turn_processing']['sea_area_weather'];
        $distribution = new HugeMeteorWeatherDistribution($space->currentBounds(), 2, ['numerator' => 1, 'denominator' => 1]);
        $lottery = new SeaAreaWeatherLottery;
        $seed = null;
        for ($candidate = 0; $candidate < 100000; $candidate++) {
            $trial = hash('sha256', "partial-terminal:{$candidate}");
            $random = new TurnRandomStreamFactory($trial);
            if ($lottery->draw($weather, $distribution->internalProbability(1), $random->stream(TurnRandomStreamFactory::seaAreaWeather(0, 0, 1))) !== 'huge_meteor'
                || $distribution->drawOutsideCount($random->stream(TurnRandomStreamFactory::weatherHugeMeteorOutside(1))) !== 0
                || $random->stream(TurnRandomStreamFactory::worldDisasterAreaFraction('huge_meteor'))->integer(0, 224) >= 16) {
                continue;
            }
            // If the old path were retained, this seed would admit an extra guaranteed huge meteor.
            $seed = $trial;
            break;
        }
        $this->assertNotNull($seed);
        $first = $this->context($world, $seed);
        $beforeCellCount = MapCell::query()->where('map_space_id', $space->id)->count();
        $beforeOutside = MapCell::query()->where('map_space_id', $space->id)->where('x', 1)->where('y', 0)->sole()->getAttributes();
        DB::beginTransaction();
        try {
            $firstMetrics = app(DisasterTurnService::class)->executeGlobal($first);
            $records = $first->state->seaAreaWeather();
            $centers = $first->state->weatherHugeMeteorCenters();
            $this->assertSame('huge_meteor', MapChunk::query()->findOrFail($target->map_chunk_id)->weather_key);
            $this->assertSame('sea', $target->fresh()->terrain()->value('key'));
        } finally {
            DB::rollBack();
        }
        $this->assertNull(MapChunk::query()->findOrFail($target->map_chunk_id)->weather_key);
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
        $retry = new TurnContext($world, $first->run, $ruleset->fresh(), 2, $seed, new TurnRandomStreamFactory($seed), new TurnState);
        $this->assertSame($firstMetrics, app(DisasterTurnService::class)->executeGlobal($retry));
        $this->assertSame($records, $retry->state->seaAreaWeather());
        $this->assertEquals($centers, $retry->state->weatherHugeMeteorCenters());
        $this->assertEquals([new GridCoordinate(0, 0)], $centers);
        $this->assertSame(1, $firstMetrics['executed_disasters']);
        $this->assertSame($beforeCellCount, MapCell::query()->where('map_space_id', $space->id)->count());
        $this->assertSame($beforeOutside, MapCell::query()->where('map_space_id', $space->id)->where('x', 1)->where('y', 0)->sole()->getAttributes());
        $triggers = DB::table('audit_events')->where('event_type', 'disaster.triggered')->pluck('metadata');
        $this->assertCount(1, $triggers);
        $trigger = json_decode($triggers[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('huge_meteor', $trigger['disaster_key']);
        $this->assertArrayNotHasKey('sea_area_name', $trigger);
        $this->assertArrayNotHasKey('world_opportunity_index', $trigger);
    }

    public function test_terminal_and_outside_blasts_cross_area_edges_and_clip_without_cell_lookup_queries(): void
    {
        $world = $this->lightweightWorld();
        $space = $this->surfaceMapSpace($world);
        $ruleset = $world->rulesetVersion()->sole();
        $settings = $ruleset->settings;
        foreach (['earthquake', 'tsunami', 'eruption'] as $key) {
            $settings['turn_processing']['disasters'][$key]['probability'] = ['numerator' => 0, 'denominator' => 1];
        }
        $ruleset->update(['settings' => $settings]);
        $plain = TerrainDefinition::query()->where('key', 'plain')->sole();
        foreach ([[15, 15], [16, 15], [0, 0]] as [$x, $y]) {
            $cell = MapCell::query()->where('map_space_id', $space->id)->where('x', $x)->where('y', $y)->sole();
            app(MapCellStateService::class)->transitionTerrain($cell, $plain);
            $cell->save();
        }
        $context = $this->context($world, str_repeat('cd', 32));
        $context->state->setSeaAreaWeather([]);
        $context->state->setWeatherHugeMeteorCenters([new GridCoordinate(15, 15), new GridCoordinate(-1, 0)]);
        $beforeCount = MapCell::query()->where('map_space_id', $space->id)->count();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $metrics = app(DisasterTurnService::class)->executeGlobal($context);
        $this->assertSame(2, $metrics['executed_disasters']);
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "map_cells"') && str_contains($sql, '"x" = ?') && str_contains($sql, '"y" = ?'))));
        $blastLoads = array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select * from "map_cells"') && str_contains($sql, 'for update'));
        $this->assertCount(1, $blastLoads);
        $shipLoads = array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select * from "ships"'));
        $this->assertCount(1, $shipLoads);
        foreach ([[16, 15], [0, 0]] as [$x, $y]) {
            $this->assertSame('shallow', MapCell::query()->where('map_space_id', $space->id)->where('x', $x)->where('y', $y)->sole()->terrain()->value('key'));
        }
        $this->assertSame($beforeCount, MapCell::query()->where('map_space_id', $space->id)->count());
        $this->assertFalse(MapCell::query()->where('map_space_id', $space->id)->where('x', '<', 0)->exists());
    }

    public function test_partial_area_meteor_repeats_a_real_cell_with_the_existing_half_continuation(): void
    {
        $world = $this->lightweightWorld();
        $space = $this->surfaceMapSpace($world);
        $space->update(['min_x' => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0]);
        $target = MapCell::query()->where('map_space_id', $space->id)->where('x', 0)->where('y', 0)->with(['terrain', 'facility'])->sole();
        $states = app(MapCellStateService::class);
        $states->transitionTerrain($target, TerrainDefinition::query()->where('key', 'plain')->sole());
        $states->setFacility($target, FacilityDefinition::query()->where('key', 'farm')->sole());
        $target->facility_scale = 51;
        $target->save();
        $ruleset = $world->rulesetVersion()->sole();
        $settings = $ruleset->settings;
        foreach (['earthquake', 'tsunami', 'huge_meteor', 'eruption'] as $key) {
            $settings['turn_processing']['disasters'][$key]['probability'] = ['numerator' => 0, 'denominator' => 1];
        }
        $ruleset->update(['settings' => $settings]);
        $weather = $settings['turn_processing']['sea_area_weather'];
        $lottery = new SeaAreaWeatherLottery;
        for ($candidate = 0; $candidate < 100000; $candidate++) {
            $seed = hash('sha256', "partial-meteor:{$candidate}");
            $random = new TurnRandomStreamFactory($seed);
            if ($lottery->draw($weather, ['numerator' => 0, 'denominator' => 1], $random->stream(TurnRandomStreamFactory::seaAreaWeather(0, 0, 1))) !== 'meteor_shower') {
                continue;
            }
            $effect = $random->stream(TurnRandomStreamFactory::seaAreaWeatherEffect('meteor_shower', 0, 0, 1));
            $effect->integer(0, 0);
            $firstContinue = $effect->integer(0, 1);
            $effect->integer(0, 0);
            if ($firstContinue === 0 && $effect->integer(0, 1) === 1) {
                break;
            }
        }
        $context = $this->context($world, $seed);
        $beforeCells = MapCell::query()->where('map_space_id', $space->id)->count();
        $result = app(DisasterTurnService::class)->executeGlobal($context);
        $this->assertSame(1, $result['executed_disasters']);
        $this->assertSame(2, $result['damaged_cells']);
        $this->assertSame('sea', $target->fresh()->terrain()->value('key'));
        $this->assertNull($target->fresh()->facility_definition_id);
        $this->assertSame('meteor_shower', MapChunk::query()->findOrFail($target->map_chunk_id)->weather_key);
        $this->assertSame($beforeCells, MapCell::query()->where('map_space_id', $space->id)->count());
        $trigger = json_decode(DB::table('audit_events')->where('event_type', 'disaster.triggered')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $trigger['min_x']);
        $this->assertSame(0, $trigger['max_x']);
        $this->assertArrayNotHasKey('world_opportunity_index', $trigger);
    }

    public function test_weather_rolls_back_replays_and_includes_new_partial_signed_chunks_with_bounded_queries(): void
    {
        $world = $this->lightweightWorld();
        $space = $this->surfaceMapSpace($world);
        $service = app(SeaAreaWeatherService::class);
        $seed = str_repeat('ab', 32);
        DB::beginTransaction();
        $first = $this->context($world, $seed);
        $this->assertSame(4, $service->draw($first, $space));
        $records = $first->state->seaAreaWeather();
        $this->assertGreaterThan(1, count(array_unique(array_column($records, 'weather_key'))));
        DB::rollBack();
        $this->assertSame(0, MapChunk::query()->where('map_space_id', $space->id)->whereNotNull('weather_turn')->count());
        $retry = $this->context($world, $seed);
        $service->draw($retry, $space);
        $this->assertSame($records, $retry->state->seaAreaWeather());
        $this->assertSame([2], MapChunk::query()->where('map_space_id', $space->id)->distinct()->pluck('weather_turn')->all());

        $space = app(WorldExpansionService::class)->expand($world, $this->boundsFor($world), new MapBounds(-16, 31, 0, 31, 16));
        $newChunk = MapChunk::query()->where('map_space_id', $space->id)->where('chunk_x', -1)->firstOrFail();
        $this->assertNull($newChunk->weather_key);
        $this->assertNull($newChunk->weather_turn);
        // A clipped World edge and an empty chunk must not produce cells or nonexistent weather areas.
        $space->update(['max_x' => 29]);
        MapChunk::query()->create(['map_space_id' => $space->id, 'chunk_x' => 2, 'chunk_y' => 0]);
        $beforeCells = MapCell::query()->where('map_space_id', $space->id)->count();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $expanded = $this->context($world, $seed);
        $queries = [];
        $this->assertSame(6, $service->draw($expanded, $space));
        $this->assertCount(2, $queries);
        $sameCenters = $expanded->state->weatherHugeMeteorCenters();
        $this->assertSame(6, $service->draw($expanded, $space));
        $this->assertCount(2, $queries);
        $this->assertSame($sameCenters, $expanded->state->weatherHugeMeteorCenters());
        $expandedRecords = $expanded->state->seaAreaWeather();
        $this->assertSame($expandedRecords[$newChunk->id]['weather_key'], $newChunk->fresh()->weather_key);
        $this->assertSame(2, $newChunk->fresh()->weather_turn);
        $this->assertSame(-16, $expandedRecords[$newChunk->id]['min_x']);
        $this->assertSame(-1, $expandedRecords[$newChunk->id]['max_x']);
        foreach ($records as $chunkId => $record) {
            $this->assertContains($expandedRecords[$chunkId]['weather_key'], ['sunny', 'cloudy', 'rain', 'snow', 'thunder', 'typhoon', 'meteor_shower', 'huge_meteor']);
            $this->assertLessThanOrEqual(29, $expandedRecords[$chunkId]['max_x']);
        }
        $this->assertSame($beforeCells, MapCell::query()->where('map_space_id', $space->id)->count());
    }

    private function context(World $world, string $seed): TurnContext
    {
        $ruleset = $world->rulesetVersion()->sole();
        $run = TurnRun::query()->create([
            'world_id' => $world->id, 'ruleset_version_id' => $ruleset->id, 'target_turn' => 2,
            'random_seed' => $seed, 'source' => 'manual', 'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN, 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => [],
        ]);

        return new TurnContext($world, $run, $ruleset, 2, $seed, new TurnRandomStreamFactory($seed), new TurnState);
    }
}
