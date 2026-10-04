<?php

namespace Tests\Feature;

use App\Application\DisasterTurnService;
use App\Application\SeaAreaWeatherService;
use App\Application\WorldExpansionService;
use App\Domain\Disaster\SeaAreaWeatherLottery;
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
            if ($lottery->select($weather, $random->stream(TurnRandomStreamFactory::seaAreaWeather(0, 0, 1))->integer(0, 9999)) !== 'meteor_shower') {
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
        $expandedRecords = $expanded->state->seaAreaWeather();
        $this->assertSame($expandedRecords[$newChunk->id]['weather_key'], $newChunk->fresh()->weather_key);
        $this->assertSame(2, $newChunk->fresh()->weather_turn);
        $this->assertSame(-16, $expandedRecords[$newChunk->id]['min_x']);
        $this->assertSame(-1, $expandedRecords[$newChunk->id]['max_x']);
        foreach ($records as $chunkId => $record) {
            $this->assertSame($record['weather_key'], $expandedRecords[$chunkId]['weather_key']);
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
