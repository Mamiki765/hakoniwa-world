<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\DisasterMutableCellIndex;
use App\Application\DisasterTurnService;
use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\SecretaryTurnService;
use App\Application\WorldExpansionService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\DeterministicRandomStream;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Domain\World\MapBounds;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class DisasterAndOilTurnTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    /** @var list<string> */
    private const GLOBAL_KEYS = [
        'earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption',
    ];

    public function test_world_disaster_opportunities_scale_exactly_with_chunk_count(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '面積補正国',
            '面積補正島主',
        );
        $space = app(WorldExpansionService::class)->expand(
            $world,
            new MapBounds(0, 59, 0, 59, 16),
            new MapBounds(0, 63, 0, 63, 16),
        );
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $originalSettings = $ruleset->settings;
        $ruleset = $this->forceGlobal($ruleset, 'earthquake');
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForAreaGate('earthquake', 31, false),
            [$nation->id],
        );

        $first = app(DisasterTurnService::class)->executeGlobal($context);
        $firstEvent = $this->events($run, 'disaster.triggered');

        $this->assertSame(16, $space->currentBounds()->chunkCount());
        $this->assertSame(1, $first['executed_disasters']);
        $this->assertCount(1, $firstEvent);
        $this->assertSame(256, $firstEvent[0]['world_scale_numerator']);
        $this->assertSame(225, $firstEvent[0]['world_scale_denominator']);
        $this->assertSame('integer', $firstEvent[0]['world_opportunity_kind']);

        $ruleset->settings = $originalSettings;
        $ruleset->save();
        $space = app(WorldExpansionService::class)->expand(
            $world->fresh(),
            new MapBounds(0, 63, 0, 63, 16),
            new MapBounds(-16, 63, 0, 63, 16),
        );
        $ruleset = $this->forceGlobal($ruleset->fresh(), 'earthquake');
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForAreaGate('earthquake', 95, true),
            [$nation->id],
        );

        $second = app(DisasterTurnService::class)->executeGlobal($context);
        $secondEvents = $this->events($run, 'disaster.triggered');

        $this->assertSame(20, $space->currentBounds()->chunkCount());
        $this->assertSame(2, $second['executed_disasters']);
        $this->assertCount(2, $secondEvents);
        $this->assertSame([320, 320], array_column($secondEvents, 'world_scale_numerator'));
        $this->assertSame(['integer', 'fractional'], array_column($secondEvents, 'world_opportunity_kind'));
        $this->assertLessThan(95, $secondEvents[1]['world_fractional_gate_draw']);
    }

    public function test_signed_world_disaster_center_uses_negative_bounds_and_clips_neighbors(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '負座標災害国',
            '負座標島主',
        );
        $expansion = app(WorldExpansionService::class);
        $expansion->expand($world, new MapBounds(0, 59, 0, 59, 16), new MapBounds(0, 63, 0, 63, 16));
        $expansion->expand($world->fresh(), new MapBounds(0, 63, 0, 63, 16), new MapBounds(-16, 63, 0, 63, 16));
        $space = $expansion->expand(
            $world->fresh(),
            new MapBounds(-16, 63, 0, 63, 16),
            new MapBounds(-16, 63, -16, 63, 16),
        );
        $ruleset = $this->forceGlobal($world->rulesetVersion()->firstOrFail(), 'eruption');
        $center = new GridCoordinate(-16, -16);
        $this->setCell($this->cellAt($space, $center->x, $center->y), 'sea', null, null, 0);
        $seed = $this->seedForCenter(
            TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER,
            $center->x,
            $center->y,
            $space,
        );
        [$context, $run] = $this->context($world, $ruleset, $seed, [$nation->id]);

        app(DisasterTurnService::class)->executeGlobal($context);

        $event = $this->event($run, 'disaster.triggered');
        $this->assertSame(-16, $event['center_x']);
        $this->assertSame(-16, $event['center_y']);
        $this->assertSame('mountain', $this->cellAt($space, -16, -16)->terrain()->value('key'));
        $this->assertFalse(MapCell::query()->where('map_space_id', $space->id)
            ->where(fn ($query) => $query->where('x', '<', -16)->orWhere('y', '<', -16)
                ->orWhere('x', '>', 63)->orWhere('y', '>', 63))->exists());
        $this->assertSame(6_400, MapCell::query()->where('map_space_id', $space->id)->count());
    }

    public function test_same_seed_retry_replays_scaled_disaster_opportunities_centers_and_effects(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $ruleset = $this->forceGlobal($world->rulesetVersion()->firstOrFail(), 'eruption');
        $seed = $this->seedForAreaGate('eruption', 31, true);
        [$firstContext, $run] = $this->context($world, $ruleset, $seed, []);

        DB::beginTransaction();
        try {
            $firstMetrics = app(DisasterTurnService::class)->executeGlobal($firstContext);
            $firstEvents = $this->turnEvents($run);
            $firstCells = $this->cellState($world);
        } finally {
            DB::rollBack();
        }

        $retryState = new TurnState;
        $retryContext = new TurnContext(
            $world->fresh(),
            $run->fresh(),
            $ruleset->fresh(),
            2,
            $seed,
            new TurnRandomStreamFactory($seed),
            $retryState,
        );
        $retryMetrics = app(DisasterTurnService::class)->executeGlobal($retryContext);

        $this->assertSame(2, $retryMetrics['executed_disasters']);
        $this->assertSame($firstMetrics, $retryMetrics);
        $this->assertSame($firstEvents, $this->turnEvents($run));
        $this->assertSame($firstCells, $this->cellState($world));
    }

    public function test_each_global_disaster_applies_its_normal_cell_contract_at_a_fixed_center(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('通常災害国');
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        $cases = [
            'earthquake' => ['plain', 'city', 10_000, 'wasteland', $nation->id],
            'tsunami' => ['plain', 'factory', 0, 'wasteland', $nation->id],
            'typhoon' => ['plain', 'farm', 0, 'plain', $nation->id],
            'meteor_shower' => ['shallow', null, 0, 'sea', $nation->id],
            'huge_meteor' => ['plain', null, 0, 'sea', null],
            'eruption' => ['plain', null, 0, 'mountain', $nation->id],
        ];

        foreach ($cases as $key => [$terrain, $facility, $population, $expectedTerrain, $expectedOwner]) {
            $this->setCell($target, $terrain, $facility, $nation->id, $population);
            $ruleset = $this->forceGlobal($ruleset, $key);
            $seed = $this->seedForCenter($this->centerLabel($key), $center->x, $center->y, $space);
            [$context, $run] = $this->context($world, $ruleset, $seed, [$nation->id]);

            $result = app(DisasterTurnService::class)->executeGlobal($context);
            $changed = $target->fresh(['terrain', 'facility']);

            $this->assertSame(1, $result['executed_disasters'], $key);
            $this->assertSame($expectedTerrain, $changed->terrain->key, $key);
            $this->assertNull($changed->facility_definition_id, $key);
            $this->assertSame($expectedOwner, $changed->owner_nation_id, $key);
            $this->assertSame(1, DB::table('audit_events')->where('event_type', 'disaster.triggered')
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count(), $key);
            $metadata = $this->event($run, 'disaster.triggered');
            $this->assertSame($key, $metadata['disaster_key']);
            $this->assertSame($center->x, $metadata['center_x']);
            $this->assertSame($center->y, $metadata['center_y']);
        }
    }

    public function test_eruption_uses_adr_directions_and_never_creates_world_outside_cells(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('端災害国');
        $ruleset = $this->forceGlobal($ruleset, 'eruption');
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$x, $y]) {
            $this->setCell($this->cellAt($space, $x, $y), 'sea', null, null, 0);
        }
        [$context] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER, 0, 0, $space),
            [$nation->id],
        );

        app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame('mountain', $this->cellAt($space, 0, 0)->terrain()->value('key'));
        foreach ([[1, 0], [0, 1], [1, 1]] as [$x, $y]) {
            $this->assertSame('shallow', $this->cellAt($space, $x, $y)->terrain()->value('key'));
        }
        $bounds = $this->boundsFor($world);
        $this->assertSame($bounds->cellCount(), MapCell::query()->where('map_space_id', $space->id)->count());
        $this->assertFalse(MapCell::query()->where('map_space_id', $space->id)
            ->where(fn ($query) => $query->where('x', '<', 0)->orWhere('y', '<', 0)
                ->orWhere('x', '>', $bounds->maxX)->orWhere('y', '>', $bounds->maxY))->exists());
        $this->assertSame(
            [
                GridCoordinate::EAST => [1, 0],
                GridCoordinate::NORTH_EAST => [1, -1],
                GridCoordinate::NORTH_WEST => [0, -1],
                GridCoordinate::WEST => [-1, 0],
                GridCoordinate::SOUTH_WEST => [0, 1],
                GridCoordinate::SOUTH_EAST => [1, 1],
            ],
            collect(array_keys(GridCoordinate::DIRECTION_NAMES))->mapWithKeys(static function (int $direction): array {
                $neighbor = (new GridCoordinate(0, 0))->neighbor($direction);

                return [$direction => [$neighbor->x, $neighbor->y]];
            })->all(),
        );
    }

    public function test_expanded_world_center_bounds_must_fit_the_deterministic_stream_range(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('拡張境界国');
        $ruleset = $this->forceGlobal($ruleset, 'earthquake');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['disasters']['earthquake']['center_padding'] = 1;
        });
        $space->update(['max_x' => DeterministicRandomStream::MAXIMUM_INTEGER]);
        [$context] = $this->context($world, $ruleset, hash('sha256', 'expanded-center-bound'), [$nation->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Disaster center draw bounds must fit signed 32-bit integers after World expansion.');

        app(DisasterTurnService::class)->executeGlobal($context);
    }

    public function test_capital_damage_is_sequential_clamped_and_identity_preserving(): void
    {
        [$world, $nation, $ruleset] = $this->worldAndNation('首都災害国');
        $capitalRecord = $nation->capital()->firstOrFail();
        $space = $this->surfaceMapSpace($world);
        $capital = $capitalRecord->cell()->with(['terrain', 'facility'])->firstOrFail();
        $capital->update(['population' => 10_000]);
        $identity = [
            $capital->id,
            $capital->facility_definition_id,
            $capital->owner_nation_id,
            $capital->terrain_definition_id,
            $capitalRecord->x,
            $capitalRecord->y,
        ];
        $expected = [
            'earthquake' => 9_000,
            'eruption' => 6_300,
            'meteor_shower' => 630,
            'meteor_shower-second' => 100,
        ];

        foreach ($expected as $step => $population) {
            $key = str_starts_with($step, 'meteor_shower') ? 'meteor_shower' : $step;
            $ruleset = $this->forceGlobal($ruleset, $key);
            [$context] = $this->context(
                $world,
                $ruleset,
                $this->seedForCenter($this->centerLabel($key), $capital->x, $capital->y, $space),
                [$nation->id],
            );
            app(DisasterTurnService::class)->executeGlobal($context);
            $this->assertSame($population, $capital->fresh()->population, $step);
        }

        $capital = $capital->fresh(['terrain', 'facility']);
        $capitalRecord->refresh();
        $this->assertSame($identity, [
            $capital->id,
            $capital->facility_definition_id,
            $capital->owner_nation_id,
            $capital->terrain_definition_id,
            $capitalRecord->x,
            $capitalRecord->y,
        ]);
        $this->assertSame('capital', $capital->facility?->key);

        $capital->update(['population' => 50]);
        $ruleset = $this->forceGlobal($ruleset, 'eruption');
        [$minimumContext, $minimumRun] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER, $capital->x, $capital->y, $space),
            [$nation->id],
        );
        app(DisasterTurnService::class)->executeGlobal($minimumContext);
        $minimumDamage = $this->event($minimumRun, 'capital.disaster_damaged');
        $this->assertSame(100, $capital->fresh()->population);
        $this->assertTrue($minimumDamage['minimum_population_applied']);
        $this->assertSame(50, $minimumDamage['minimum_population_adjustment']);
        $this->assertSame(5, DB::table('audit_events')->where('event_type', 'capital.disaster_damaged')->count());
    }

    public function test_fire_is_prevented_by_forest_then_damages_factory_and_capital(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('火災国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['disasters']['fire']['probability'] = ['numerator' => 1, 'denominator' => 1];
        });
        $center = $this->boundsFor($world)->center();
        $factory = $this->cellAt($space, $center->x, $center->y);
        $forest = $this->cellAt($space, $center->x + 1, $center->y);
        $this->setCell($factory, 'plain', 'factory', $nation->id, 0);
        $this->setCell($forest, 'forest', null, $nation->id, 0);
        $factory = $factory->fresh(['terrain', 'facility']);
        $forest = $forest->fresh(['terrain', 'facility']);
        $cellIndex = DisasterMutableCellIndex::fromCells(
            [$factory, $forest],
            terrainDefinitions: ['wasteland' => TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail()],
        );
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'fire-protection'), [$nation->id]);

        $this->assertFalse(app(DisasterTurnService::class)->processFire($context, $factory, $cellIndex));
        $this->assertSame('factory', $factory->fresh()->facility()->value('key'));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'fire.prevented')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());

        $states = app(MapCellStateService::class);
        $states->setFacility($forest, null);
        $states->transitionTerrain($forest, TerrainDefinition::query()->where('key', 'sea')->firstOrFail());
        $forest->owner_nation_id = null;
        $forest->population = 0;
        $forest->save();
        $this->assertSame('sea', $cellIndex->cellAt($forest->x, $forest->y)?->terrain->key);
        $this->assertTrue(app(DisasterTurnService::class)->processFire($context, $factory, $cellIndex));
        $this->assertSame('wasteland', $factory->fresh()->terrain()->value('key'));
        $this->assertNull($factory->fresh()->facility_definition_id);

        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $capital->update(['population' => 10_000]);
        foreach ((new GridCoordinate($capital->x, $capital->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        ) as $neighbor) {
            $this->setCell($this->cellAt($space, $neighbor->x, $neighbor->y), 'sea', null, null, 0);
        }
        $this->assertTrue(app(DisasterTurnService::class)->processFire($context, $capital->fresh(['terrain', 'facility'])));
        $this->assertSame(9_000, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));
    }

    public function test_earthquake_removal_is_visible_to_later_typhoon_protection_checks(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('連続災害国');
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        $protectionCoordinate = $center->neighbor(GridCoordinate::EAST);
        foreach ($center->neighborsWithin($space->min_x, $space->max_x, $space->min_y, $space->max_y) as $neighbor) {
            $this->setCell($this->cellAt($space, $neighbor->x, $neighbor->y), 'plain', null, $nation->id, 0);
        }
        $this->setCell($target, 'plain', 'farm', $nation->id, 0);
        $protection = $this->cellAt($space, $protectionCoordinate->x, $protectionCoordinate->y);
        $this->setCell($protection, 'plain', 'monument', $nation->id, 0);
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            foreach (self::GLOBAL_KEYS as $key) {
                $settings['turn_processing']['disasters'][$key]['probability'] = [
                    'numerator' => in_array($key, ['earthquake', 'typhoon'], true) ? 1 : 0,
                    'denominator' => 1,
                ];
                $settings['turn_processing']['disasters'][$key]['center_padding'] = 0;
            }
            $settings['turn_processing']['disasters']['earthquake']['radius'] = 64;
            $settings['turn_processing']['disasters']['earthquake']['facility_keys'] = ['monument'];
            $settings['turn_processing']['disasters']['earthquake']['damage_probability'] = [
                'numerator' => 1,
                'denominator' => 1,
            ];
            $settings['turn_processing']['disasters']['typhoon']['radius'] = 64;
            $settings['turn_processing']['disasters']['typhoon']['facility_keys'] = ['farm'];
            $settings['turn_processing']['disasters']['typhoon']['protection_facility_keys'] = ['monument'];
            $settings['turn_processing']['disasters']['typhoon']['internal_denominator'] = 1;
            $settings['turn_processing']['disasters']['typhoon']['base_damage_threshold'] = 1;
            $settings['turn_processing']['disasters']['land_subsidence']['enabled'] = false;
        });
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForAreaGates(['earthquake', 'typhoon'], 64),
            [$nation->id],
        );

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(2, $result['executed_disasters']);
        $this->assertNull($protection->fresh()->facility_definition_id);
        $this->assertSame('wasteland', $protection->fresh()->terrain()->value('key'));
        $this->assertNull($target->fresh()->facility_definition_id);
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
        $triggered = DB::table('audit_events')->where('event_type', 'disaster.triggered')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->orderBy('id')->pluck('metadata')->map(
                static fn (string $metadata): string => json_decode($metadata, true, 512, JSON_THROW_ON_ERROR)['disaster_key'],
            )->all();
        $this->assertSame(['earthquake', 'typhoon'], $triggered);
        $typhoonDamage = DB::table('audit_events')
            ->where('event_type', 'disaster.cell_damaged')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->whereRaw("metadata->>'disaster_key' = 'typhoon'")
            ->first(['visibility', 'metadata']);
        $this->assertNotNull($typhoonDamage);
        $this->assertSame('public', $typhoonDamage->visibility);
        $typhoonMetadataJson = $typhoonDamage->metadata;
        $this->assertIsString($typhoonMetadataJson);
        $typhoonMetadata = json_decode($typhoonMetadataJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('farm', $typhoonMetadata['removed_facility_key']);
        $this->assertSame('plain', $typhoonMetadata['from_terrain_key']);
        $this->assertSame('plain', $typhoonMetadata['to_terrain_key']);
    }

    public function test_tsunami_still_counts_out_of_bounds_neighbors_as_water(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('津波端国');
        $ruleset = $this->forceGlobal($ruleset, 'tsunami');
        $target = $this->cellAt($space, 0, 0);
        $origin = new GridCoordinate(0, 0);
        foreach ($origin->neighborsWithin($space->min_x, $space->max_x, $space->min_y, $space->max_y) as $neighbor) {
            $this->setCell($this->cellAt($space, $neighbor->x, $neighbor->y), 'plain', null, $nation->id, 0);
        }
        $this->setCell($target, 'plain', 'farm', $nation->id, 0);
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_TSUNAMI_CENTER, 0, 0, $space),
            [$nation->id],
        );

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(1, $result['damaged_cells']);
        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        $this->assertSame(3, $this->event($run, 'disaster.cell_damaged')['adjacent_water_count']);
    }

    public function test_normal_global_disaster_cannot_mutate_the_dormant_capital_radius(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('休眠災害保護国');
        $ruleset = $this->forceGlobal($ruleset, 'earthquake');
        $capital = $nation->capital()->firstOrFail();
        $target = $this->cellAt($space, $capital->x, $capital->y);
        $this->setCell($target, 'plain', 'factory', $nation->id, 0);
        $nation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        [$context] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER, $capital->x, $capital->y, $space),
            [],
        );
        $context->state->setNationLifecycleSnapshot($nation->id, [
            'state' => 'dormant',
            'reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(1, $result['executed_disasters']);
        $this->assertSame('factory', $target->fresh()->facility()->value('key'));
    }

    public function test_normal_global_disaster_still_mutates_recovery_territory(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('休戦災害継続国');
        $ruleset = $this->forceGlobal($ruleset, 'earthquake');
        $capital = $nation->capital()->firstOrFail();
        $target = $this->cellAt($space, $capital->x, $capital->y);
        $this->setCell($target, 'plain', 'factory', $nation->id, 0);
        $nation->update([
            'state' => 'recovery',
            'state_reason' => null,
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
        ]);
        [$context] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER, $capital->x, $capital->y, $space),
            [$nation->id],
        );
        $context->state->setNationLifecycleSnapshot($nation->id, [
            'state' => 'recovery',
            'reason' => null,
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);
        $context->state->setRecoveryTerritoryNationIds([
            $target->x.':'.$target->y => $nation->id,
        ]);

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(1, $result['executed_disasters']);
        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        $this->assertNull($target->fresh()->facility_definition_id);
    }

    public function test_oil_production_precedes_depletion_obeys_capacity_rolls_back_and_is_retry_idempotent(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('油田稼働国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['oil_field']['depletion_probability'] = ['numerator' => 1, 'denominator' => 1];
        });
        $center = $this->boundsFor($world)->center();
        $oil = $this->cellAt($space, $center->x, $center->y);
        $this->setCell($oil, 'sea', 'seabed_oil_field', $nation->id, 0);
        $oilDefinitionId = (int) DB::table('resource_definitions')->where('key', 'oil')->value('id');
        DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oilDefinitionId)->update(['amount' => 4_900]);
        $nation->update(['money' => 0]);
        $seed = hash('sha256', 'oil-rollback-replay');
        [$rollbackContext] = $this->context($world, $ruleset, $seed, [$nation->id], [$oil->id]);

        try {
            DB::transaction(function () use ($rollbackContext): void {
                app(CompleteTurnEngine::class)->execute('process_cells', $rollbackContext);
                throw new RuntimeException('rollback probe');
            });
            $this->fail('Expected the rollback probe to abort the World transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback probe', $exception->getMessage());
        }
        $this->assertSame(4_900, (int) DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oilDefinitionId)->value('amount'));
        $this->assertSame(0, (int) $nation->fresh()->money);
        $this->assertSame('seabed_oil_field', $oil->fresh()->facility()->value('key'));
        $this->assertSame($nation->id, $oil->fresh()->owner_nation_id);

        [$context, $run] = $this->context($world, $ruleset, $seed, [$nation->id], [$oil->id]);
        $result = app(CompleteTurnEngine::class)->execute('process_cells', $context);
        $income = $this->event($run, 'oil.income');
        $depleted = $this->event($run, 'oil.depleted');
        $oil = $oil->fresh(['terrain', 'facility']);

        $this->assertSame(500, $result->metrics['oil_income']);
        $this->assertSame(1, $result->metrics['oil_depleted']);
        $this->assertSame('oil', $income['resource_key']);
        $this->assertSame(500, $income['requested_units']);
        $this->assertSame(500, $income['applied_units']);
        $this->assertSame(4_900, $income['before_units']);
        $this->assertSame(5_400, $income['after_units']);
        $this->assertTrue($depleted['production_applied_first']);
        $this->assertSame(5_400, (int) DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oilDefinitionId)->value('amount'));
        $this->assertSame(0, (int) $nation->fresh()->money);
        $this->assertNull($oil->facility_definition_id);
        $this->assertNull($oil->owner_nation_id);
        $this->assertSame('sea', $oil->terrain->key);
        $this->assertLessThan(
            DB::table('audit_events')->where('event_type', 'oil.depleted')
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('id'),
            DB::table('audit_events')->where('event_type', 'oil.income')
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('id'),
        );

        $capacity = app(CompleteTurnEngine::class)->execute('enforce_capacities', $context);
        $overflow = $this->event($run, 'capacity.overflow');
        $this->assertSame(1, $capacity->metrics['overflow_reports']);
        $this->assertSame('oil', $overflow['resource_key']);
        $this->assertSame(400, $overflow['overflow']);
        $this->assertSame(5_000, $overflow['after']);
        $this->assertSame(5_000, (int) DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oilDefinitionId)->value('amount'));

        [$retryContext] = $this->context($world, $ruleset, $seed, [$nation->id], [$oil->id]);
        $retry = app(CompleteTurnEngine::class)->execute('process_cells', $retryContext);
        $this->assertSame(0, $retry->metrics['oil_income']);
        $this->assertSame(0, $retry->metrics['oil_depleted']);
        $this->assertSame(5_000, (int) DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oilDefinitionId)->value('amount'));
        $this->assertSame(0, (int) $nation->fresh()->money);
    }

    public function test_land_level_draws_only_after_success_and_applies_the_immediate_event(): void
    {
        [$world, $nation, $ruleset, $space, $user] = $this->worldAndNation('地ならし地震国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $earthquake = &$settings['turn_processing']['command_random_effects']['land_level_earthquake'];
            $earthquake['probability'] = ['numerator' => 1, 'denominator' => 1];
            $earthquake['damage_probability'] = ['numerator' => 1, 'denominator' => 1];
        });
        $capitalCellId = $nation->capital()->value('map_cell_id');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capitalCellId)->firstOrFail();
        $this->setCell($target, 'wasteland', null, $nation->id, 0);
        $victim = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', [$target->id, $capitalCellId])->firstOrFail();
        $this->setCell($victim, 'plain', 'factory', $nation->id, 0);
        $valid = $this->queueItem($user, $nation, $space, $target, 'land_level');
        [$successContext, $successRun] = $this->context(
            $world,
            $ruleset,
            hash('sha256', 'land-level-success'),
            [$nation->id],
        );

        app(DomesticCommandExecutor::class)->execute($successContext);
        $this->assertSame('completed', $valid->fresh()->status);
        $this->assertSame('wasteland', $victim->fresh()->terrain()->value('key'));
        $this->assertNull($victim->fresh()->facility_definition_id);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.land_level_earthquake')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $successRun->id])->count());
        $damage = $this->event($successRun, 'disaster.cell_damaged');
        $this->assertSame('land_level', $damage['source']);
        $this->assertSame($victim->x, $damage['x']);
        $this->assertSame($victim->y, $damage['y']);

        $invalidTarget = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', [$target->id, $victim->id, $capitalCellId])->firstOrFail();
        $invalid = $this->queueItem($user, $nation, $space, $invalidTarget, 'land_level');
        $this->setCell($invalidTarget, 'sea', null, $nation->id, 0);
        [$failureContext, $failureRun] = $this->context(
            $world,
            $ruleset,
            hash('sha256', 'land-level-failure'),
            [$nation->id],
        );

        app(DomesticCommandExecutor::class)->execute($failureContext);
        $this->assertSame('failed', $invalid->fresh()->status);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.land_level_earthquake')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $failureRun->id])->count());
    }

    /** @return array{World, Nation, RulesetVersion, MapSpace, User} */
    private function worldAndNation(string $name): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name, '試験島主');
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();

        return [$world, $nation, $ruleset, $space, $user];
    }

    private function forceGlobal(RulesetVersion $ruleset, string $selected): RulesetVersion
    {
        return $this->updateRuleset($ruleset, static function (array &$settings) use ($selected): void {
            foreach (self::GLOBAL_KEYS as $key) {
                $settings['turn_processing']['disasters'][$key]['probability'] = [
                    'numerator' => $key === $selected ? 1 : 0,
                    'denominator' => 1,
                ];
                $settings['turn_processing']['disasters'][$key]['center_padding'] = 0;
            }
            $settings['turn_processing']['disasters'][$selected]['radius'] = $selected === 'eruption' ? 1 : 0;
            $settings['turn_processing']['disasters']['earthquake']['damage_probability'] = [
                'numerator' => 1, 'denominator' => 1,
            ];
            $settings['turn_processing']['disasters']['tsunami']['internal_denominator'] = 1;
            $settings['turn_processing']['disasters']['tsunami']['adjacent_water_offset'] = 0;
            $settings['turn_processing']['disasters']['typhoon']['internal_denominator'] = 1;
            $settings['turn_processing']['disasters']['typhoon']['base_damage_threshold'] = 1;
            $settings['turn_processing']['disasters']['meteor_shower']['continuation_probability'] = [
                'numerator' => 0, 'denominator' => 1,
            ];
        });
    }

    /** @param callable(array<string, mixed>&): void $mutate */
    private function updateRuleset(RulesetVersion $ruleset, callable $mutate): RulesetVersion
    {
        $settings = $ruleset->settings;
        $mutate($settings);
        $ruleset->settings = $settings;
        $ruleset->save();

        return $ruleset->fresh();
    }

    /**
     * @param  list<int>  $nationIds
     * @param  list<int>  $cellIds
     * @return array{TurnContext, TurnRun}
     */
    private function context(
        World $world,
        RulesetVersion $ruleset,
        string $seed,
        array $nationIds,
        array $cellIds = [],
    ): array {
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 2,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);
        $state->setSurfaceCellIds($cellIds);

        $context = new TurnContext($world, $run, $ruleset, 2, $seed, new TurnRandomStreamFactory($seed), $state);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);

        return [$context, $run];
    }

    private function setCell(
        MapCell $cell,
        string $terrainKey,
        ?string $facilityKey,
        ?int $ownerNationId,
        int $population,
    ): void {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        if ($facilityKey !== null) {
            $states->setFacility($cell, FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail());
        }
        $cell->owner_nation_id = $ownerNationId;
        $cell->population = $population;
        $cell->save();
    }

    private function cellAt(MapSpace $space, int $x, int $y): MapCell
    {
        return MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $x)->where('y', $y)->with(['terrain', 'facility'])->firstOrFail();
    }

    private function centerLabel(string $key): string
    {
        return match ($key) {
            'earthquake' => TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER,
            'tsunami' => TurnRandomStreamFactory::GLOBAL_TSUNAMI_CENTER,
            'typhoon' => TurnRandomStreamFactory::GLOBAL_TYPHOON_CENTER,
            'meteor_shower' => TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_CENTER,
            'huge_meteor' => TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_CENTER,
            'eruption' => TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER,
            default => throw new RuntimeException("Unknown disaster {$key}."),
        };
    }

    private function seedForCenter(string $label, int $x, int $y, MapSpace $space): string
    {
        $disasterKey = match ($label) {
            TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER => 'earthquake',
            TurnRandomStreamFactory::GLOBAL_TSUNAMI_CENTER => 'tsunami',
            TurnRandomStreamFactory::GLOBAL_TYPHOON_CENTER => 'typhoon',
            TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_CENTER => 'meteor_shower',
            TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_CENTER => 'huge_meteor',
            TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER => 'eruption',
            default => throw new RuntimeException("Unknown disaster center stream {$label}."),
        };
        $scaleNumerator = 16 * $space->currentBounds()->chunkCount();
        $full = intdiv($scaleNumerator, 225);
        $remainder = $scaleNumerator % 225;
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$x}:{$y}:{$candidate}");
            $factory = new TurnRandomStreamFactory($seed);
            $stream = $factory->stream($label);
            $gate = $remainder === 0 ? null : $factory
                ->stream(TurnRandomStreamFactory::worldDisasterAreaFraction($disasterKey))
                ->integer(0, 224);
            $hasExactlyOneOpportunity = $remainder === 0
                || ($full === 0 ? $gate < $remainder : $full === 1 && $gate >= $remainder);
            if ($stream->integer($space->min_x, $space->max_x) === $x
                && $stream->integer($space->min_y, $space->max_y) === $y
                && $hasExactlyOneOpportunity) {
                return $seed;
            }
        }

        $this->fail("Unable to find center seed for {$label} at {$x},{$y}.");
    }

    private function seedForAreaGate(string $disasterKey, int $threshold, bool $admitted): string
    {
        for ($candidate = 0; $candidate < 10_000; $candidate++) {
            $seed = hash('sha256', "area-gate:{$disasterKey}:{$threshold}:{$candidate}");
            $draw = (new TurnRandomStreamFactory($seed))
                ->stream(TurnRandomStreamFactory::worldDisasterAreaFraction($disasterKey))
                ->integer(0, 224);
            if (($draw < $threshold) === $admitted) {
                return $seed;
            }
        }

        $this->fail("Unable to find area-gate seed for {$disasterKey}.");
    }

    /** @param list<string> $disasterKeys */
    private function seedForAreaGates(array $disasterKeys, int $threshold): string
    {
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', 'area-gates:'.implode(':', $disasterKeys).":{$candidate}");
            $factory = new TurnRandomStreamFactory($seed);
            foreach ($disasterKeys as $disasterKey) {
                $draw = $factory->stream(TurnRandomStreamFactory::worldDisasterAreaFraction($disasterKey))
                    ->integer(0, 224);
                if ($draw >= $threshold) {
                    continue 2;
                }
            }

            return $seed;
        }

        $this->fail('Unable to find a deterministic seed for all disaster area gates.');
    }

    private function queueItem(
        User $user,
        Nation $nation,
        MapSpace $space,
        MapCell $target,
        string $commandKey,
    ): NationCommandQueueItem {
        $queue = NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $space->id, 'version' => 1],
        );
        $definition = CommandDefinition::query()->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
            ->where('key', $commandKey)->firstOrFail();
        $membership = NationMembership::query()->where('user_id', $user->id)
            ->where('nation_id', $nation->id)->firstOrFail();

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $definition->id,
            'queue_position' => 1,
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => $membership->id,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => [],
        ])->load('definition');
    }

    /** @return array<string, mixed> */
    private function event(TurnRun $run, string $eventType): array
    {
        $metadata = DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('metadata');

        return json_decode((string) $metadata, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function events(TurnRun $run, string $eventType): array
    {
        return DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->orderBy('id')->pluck('metadata')
            ->map(static fn (string $metadata): array => json_decode($metadata, true, 512, JSON_THROW_ON_ERROR))
            ->all();
    }

    /** @return list<array{event_type: string, metadata: array<string, mixed>}> */
    private function turnEvents(TurnRun $run): array
    {
        return DB::table('audit_events')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->orderBy('id')
            ->get(['event_type', 'metadata'])
            ->map(static fn (object $event): array => [
                'event_type' => $event->event_type,
                'metadata' => json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR),
            ])->all();
    }

    /** @return list<array<string, int|null>> */
    private function cellState(World $world): array
    {
        $spaceId = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->value('id');

        return MapCell::query()->where('map_space_id', $spaceId)
            ->orderBy('id')
            ->get([
                'id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id',
                'population', 'facility_experience', 'version',
            ])->map(static fn (MapCell $cell): array => [
                'id' => $cell->id,
                'terrain_definition_id' => $cell->terrain_definition_id,
                'facility_definition_id' => $cell->facility_definition_id,
                'owner_nation_id' => $cell->owner_nation_id,
                'population' => $cell->population,
                'facility_experience' => $cell->facility_experience,
                'version' => $cell->version,
            ])->all();
    }
}
