<?php

namespace Tests\Feature;

use App\Application\CentralFacilityDamageService;
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
use App\Models\BuriedTreasure;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\Ship;
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
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

class DisasterAndOilTurnTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

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
            'meteor_shower' => ['shallow', null, 0, 'sea', null],
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
        $this->assertSame([
            ['source' => 'meteor', 'item_key' => 'wakuwaku_ticket'],
            ['source' => 'huge_meteor', 'item_key' => 'dokidoki_ticket'],
        ], BuriedTreasure::query()->orderBy('id')->get()->map(static fn (BuriedTreasure $treasure): array => [
            'source' => $treasure->source,
            'item_key' => $treasure->reward_snapshot['item_key'],
        ])->all());
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'buried_treasure.created')
            ->where('visibility', 'public')->count());
    }

    public function test_eruption_sinks_a_dormant_nation_ship_before_mutating_its_cell(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('休眠船舶災害国');
        $capital = new GridCoordinate(
            (int) $nation->capital()->valueOrFail('x'),
            (int) $nation->capital()->valueOrFail('y'),
        );
        $targetCoordinate = $capital->ring(2)[0];
        $target = $this->cellAt($space, $targetCoordinate->x, $targetCoordinate->y);
        $this->setCell($target, 'sea', null, null, 0);
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $ruleset->id,
            'nation_id' => $nation->id,
            'map_cell_id' => $target->id,
            'ship_type_key' => 'exploration',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $nation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
        ]);
        $ruleset = $this->forceGlobal($ruleset, 'eruption');
        $seed = $this->seedForCenter(
            TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER,
            $target->x,
            $target->y,
            $space,
        );
        [$context, $run] = $this->context($world, $ruleset, $seed, [$nation->id]);
        $context->state->setNationLifecycleSnapshot($nation->id, [
            'state' => 'dormant',
            'reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);

        app(DisasterTurnService::class)->executeGlobal($context);

        $ship = $ship->fresh();
        $this->assertSame(Ship::STATE_REMOVED, $ship->state);
        $this->assertSame('eruption', $ship->removal_reason);
        $this->assertSame(0, $ship->current_hp);
        $this->assertNull($ship->map_cell_id);
        $this->assertSame('sea', $target->fresh()->terrain()->value('key'));
        $event = $this->event($run, 'ship.sunk');
        $this->assertSame('探索船', $event['ship_name']);
        $this->assertSame('eruption', $event['removal_reason']);
    }

    public function test_eruption_uses_adr_directions_and_never_creates_world_outside_cells(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('端災害国');
        $ruleset = $this->forceGlobal($ruleset, 'eruption');
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$x, $y]) {
            $this->setCell($this->cellAt($space, $x, $y), 'sea', null, null, 0);
        }
        $this->setCell($this->cellAt($space, 1, 0), 'sea', null, $nation->id, 0);
        [$context] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER, 0, 0, $space),
            [$nation->id],
        );

        app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame('mountain', $this->cellAt($space, 0, 0)->terrain()->value('key'));
        foreach ([[1, 0], [0, 1], [1, 1]] as [$x, $y]) {
            $changed = $this->cellAt($space, $x, $y);
            $this->assertSame('shallow', $changed->terrain()->value('key'));
            $this->assertNull($changed->owner_nation_id);
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
        $this->assertSame(1, $context->state->routineSummaryMetrics($nation->id)['fire_protection_checks']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'fire.prevented')
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

    public function test_undersea_city_burns_at_3000_without_forest_or_monument_protection_and_reuses_seabed_disaster_lists(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('海底災害国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['disasters']['fire']['probability'] = ['numerator' => 1, 'denominator' => 1];
        });
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        $neighbors = $center->neighborsWithin($space->min_x, $space->max_x, $space->min_y, $space->max_y);
        $forest = $this->cellAt($space, $neighbors[0]->x, $neighbors[0]->y);
        $monument = $this->cellAt($space, $neighbors[1]->x, $neighbors[1]->y);
        $this->setCell($target, 'sea', 'undersea_city', $nation->id, 3_000);
        $this->setCell($forest, 'forest', null, $nation->id, 0);
        $this->setCell($monument, 'plain', 'monument', $nation->id, 0);
        $target = $target->fresh(['terrain', 'facility']);
        $forest = $forest->fresh(['terrain', 'facility']);
        $monument = $monument->fresh(['terrain', 'facility']);
        $cellIndex = DisasterMutableCellIndex::fromCells(
            [$target, $forest, $monument],
            terrainDefinitions: ['sea' => TerrainDefinition::query()->where('key', 'sea')->firstOrFail()],
        );
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'undersea fire'), [$nation->id]);

        $this->assertTrue(app(DisasterTurnService::class)->processFire($context, $target, $cellIndex));
        $burned = $target->fresh(['terrain', 'facility']);
        $this->assertSame('sea', $burned->terrain->key);
        $this->assertNull($burned->facility_definition_id);
        $this->assertSame(0, $burned->population);
        $this->assertNull($burned->owner_nation_id);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'fire.prevented')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'fire.undersea_city_destroyed',
            'visibility' => 'private',
            'subject_id' => $target->id,
        ]);

        $disasters = $ruleset->settings['turn_processing']['disasters'];
        $this->assertContains('seabed_base', $disasters['tsunami']['excluded_facility_keys']);
        $this->assertContains('undersea_city', $disasters['tsunami']['excluded_facility_keys']);
        $this->assertContains('seabed_base', $disasters['tsunami']['water_facility_keys']);
        $this->assertContains('undersea_city', $disasters['tsunami']['water_facility_keys']);
        foreach (['meteor_shower', 'huge_meteor', 'eruption'] as $disasterKey) {
            $this->assertSame(
                in_array('seabed_base', $disasters[$disasterKey]['seabed_facility_keys'], true),
                in_array('undersea_city', $disasters[$disasterKey]['seabed_facility_keys'], true),
                $disasterKey,
            );
        }
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
        $engine = app(CompleteTurnEngine::class);
        $engine->execute('resource_sales', $context);
        $result = $engine->execute('process_cells', $context);
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

        $capacity = $engine->execute('enforce_capacities', $context);
        $overflowSale = DB::table('audit_events')
            ->where('event_type', 'resource.automatic_sale')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->whereRaw("metadata->>'resource_key' = 'oil'")
            ->orderByDesc('id')
            ->value('metadata');
        $this->assertIsString($overflowSale);
        $overflowSale = json_decode($overflowSale, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $capacity->metrics['overflow_reports']);
        $this->assertSame(400, $overflowSale['requested']);
        $this->assertSame(400, $overflowSale['sold']);
        $this->assertSame(800, $overflowSale['revenue']);
        $this->assertSame(5_000, $overflowSale['after']);
        $this->assertSame('capacity_overflow', $overflowSale['sale_reason']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'capacity.overflow')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
        $this->assertSame(5_000, (int) DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oilDefinitionId)->value('amount'));
        $this->assertSame(800, (int) $nation->fresh()->money);

        [$retryContext] = $this->context($world, $ruleset, $seed, [$nation->id], [$oil->id]);
        $retry = app(CompleteTurnEngine::class)->execute('process_cells', $retryContext);
        $this->assertSame(0, $retry->metrics['oil_income']);
        $this->assertSame(0, $retry->metrics['oil_depleted']);
        $this->assertSame(5_000, (int) DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oilDefinitionId)->value('amount'));
        $this->assertSame(800, (int) $nation->fresh()->money);
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

    public function test_v21_rank_two_factory_fire_damage_preserves_the_facility_and_cell_state(): void
    {
        [$world, $nation, $ruleset, $space, $owner] = $this->worldAndNation('ランク二工場火災国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['disasters']['fire']['probability'] = [
                'numerator' => 1,
                'denominator' => 1,
            ];
        });
        $target = $this->cellAt($space, $this->boundsFor($world)->center()->x, $this->boundsFor($world)->center()->y);
        $this->setCell($target, 'plain', 'factory', $nation->id, 0);
        $target->facility_scale = 105;
        $target->save();
        $target = $target->fresh(['terrain', 'facility']);
        $beforeVersion = (int) $target->version;
        $beforeChunkVersion = (int) DB::table('map_chunks')->where('id', $target->map_chunk_id)->value('version');
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'rank-two-factory-fire'), [$nation->id]);
        $cellIndex = DisasterMutableCellIndex::fromCells([$target]);

        $this->assertTrue(app(DisasterTurnService::class)->processFire($context, $target, $cellIndex));

        $after = $target->fresh(['terrain', 'facility']);
        $this->assertSame('factory', $after->facility?->key);
        $this->assertSame(85, $after->facility_scale);
        $this->assertSame('plain', $after->terrain->key);
        $this->assertSame($nation->id, $after->owner_nation_id);
        $this->assertSame(0, $after->population);
        $this->assertSame($beforeVersion + 1, (int) $after->version);
        $this->assertSame([$after->map_chunk_id], $context->state->changedMapChunkIds());
        app(CompleteTurnEngine::class)->execute('aggregate_nations', $context);
        $this->assertSame($beforeChunkVersion + 1, (int) DB::table('map_chunks')
            ->where('id', $after->map_chunk_id)->value('version'));
        $damage = $this->event($run, 'facility.partially_damaged');
        $this->assertSame('factory', $damage['facility_key']);
        $this->assertSame('fire', $damage['damage_kind']);
        $this->assertSame(105, $damage['before_scale']);
        $this->assertSame(85, $damage['after_scale']);
        $this->assertSame(20, $damage['scale_loss']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'fire.damaged')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
        $world->update(['current_turn' => 2]);

        foreach ([
            $this->getJson("/api/v1/public/nations/{$nation->id}/events")->assertOk(),
            $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk(),
        ] as $response) {
            $event = collect($response->json('data.groups'))
                ->flatMap(static fn (array $group): array => $group['events'])
                ->firstWhere('type', 'facility.partially_damaged');
            $this->assertIsArray($event);
            $this->assertSame('warning', $event['importance']);
            $this->assertSame(
                "{$nation->name}({$target->x},{$target->y})の大工場が火災により一部損壊し、"
                .'規模が105,000人から85,000人へ減少しました。ランク1へ降格しました。',
                $event['message'],
            );
        }
    }

    public function test_v21_rank_two_factory_earthquake_damage_preserves_the_facility_and_cell_state(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('ランク二工場地震国');
        $ruleset = $this->forceGlobal($ruleset, 'earthquake');
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        $this->setCell($target, 'plain', 'factory', $nation->id, 0);
        $target->facility_scale = 105;
        $target->save();
        $target = $target->fresh(['terrain', 'facility']);
        $beforeVersion = (int) $target->version;
        $beforeChunkVersion = (int) DB::table('map_chunks')->where('id', $target->map_chunk_id)->value('version');
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER, $center->x, $center->y, $space),
            [$nation->id],
        );

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $after = $target->fresh(['terrain', 'facility']);
        $this->assertSame(1, $result['damaged_cells']);
        $this->assertSame('factory', $after->facility?->key);
        $this->assertSame(85, $after->facility_scale);
        $this->assertSame('plain', $after->terrain->key);
        $this->assertSame($nation->id, $after->owner_nation_id);
        $this->assertSame(0, $after->population);
        $this->assertSame($beforeVersion + 1, (int) $after->version);
        $this->assertSame([$after->map_chunk_id], $context->state->changedMapChunkIds());
        app(CompleteTurnEngine::class)->execute('aggregate_nations', $context);
        $this->assertSame($beforeChunkVersion + 1, (int) DB::table('map_chunks')
            ->where('id', $after->map_chunk_id)->value('version'));
        $damage = $this->event($run, 'facility.partially_damaged');
        $this->assertSame('earthquake', $damage['damage_kind']);
        $this->assertSame(20, $damage['scale_loss']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'disaster.cell_damaged')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
    }

    public function test_v21_rank_two_farm_typhoon_uses_the_rank_two_threshold_but_still_removes_the_facility(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('ランク二農場台風国');
        $ruleset = $this->forceGlobal($ruleset, 'typhoon');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['disasters']['typhoon']['base_damage_threshold'] = 0;
        });
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        $this->setCell($target, 'plain', 'farm', $nation->id, 0);
        $target->facility_scale = 51;
        $target->save();
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_TYPHOON_CENTER, $center->x, $center->y, $space),
            [$nation->id],
        );

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $after = $target->fresh(['terrain', 'facility']);
        $this->assertSame(1, $result['damaged_cells']);
        $this->assertNull($after->facility_definition_id);
        $this->assertNull($after->facility_scale);
        $this->assertSame('plain', $after->terrain->key);
        $this->assertSame($nation->id, $after->owner_nation_id);
        $this->assertSame(0, $after->population);
        $damage = $this->event($run, 'disaster.cell_damaged');
        $this->assertSame('typhoon', $damage['disaster_key']);
        $this->assertSame('farm', $damage['removed_facility_key']);
    }

    public function test_v21_meteor_shower_uses_land_destruction_scale_damage_for_rank_two_farm(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('ランク二農場流星群国');
        $ruleset = $this->forceGlobal($ruleset, 'meteor_shower');
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        $this->setCell($target, 'plain', 'farm', $nation->id, 0);
        $target->facility_scale = 51;
        $target->save();
        $target = $target->fresh(['terrain', 'facility']);
        $beforeVersion = (int) $target->version;
        $beforeChunkVersion = (int) DB::table('map_chunks')->where('id', $target->map_chunk_id)->value('version');
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_CENTER, $center->x, $center->y, $space),
            [$nation->id],
        );

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $after = $target->fresh(['terrain', 'facility']);
        $this->assertSame(1, $result['damaged_cells']);
        $this->assertSame('farm', $after->facility?->key);
        $this->assertSame(48, $after->facility_scale);
        $this->assertSame('plain', $after->terrain->key);
        $this->assertSame($nation->id, $after->owner_nation_id);
        $this->assertSame(0, $after->population);
        $this->assertSame($beforeVersion + 1, (int) $after->version);
        $this->assertSame([$after->map_chunk_id], $context->state->changedMapChunkIds());
        app(CompleteTurnEngine::class)->execute('aggregate_nations', $context);
        $this->assertSame($beforeChunkVersion + 1, (int) DB::table('map_chunks')
            ->where('id', $after->map_chunk_id)->value('version'));
        $damage = $this->event($run, 'facility.partially_damaged');
        $this->assertSame('land_destruction', $damage['damage_kind']);
        $this->assertSame('meteor_shower', $damage['source_key']);
        $this->assertSame(3, $damage['scale_loss']);
    }

    public function test_v23_central_facility_uses_confirmed_tsunami_meteor_and_earthquake_damage(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('中央施設単体災害国');
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        foreach ($center->neighborsWithin($space->min_x, $space->max_x, $space->min_y, $space->max_y) as $neighbor) {
            $this->setCell($this->cellAt($space, $neighbor->x, $neighbor->y), 'sea', null, null, 0);
        }
        foreach ([
            'earthquake' => [20, 0],
            'tsunami' => [19, 1],
            'meteor_shower' => [15, 5],
        ] as $disasterKey => [$expectedScale, $expectedLoss]) {
            $this->setCell($target, 'plain', 'central_bank', $nation->id, 0);
            $target = $target->fresh(['terrain', 'facility']);
            $target->facility_scale = 20;
            $target->save();
            $ruleset = $this->forceGlobal($ruleset, $disasterKey);
            [$context, $run] = $this->context(
                $world,
                $ruleset,
                $this->seedForCenter($this->centerLabel($disasterKey), $center->x, $center->y, $space),
                [$nation->id],
            );

            $result = app(DisasterTurnService::class)->executeGlobal($context);
            $after = $target->fresh(['terrain', 'facility']);

            $this->assertSame($expectedScale, $after->facility_scale, $disasterKey);
            $this->assertSame('central_bank', $after->facility?->key, $disasterKey);
            $this->assertSame('plain', $after->terrain->key, $disasterKey);
            if ($expectedLoss === 0) {
                $this->assertSame(0, $result['damaged_cells'], $disasterKey);
                $this->assertSame(0, DB::table('audit_events')->where('event_type', 'facility.partially_damaged')
                    ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
            } else {
                $damage = $this->event($run, 'facility.partially_damaged');
                $this->assertSame($expectedLoss, $damage['scale_loss'], $disasterKey);
                $this->assertSame($disasterKey, $damage['source_key'], $disasterKey);
            }
        }
    }

    public function test_central_facility_disaster_loss_zero_keeps_the_facility_unchanged(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('中央施設無被害国');
        $center = $this->boundsFor($world)->center();
        $target = $this->cellAt($space, $center->x, $center->y);
        foreach ($center->neighborsWithin($space->min_x, $space->max_x, $space->min_y, $space->max_y) as $neighbor) {
            $this->setCell($this->cellAt($space, $neighbor->x, $neighbor->y), 'sea', null, null, 0);
        }
        $this->setCell($target, 'plain', 'central_bank', $nation->id, 0);
        $target->update(['facility_scale' => 20]);
        $ruleset = $this->forceGlobal($ruleset, 'tsunami');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['central_facilities']['disaster_damage']['tsunami_level_loss'] = 0;
        });
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter($this->centerLabel('tsunami'), $center->x, $center->y, $space),
            [$nation->id],
        );

        $result = app(DisasterTurnService::class)->executeGlobal($context);

        $after = $target->fresh(['terrain', 'facility']);
        $this->assertSame(0, $result['damaged_cells']);
        $this->assertSame(20, $after->facility_scale);
        $this->assertSame('central_bank', $after->facility?->key);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'facility.partially_damaged')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
    }

    public function test_central_facility_damage_uses_the_authored_maximum_level(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('中央施設上限可変国');
        $target = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $this->setCell($target, 'plain', 'central_bank', $nation->id, 0);
        $target->update(['facility_scale' => 91]);
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['facility_definitions']['central_bank']['maximum_scale'] = 100;
        });
        [$context] = $this->context(
            $world,
            $ruleset,
            hash('sha256', 'central facility configured maximum damage'),
            [$nation->id],
            [$target->id],
        );

        $result = app(CentralFacilityDamageService::class)->apply(
            $context,
            $target->fresh(['terrain', 'facility', 'ownerNation']),
            1,
            'test',
            'configured_maximum',
        );

        $this->assertSame(90, $result['after_scale']);
        $this->assertSame(90, (int) $target->fresh()->facility_scale);
    }

    public function test_v23_huge_meteor_uses_twenty_five_one_central_facility_damage(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('中央施設巨大隕石国');
        $center = $this->boundsFor($world)->center();
        $coordinates = [$center, $center->ring(1)[0], $center->ring(2)[0]];
        $beforeScales = [30, 10, 2];
        $cells = [];
        foreach ($coordinates as $index => $coordinate) {
            $cell = $this->cellAt($space, $coordinate->x, $coordinate->y);
            $this->setCell($cell, 'plain', $index === 1 ? 'central_granary' : 'central_bank', $nation->id, 0);
            $cell->facility_scale = $beforeScales[$index];
            $cell->save();
            $cells[] = $cell->fresh(['terrain', 'facility']);
        }
        $cellIndex = DisasterMutableCellIndex::fromCells($cells, [$nation->id]);
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'v23-central-huge-meteor'), [$nation->id]);

        $damaged = app(DisasterTurnService::class)->resolveHugeMeteorBlast(
            $context,
            $space,
            $center,
            $ruleset->settings['turn_processing']['disasters']['huge_meteor'],
            cellIndex: $cellIndex,
        );

        $this->assertSame(3, $damaged);
        $this->assertSame([10, 5, 1], array_map(
            static fn (MapCell $cell): int => (int) $cell->fresh()->facility_scale,
            $cells,
        ));
        $events = $this->events($run, 'facility.partially_damaged');
        $this->assertSame([20, 5, 1], array_column($events, 'scale_loss'));
        $this->assertSame([0, 1, 2], array_column($events, 'ring_distance'));
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'facility.partially_damaged')
            ->where('visibility', 'private')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
    }

    public function test_v23_eruption_uses_five_one_central_facility_damage(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('中央施設噴火国');
        $ruleset = $this->forceGlobal($ruleset, 'eruption');
        $center = $this->boundsFor($world)->center();
        $neighborCoordinate = $center->ring(1)[0];
        $centerCell = $this->cellAt($space, $center->x, $center->y);
        $neighbor = $this->cellAt($space, $neighborCoordinate->x, $neighborCoordinate->y);
        foreach ([[$centerCell, 9], [$neighbor, 5]] as [$cell, $scale]) {
            $this->setCell($cell, 'plain', 'central_granary', $nation->id, 0);
            $cell->facility_scale = $scale;
            $cell->save();
        }
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER, $center->x, $center->y, $space),
            [$nation->id],
        );

        app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(4, (int) $centerCell->fresh()->facility_scale);
        $this->assertSame(4, (int) $neighbor->fresh()->facility_scale);
        $events = $this->events($run, 'facility.partially_damaged');
        $this->assertSame([5, 1], array_column($events, 'scale_loss'));
        $this->assertSame([0, 1], array_column($events, 'ring_distance'));
        $this->assertSame(['eruption', 'eruption'], array_column($events, 'source_key'));
    }

    public function test_v21_huge_meteor_ring_distance_selects_land_then_ordinary_damage(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('ランク二施設巨大隕石国');
        $center = $this->boundsFor($world)->center();
        $ringOneCoordinate = $center->ring(1)[0];
        $ringTwoCoordinate = $center->ring(2)[0];
        $ringOne = $this->cellAt($space, $ringOneCoordinate->x, $ringOneCoordinate->y);
        $ringTwo = $this->cellAt($space, $ringTwoCoordinate->x, $ringTwoCoordinate->y);
        $this->setCell($ringOne, 'plain', 'farm', $nation->id, 0);
        $ringOne->facility_scale = 51;
        $ringOne->save();
        $this->setCell($ringTwo, 'plain', 'factory', $nation->id, 0);
        $ringTwo->facility_scale = 105;
        $ringTwo->save();
        $ringOne = $ringOne->fresh(['terrain', 'facility']);
        $ringTwo = $ringTwo->fresh(['terrain', 'facility']);
        $beforeRingOneVersion = (int) $ringOne->version;
        $beforeRingTwoVersion = (int) $ringTwo->version;
        $cellIndex = DisasterMutableCellIndex::fromCells([$ringOne, $ringTwo], [$nation->id]);
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'rank-two-huge-ring'), [$nation->id]);

        $result = app(DisasterTurnService::class)->resolveHugeMeteorBlast(
            $context,
            $space,
            $center,
            $ruleset->settings['turn_processing']['disasters']['huge_meteor'],
            cellIndex: $cellIndex,
        );

        $this->assertSame(2, $result);
        $afterRingOne = $ringOne->fresh(['terrain', 'facility']);
        $afterRingTwo = $ringTwo->fresh(['terrain', 'facility']);
        $this->assertSame('farm', $afterRingOne->facility?->key);
        $this->assertSame(48, $afterRingOne->facility_scale);
        $this->assertSame('plain', $afterRingOne->terrain->key);
        $this->assertSame($beforeRingOneVersion + 1, (int) $afterRingOne->version);
        $this->assertSame('factory', $afterRingTwo->facility?->key);
        $this->assertSame(100, $afterRingTwo->facility_scale);
        $this->assertSame('plain', $afterRingTwo->terrain->key);
        $this->assertSame($beforeRingTwoVersion + 1, (int) $afterRingTwo->version);
        $expectedChunkIds = array_values(array_unique([$ringOne->map_chunk_id, $ringTwo->map_chunk_id]));
        sort($expectedChunkIds, SORT_NUMERIC);
        $this->assertSame(
            $expectedChunkIds,
            $context->state->changedMapChunkIds(),
        );
        $damages = $this->events($run, 'facility.partially_damaged');
        $this->assertCount(2, $damages);
        $this->assertSame(['land_destruction', 'ordinary_terrain_destruction'], array_column($damages, 'damage_kind'));
        $this->assertSame([3, 5], array_column($damages, 'scale_loss'));
    }

    public function test_v21_huge_meteor_ring_two_keeps_mountain_mine_outside_the_existing_target(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('ランク二採掘場巨大隕石国');
        $center = $this->boundsFor($world)->center();
        $targetCoordinate = $center->ring(2)[0];
        $target = $this->cellAt($space, $targetCoordinate->x, $targetCoordinate->y);
        $this->setCell($target, 'mountain', 'mine', $nation->id, 0);
        $target->facility_scale = 202;
        $target->save();
        $target = $target->fresh(['terrain', 'facility']);
        $beforeVersion = (int) $target->version;
        $cellIndex = DisasterMutableCellIndex::fromCells([$target], [$nation->id]);
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'rank-two-mine-ring-two'), [$nation->id]);

        $result = app(DisasterTurnService::class)->resolveHugeMeteorBlast(
            $context,
            $space,
            $center,
            $ruleset->settings['turn_processing']['disasters']['huge_meteor'],
            cellIndex: $cellIndex,
        );

        $after = $target->fresh(['terrain', 'facility']);
        $this->assertSame(0, $result);
        $this->assertSame('mountain', $after->terrain->key);
        $this->assertSame('mine', $after->facility?->key);
        $this->assertSame(202, $after->facility_scale);
        $this->assertSame($beforeVersion, (int) $after->version);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'facility.partially_damaged')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
    }

    public function test_huge_meteor_ring_two_treats_zero_scale_loss_as_resistance_without_counting_damage(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('広域爆発零損失国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['facility_rank_system']['definitions']['farm']['damage_scale_loss']['ordinary_terrain_destruction'] = 0;
        });
        $center = $this->boundsFor($world)->center();
        $targetCoordinate = $center->ring(2)[0];
        $target = $this->cellAt($space, $targetCoordinate->x, $targetCoordinate->y);
        $this->setCell($target, 'plain', 'farm', $nation->id, 0);
        $target->facility_scale = 51;
        $target->save();
        $target = $target->fresh(['terrain', 'facility']);
        $beforeVersion = (int) $target->version;
        $cellIndex = DisasterMutableCellIndex::fromCells([$target], [$nation->id]);
        [$context, $run] = $this->context(
            $world,
            $ruleset,
            hash('sha256', 'zero-scale-loss-ring-two'),
            [$nation->id],
        );

        $result = app(DisasterTurnService::class)->resolveHugeMeteorBlast(
            $context,
            $space,
            $center,
            $ruleset->settings['turn_processing']['disasters']['huge_meteor'],
            cellIndex: $cellIndex,
        );

        $after = $target->fresh(['terrain', 'facility']);
        $this->assertSame(0, $result);
        $this->assertSame('plain', $after->terrain->key);
        $this->assertSame('farm', $after->facility?->key);
        $this->assertSame(51, $after->facility_scale);
        $this->assertSame($beforeVersion, (int) $after->version);
        $this->assertSame([], $context->state->changedMapChunkIds());
        $this->assertSame(0, DB::table('audit_events')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->whereIn('event_type', ['facility.partially_damaged', 'disaster.cell_damaged'])
            ->count());
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
            if (is_array($settings['ocean_loop']['npc_ship_spawn']['probability'] ?? null)) {
                $settings['ocean_loop']['npc_ship_spawn']['probability'] = ['numerator' => 0, 'denominator' => 1];
            }
            if (is_array($settings['ocean_loop']['buried_treasure']['natural_spawn']['probability'] ?? null)) {
                $settings['ocean_loop']['buried_treasure']['natural_spawn']['probability'] = [
                    'numerator' => 0, 'denominator' => 1,
                ];
            }
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
