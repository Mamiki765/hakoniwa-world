<?php

namespace Tests\Feature;

use App\Application\BuriedTreasureService;
use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\MonsterTurnService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\SecretaryTurnService;
use App\Application\SurfaceShipTurnService;
use App\Application\WorldExpansionService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Domain\World\MapBounds;
use App\Models\BuriedTreasure;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\NationResource;
use App\Models\NationUndergroundFacility;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

class TurnCellProcessingTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_surface_ship_cell_event_moves_once_settles_rewards_and_applies_fuel_and_lifecycle_rules(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '航行試験国', '航行島主');
        $space = $this->surfaceMapSpace($world);
        $port = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $this->facility($port, 'port', 'plain');
        [$origin, $destination, $later] = $this->eastwardSeaLine($space);
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $origin->id,
            'ship_type_key' => 'fishing',
            'current_hp' => 1,
            'max_hp' => 1,
            'heading' => GridCoordinate::EAST,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $oil = ResourceDefinition::query()->where('key', 'oil')->firstOrFail();
        $fish = ResourceDefinition::query()->where('key', 'fish')->firstOrFail();
        $wheat = ResourceDefinition::query()->where('key', 'wheat')->firstOrFail();
        $foodCapacity = app(NationCapacityResolver::class)
            ->resolve($nation, $world->rulesetVersion()->firstOrFail())->foodTons;
        NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->update(['amount' => 0]);
        NationResource::query()->updateOrCreate(
            ['nation_id' => $nation->id, 'resource_definition_id' => $oil->id],
            ['amount' => 5],
        );
        NationResource::query()->updateOrCreate(
            ['nation_id' => $nation->id, 'resource_definition_id' => $fish->id],
            ['amount' => 0],
        );
        NationResource::query()->updateOrCreate(
            ['nation_id' => $nation->id, 'resource_definition_id' => $wheat->id],
            ['amount' => $foodCapacity],
        );
        $nation->update(['money' => 0]);

        [$context, $run] = $this->context(
            $world,
            $nation,
            [$origin->id, $destination->id, $later->id],
            hash('sha256', 'surface ship one move'),
        );
        $metrics = app(CompleteTurnEngine::class)->execute('process_cells', $context)->metrics;
        app(SecretaryTurnService::class)->flushExperience($context);

        $this->assertSame([1, 1, 1, 0, 4, 0, 7], [
            $metrics['ship_events'], $metrics['ship_moves'], $metrics['ship_secretary_experience'],
            $metrics['ship_fish_applied'],
            (int) NationResource::query()->where('nation_id', $nation->id)
                ->where('resource_definition_id', $oil->id)->value('amount'),
            (int) NationResource::query()->where('nation_id', $nation->id)
                ->where('resource_definition_id', $fish->id)->value('amount'),
            (int) $nation->fresh()->money,
        ]);
        $this->assertSame($foodCapacity, (int) NationResource::query()
            ->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->sum('amount'));
        $moveEvent = $this->event($run, 'ship.moved');
        $this->assertSame([7_000, 0, 7_000], [
            $moveEvent['resource_requested'], $moveEvent['resource_applied'], $moveEvent['resource_overflow'],
        ]);
        $overflowEvent = $this->event($run, 'resource.food_overflow_resolved');
        $this->assertSame(['fish', 7_000, 7_000, 7, 0], [
            $overflowEvent['resource_key'], $overflowEvent['requested_overflow_tons'],
            $overflowEvent['sold_tons'], $overflowEvent['revenue'], $overflowEvent['discarded_tons'],
        ]);
        $this->assertSame($destination->id, $ship->fresh()->map_cell_id);
        $this->assertSame(1, $user->secretary()->firstOrFail()->skills()
            ->where('skill_key', SecretarySkillCatalog::SHIP_OPERATIONS)->value('experience'));

        [$fuelOrigin, $fuelDestination, $fuelLater] = $this->eastwardSeaLine(
            $space,
            [$origin->id, $destination->id, $later->id],
        );
        $fuelShip = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $fuelOrigin->id,
            'ship_type_key' => 'exploration',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => GridCoordinate::EAST,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oil->id)->update(['amount' => 0]);
        $fuelLabel = TurnRandomStreamFactory::shipMovement($fuelShip->id, 'fuel_shortage_damage', 1);
        [$fuelContext] = $this->context(
            $world,
            $nation,
            [$fuelOrigin->id, $fuelDestination->id],
            $this->seedForFirstDraw($fuelLabel, 1, 100, 1),
        );
        $fuelMetrics = app(CompleteTurnEngine::class)->execute('process_cells', $fuelContext)->metrics;
        $this->assertSame([1, 1, 1, $fuelOrigin->id], [
            $fuelMetrics['ship_fuel_shortages'], $fuelMetrics['ship_fuel_damage'],
            $fuelShip->fresh()->current_hp, $fuelShip->fresh()->map_cell_id,
        ]);
        $this->assertContains((int) $fuelOrigin->map_chunk_id, $fuelContext->state->changedMapChunkIds());

        $nation->update([
            'state' => 'recovery',
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
        ]);
        NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oil->id)->update(['amount' => 5]);
        [$pausedContext] = $this->context(
            $world,
            $nation->fresh(),
            [$fuelOrigin->id, $fuelDestination->id],
            hash('sha256', 'recovery ship pause'),
        );
        $paused = app(CompleteTurnEngine::class)->execute('process_cells', $pausedContext)->metrics;
        $this->assertSame([1, 0, 5, $fuelOrigin->id], [
            $paused['ship_events'], $paused['ship_moves'],
            (int) NationResource::query()->where('nation_id', $nation->id)
                ->where('resource_definition_id', $oil->id)->value('amount'),
            $fuelShip->fresh()->map_cell_id,
        ]);

        $nation->update(['state' => 'active', 'state_started_turn' => null, 'resume_at_turn' => null]);
        [$snapshotContext] = $this->context(
            $world,
            $nation->fresh(),
            [$fuelOrigin->id, $fuelDestination->id],
            hash('sha256', 'port availability phase snapshot'),
        );
        $shipService = app(SurfaceShipTurnService::class);
        $shipBatch = $shipService->load($snapshotContext, $space);
        $monsterBatch = app(MonsterTurnService::class)->load($snapshotContext);
        $port = $port->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->setFacility($port, null);
        $port->version++;
        $port->save();
        $cells = MapCell::query()->whereIn('id', [$fuelOrigin->id, $fuelDestination->id])
            ->with(['terrain', 'facility'])->get();
        $cellsByCoordinate = $cells->mapWithKeys(static fn (MapCell $cell): array => [
            $cell->x.':'.$cell->y => $cell,
        ])->all();
        $shipService->processCell(
            $snapshotContext,
            $space,
            $cells->firstWhere('id', $fuelOrigin->id),
            $cellsByCoordinate,
            $monsterBatch,
            $shipBatch,
        );
        $this->assertSame([1, 0, $fuelDestination->id, 4], [
            $shipBatch->metrics()['ship_moves'],
            $shipBatch->metrics()['ship_no_port'],
            $fuelShip->fresh()->map_cell_id,
            (int) NationResource::query()->where('nation_id', $nation->id)
                ->where('resource_definition_id', $oil->id)->value('amount'),
        ]);

        [$nextTurnContext] = $this->context(
            $world,
            $nation->fresh(),
            [$fuelDestination->id, $fuelLater->id],
            hash('sha256', 'port loss applies next turn'),
        );
        $afterPortLoss = app(CompleteTurnEngine::class)->execute('process_cells', $nextTurnContext)->metrics;
        $this->assertSame([1, 0, 1, $fuelDestination->id, 4], [
            $afterPortLoss['ship_events'],
            $afterPortLoss['ship_moves'],
            $afterPortLoss['ship_no_port'],
            $fuelShip->fresh()->map_cell_id,
            (int) NationResource::query()->where('nation_id', $nation->id)
                ->where('resource_definition_id', $oil->id)->value('amount'),
        ]);
    }

    public function test_npc_surface_ships_drift_without_port_oil_reward_or_secretary_experience(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'NPC漂流確認国', 'NPC漂流確認島主');
        $space = $this->surfaceMapSpace($world);
        [$pirateOrigin, $pirateDestination, $pirateLater] = $this->eastwardSeaLine($space);
        [$treasureOrigin, $treasureDestination] = $this->eastwardSeaLine(
            $space,
            [$pirateOrigin->id, $pirateDestination->id, $pirateLater->id],
        );
        $pirate = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $pirateOrigin->id,
            'ship_type_key' => 'pirate',
            'current_hp' => 2,
            'max_hp' => 3,
            'population' => 7_500,
            'heading' => GridCoordinate::WEST,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $treasure = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $treasureOrigin->id,
            'ship_type_key' => 'treasure',
            'current_hp' => 1,
            'max_hp' => 1,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $oil = ResourceDefinition::query()->where('key', 'oil')->firstOrFail();
        $oilBefore = (int) NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oil->id)->value('amount');
        $moneyBefore = (int) $nation->money;
        $experienceBefore = (int) $user->secretary()->firstOrFail()->skills()
            ->where('skill_key', SecretarySkillCatalog::SHIP_OPERATIONS)->value('experience');
        $this->assertFalse(MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('facility', fn ($query) => $query->where('key', 'port'))->exists());

        [$context, $run] = $this->context(
            $world,
            $nation,
            [$pirateOrigin->id, $pirateDestination->id, $treasureOrigin->id, $treasureDestination->id],
            hash('sha256', 'npc ship random drift'),
        );
        $ships = app(SurfaceShipTurnService::class);
        $shipBatch = $ships->load($context, $space);
        $monsterBatch = app(MonsterTurnService::class)->load($context);
        foreach ([
            [$pirateOrigin, $pirateDestination],
            [$treasureOrigin, $treasureDestination],
        ] as [$origin, $destination]) {
            $cells = collect([$origin, $destination])->map(
                static fn (MapCell $cell): MapCell => $cell->fresh(['terrain', 'facility']),
            );
            $ships->processCell(
                $context,
                $space,
                $cells->first(),
                $cells->mapWithKeys(static fn (MapCell $cell): array => [
                    $cell->x.':'.$cell->y => $cell,
                ])->all(),
                $monsterBatch,
                $shipBatch,
            );
        }
        app(SecretaryTurnService::class)->flushExperience($context);

        $this->assertSame([$pirateDestination->id, $treasureDestination->id], [
            $pirate->fresh()->map_cell_id,
            $treasure->fresh()->map_cell_id,
        ]);
        $this->assertSame([2, 2, 0, 0, 0, 0, 0], [
            $shipBatch->metrics()['ship_events'],
            $shipBatch->metrics()['ship_moves'],
            $shipBatch->metrics()['ship_no_port'],
            $shipBatch->metrics()['ship_fuel_shortages'],
            $shipBatch->metrics()['ship_oil_consumed'],
            $shipBatch->metrics()['ship_fish_applied'],
            $shipBatch->metrics()['ship_secretary_experience'],
        ]);
        $this->assertSame([$oilBefore, $moneyBefore, $experienceBefore], [
            (int) NationResource::query()->where('nation_id', $nation->id)
                ->where('resource_definition_id', $oil->id)->value('amount'),
            (int) $nation->fresh()->money,
            (int) $user->secretary()->firstOrFail()->skills()
                ->where('skill_key', SecretarySkillCatalog::SHIP_OPERATIONS)->value('experience'),
        ]);
        $events = DB::table('audit_events')->where('event_type', 'ship.moved')
            ->whereIn('subject_id', [$pirate->id, $treasure->id])->orderBy('subject_id')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);
            $this->assertNull($event->nation_id);
            $this->assertSame('public', $event->visibility);
            $this->assertSame([null, 0, 0, 0], [
                $metadata['resource_key'],
                $metadata['oil_consumed'],
                $metadata['resource_applied'],
                $metadata['money_applied'],
            ]);
            $this->assertSame($run->id, $metadata['turn_run_id']);
        }
    }

    public function test_exploration_ship_chases_a_visible_treasure_and_collects_it_after_moving(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '探索国', '探索島主');
        $space = $this->surfaceMapSpace($world);
        $port = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->firstOrFail();
        $this->facility($port, 'port', 'plain');
        [$origin] = $this->eastwardSeaLine($space);
        $neighbors = collect((new GridCoordinate($origin->x, $origin->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        ))->map(fn (GridCoordinate $coordinate): ?MapCell => MapCell::query()
            ->where('map_space_id', $space->id)->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')->where('population', 0)
            ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->with(['terrain', 'facility'])->first())->filter()->values();
        $this->assertGreaterThanOrEqual(2, $neighbors->count());
        /** @var MapCell $treasureCell */
        $treasureCell = $neighbors->first();
        /** @var MapCell $alternative */
        $alternative = $neighbors->get(1);
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $origin->id,
            'ship_type_key' => 'exploration',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $oil = ResourceDefinition::query()->where('key', 'oil')->firstOrFail();
        NationResource::query()->updateOrCreate(
            ['nation_id' => $nation->id, 'resource_definition_id' => $oil->id],
            ['amount' => 2],
        );
        [$context] = $this->context(
            $world,
            $nation,
            [$origin->id, $treasureCell->id, $alternative->id],
            hash('sha256', 'exploration ship visible treasure'),
        );
        $treasure = app(BuriedTreasureService::class)->create(
            $context,
            $treasureCell,
            'natural',
            false,
        );
        $ships = app(SurfaceShipTurnService::class);
        $shipBatch = $ships->load($context, $space);
        $monsterBatch = app(MonsterTurnService::class)->load($context);
        $cells = collect([$origin, $treasureCell, $alternative]);

        $ships->processCell(
            $context,
            $space,
            $origin->fresh(['terrain', 'facility']),
            $cells->mapWithKeys(static fn (MapCell $cell): array => [
                $cell->x.':'.$cell->y => $cell->fresh(['terrain', 'facility']),
            ])->all(),
            $monsterBatch,
            $shipBatch,
        );

        $this->assertSame($treasureCell->id, $ship->fresh()->map_cell_id);
        $this->assertSame(BuriedTreasure::STATE_COLLECTED, $treasure->fresh()->state);
        $this->assertSame(1, NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oil->id)->value('amount'));
        $this->assertTrue($user->secretary()->firstOrFail()->itemInstances()
            ->where('item_key', 'wakuwaku_ticket')->exists());
    }

    public function test_exploration_ship_ignores_treasure_outside_its_visibility_and_uses_normal_movement(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '遠景探索国', '遠景探索島主');
        $space = $this->surfaceMapSpace($world);
        $port = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->firstOrFail();
        $this->facility($port, 'port', 'plain');
        [$origin, $east, $west, $remote] = $this->remoteTreasureRoute($space);
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $origin->id,
            'ship_type_key' => 'exploration',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $oil = ResourceDefinition::query()->where('key', 'oil')->firstOrFail();
        NationResource::query()->updateOrCreate(
            ['nation_id' => $nation->id, 'resource_definition_id' => $oil->id],
            ['amount' => 2],
        );
        $seed = $this->seedForFirstDraw(
            TurnRandomStreamFactory::shipMovement($ship->id, 'candidate', 1),
            0,
            1,
            1,
        );
        [$context] = $this->context(
            $world,
            $nation,
            [$origin->id, $east->id, $west->id, $remote->id],
            $seed,
        );
        $treasure = app(BuriedTreasureService::class)->create($context, $remote, 'natural', false);
        $ships = app(SurfaceShipTurnService::class);
        $shipBatch = $ships->load($context, $space);
        $monsterBatch = app(MonsterTurnService::class)->load($context);
        $cells = collect([$origin, $east, $west, $remote]);

        $ships->processCell(
            $context,
            $space,
            $origin->fresh(['terrain', 'facility']),
            $cells->mapWithKeys(static fn (MapCell $cell): array => [
                $cell->x.':'.$cell->y => $cell->fresh(['terrain', 'facility']),
            ])->all(),
            $monsterBatch,
            $shipBatch,
        );

        $this->assertSame(4, (new GridCoordinate($origin->x, $origin->y))->distanceTo(
            new GridCoordinate($remote->x, $remote->y),
        ));
        $this->assertSame($west->id, $ship->fresh()->map_cell_id);
        $this->assertSame(BuriedTreasure::STATE_ACTIVE, $treasure->fresh()->state);
    }

    public function test_pirate_stationary_attack_halves_a_uniformly_eligible_settlement_and_keeps_the_population(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '海賊被害国', '海賊被害島主');
        $space = $this->surfaceMapSpace($world);
        [$settlement, $origin] = $this->ownedLandWithSeaNeighbor($space, $nation);
        $this->facility($settlement, 'town', 'plain');
        $settlement->update(['population' => 10_000]);
        $pirate = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $origin->id,
            'ship_type_key' => 'pirate',
            'current_hp' => 2,
            'max_hp' => 3,
            'population' => 7_500,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $seed = $this->seedForFirstDraw(
            TurnRandomStreamFactory::pirateAttack($pirate->id, 'trigger', 1),
            0,
            1,
            0,
        );
        [$context] = $this->context($world, $nation, [$origin->id, $settlement->id], $seed);
        $ships = app(SurfaceShipTurnService::class);
        $shipBatch = $ships->load($context, $space);
        $monsterBatch = app(MonsterTurnService::class)->load($context);
        $cells = collect([$origin, $settlement])->map(
            static fn (MapCell $cell): MapCell => $cell->fresh(['terrain', 'facility']),
        );

        $ships->processCell(
            $context,
            $space,
            $cells->first(),
            $cells->mapWithKeys(static fn (MapCell $cell): array => [
                $cell->x.':'.$cell->y => $cell,
            ])->all(),
            $monsterBatch,
            $shipBatch,
        );

        $this->assertSame(5_000, $settlement->fresh()->population);
        $this->assertSame(12_500, $pirate->fresh()->population);
        $this->assertSame($origin->id, $pirate->fresh()->map_cell_id);
        $attack = DB::table('audit_events')->where('event_type', 'ship.pirate_attacked')->sole();
        $metadata = json_decode($attack->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('settlement', $metadata['target_type']);
        $this->assertSame(5_000, $metadata['stolen_population']);
    }

    public function test_pirate_attack_preserves_the_capital_population_minimum(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '首都防衛国',
            '首都防衛島主',
        );
        $space = $this->surfaceMapSpace($world);
        $capital = $nation->capital()->with('cell.terrain', 'cell.facility')->firstOrFail()->cell;
        $capital->update(['population' => 150]);
        $originCoordinate = (new GridCoordinate($capital->x, $capital->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        )[0];
        $origin = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $originCoordinate->x)->where('y', $originCoordinate->y)->firstOrFail();
        $this->mutateCell($origin, 'sea', null, 0);
        $origin->update(['owner_nation_id' => null]);
        $pirate = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $origin->id,
            'ship_type_key' => 'pirate',
            'current_hp' => 2,
            'max_hp' => 3,
            'population' => 7_500,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $seed = $this->seedForFirstDraw(
            TurnRandomStreamFactory::pirateAttack($pirate->id, 'trigger', 1),
            0,
            1,
            0,
        );
        [$context] = $this->context($world, $nation, [$origin->id, $capital->id], $seed);
        $ships = app(SurfaceShipTurnService::class);
        $shipBatch = $ships->load($context, $space);
        $monsterBatch = app(MonsterTurnService::class)->load($context);
        $cells = collect([$origin, $capital])->map(
            static fn (MapCell $cell): MapCell => $cell->fresh(['terrain', 'facility']),
        );

        $ships->processCell(
            $context,
            $space,
            $cells->first(),
            $cells->mapWithKeys(static fn (MapCell $cell): array => [
                $cell->x.':'.$cell->y => $cell,
            ])->all(),
            $monsterBatch,
            $shipBatch,
        );

        $this->assertSame(100, $capital->fresh()->population);
        $this->assertSame(7_550, $pirate->fresh()->population);
        $attack = DB::table('audit_events')->where('event_type', 'ship.pirate_attacked')->sole();
        $metadata = json_decode($attack->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(50, $metadata['stolen_population']);
    }

    public function test_stationary_warship_auto_attack_sinks_npc_ship_for_money_navy_exp_and_full_refugees(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '海軍国', '海軍島主');
        $space = $this->surfaceMapSpace($world);
        $port = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->firstOrFail();
        $this->facility($port, 'port', 'plain');
        [$origin, $playerCell, $targetCell] = $this->eastwardSeaLine($space);
        $originCoordinate = new GridCoordinate($origin->x, $origin->y);
        $treasureCell = MapCell::query()->where('map_space_id', $space->id)
            ->whereNotIn('id', [$origin->id, $playerCell->id, $targetCell->id])
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')->where('population', 0)
            ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->get()->first(static fn (MapCell $cell): bool => $originCoordinate->distanceTo(
                new GridCoordinate($cell->x, $cell->y),
            ) <= 5);
        if (! $treasureCell instanceof MapCell) {
            $this->fail('Surface test map did not provide a second NPC target within Warship range.');
        }
        $warship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $origin->id,
            'ship_type_key' => 'warship',
            'current_hp' => 3,
            'max_hp' => 3,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $treasureShip = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $treasureCell->id,
            'ship_type_key' => 'treasure',
            'current_hp' => 1,
            'max_hp' => 1,
            'population' => null,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $playerShip = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $playerCell->id,
            'ship_type_key' => 'tourist',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $pirate = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $targetCell->id,
            'ship_type_key' => 'pirate',
            'current_hp' => 1,
            'max_hp' => 3,
            'population' => 6_000,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $oil = ResourceDefinition::query()->where('key', 'oil')->firstOrFail();
        $oilBefore = (int) NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oil->id)->value('amount');
        $populationBefore = (int) MapCell::query()->where('owner_nation_id', $nation->id)->sum('population');
        $nation->update(['money' => 100]);
        [$context] = $this->context(
            $world,
            $nation,
            [$origin->id, $playerCell->id, $targetCell->id, $treasureCell->id],
            hash('sha256', 'stationary-warship-auto-attack'),
        );
        $ships = app(SurfaceShipTurnService::class);
        $shipBatch = $ships->load($context, $space);
        $monsterBatch = app(MonsterTurnService::class)->load($context);
        $cells = collect([$origin, $playerCell, $targetCell, $treasureCell])->map(
            static fn (MapCell $cell): MapCell => $cell->fresh(['terrain', 'facility']),
        );

        $ships->processCell(
            $context,
            $space,
            $cells->first(),
            $cells->mapWithKeys(static fn (MapCell $cell): array => [
                $cell->x.':'.$cell->y => $cell,
            ])->all(),
            $monsterBatch,
            $shipBatch,
        );
        app(SecretaryTurnService::class)->flushExperience($context);

        $this->assertSame(Ship::STATE_REMOVED, $pirate->fresh()->state);
        $this->assertSame(Ship::STATE_ACTIVE, $treasureShip->fresh()->state);
        $this->assertSame([Ship::STATE_ACTIVE, 2], [$playerShip->fresh()->state, $playerShip->fresh()->current_hp]);
        $this->assertSame(80, $nation->fresh()->money);
        $this->assertSame($populationBefore + 6_000, (int) MapCell::query()
            ->where('owner_nation_id', $nation->id)->sum('population'));
        $this->assertSame($oilBefore, (int) NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $oil->id)->value('amount'));
        $this->assertSame(3, $user->secretary()->firstOrFail()->skills()
            ->where('skill_key', SecretarySkillCatalog::NAVY)->value('experience'));
        $treasure = BuriedTreasure::query()->where('map_cell_id', $targetCell->id)
            ->where('state', BuriedTreasure::STATE_ACTIVE)->sole();
        $this->assertSame('pirate_sink', $treasure->source);
        $this->assertSame('wakuwaku_ticket', $treasure->reward_snapshot['item_key']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ship.warship_attacked')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'buried_treasure.created')
            ->where('visibility', 'public')->count());
        $this->assertSame($warship->id, DB::table('audit_events')->where('event_type', 'ship.warship_attacked')
            ->value('subject_id'));

        $nation->update(['money' => 19]);
        [$poorContext] = $this->context(
            $world,
            $nation->fresh(),
            [$origin->id, $playerCell->id, $treasureCell->id],
            hash('sha256', 'stationary-warship-insufficient-funds'),
        );
        $poorBatch = $ships->load($poorContext, $space);
        $poorMonsterBatch = app(MonsterTurnService::class)->load($poorContext);
        $poorCells = collect([$origin, $playerCell, $treasureCell])->map(
            static fn (MapCell $cell): MapCell => $cell->fresh(['terrain', 'facility']),
        );

        $ships->processCell(
            $poorContext,
            $space,
            $poorCells->first(),
            $poorCells->mapWithKeys(static fn (MapCell $cell): array => [
                $cell->x.':'.$cell->y => $cell,
            ])->all(),
            $poorMonsterBatch,
            $poorBatch,
        );

        $this->assertSame(19, $nation->fresh()->money);
        $this->assertSame(Ship::STATE_ACTIVE, $treasureShip->fresh()->state);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ship.warship_attacked')->count());
    }

    public function test_warship_refugees_remain_in_turn_local_settlement_before_later_population_growth(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '難民同期国',
            '難民同期島主',
        );
        $space = $this->surfaceMapSpace($world);
        $port = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->firstOrFail();
        $this->facility($port, 'port', 'plain');
        [$warshipCell, $pirateCell] = $this->eastwardSeaLine($space);
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $capital->update(['population' => 1_000]);
        Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $warshipCell->id,
            'ship_type_key' => 'warship',
            'current_hp' => 3,
            'max_hp' => 3,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $pirateCell->id,
            'ship_type_key' => 'pirate',
            'current_hp' => 1,
            'max_hp' => 3,
            'population' => 8_000,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $nation->update(['money' => 100]);
        [$context, $run] = $this->context(
            $world,
            $nation,
            [$warshipCell->id, $pirateCell->id, $capital->id],
            $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 1_000, 100),
        );
        $settings = $context->ruleset->settings;
        $settings['turn_processing']['disasters']['fire']['probability'] = ['numerator' => 0, 'denominator' => 1];
        $context->ruleset->settings = $settings;

        $metrics = app(CompleteTurnEngine::class)->execute('process_cells', $context)->metrics;

        $this->assertSame(100, $metrics['population_increased']);
        $this->assertSame(9_100, $capital->fresh()->population);
        $this->assertSame(8_000, $this->event($run, 'refugee_received')['received_population']);
        $this->assertSame(100, $context->state->routineSummaryMetrics($nation->id)['population_growth']);
    }

    public function test_sequential_settlement_growth_famine_riot_and_forest_processing(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'セル処理国', '試験島主');
        $engine = app(CompleteTurnEngine::class);
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$firstCandidate, $secondCandidate] = $this->sequentialCandidates($nation, $capital);
        $forestCells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->whereNotIn('id', [$firstCandidate->id, $secondCandidate->id])
            ->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(1, $forestCells->count());

        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        MapCell::query()->whereKey($capital->id)->update(['population' => 1_000]);
        $this->plain($firstCandidate);
        $this->plain($secondCandidate);
        $settlementSeed = $this->seedForSettlementSequence(19, 19);
        [$appearanceContext, $appearanceRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id, $secondCandidate->id],
            $settlementSeed,
        );
        $appearance = $engine->execute('process_cells', $appearanceContext);
        $this->assertSame(2, $appearance->metrics['settlements_appeared']);
        $this->assertSame(100, $firstCandidate->fresh()->population);
        $this->assertSame('village', $firstCandidate->fresh()->facility()->value('key'));
        $this->assertSame(100, $secondCandidate->fresh()->population);
        $this->assertSame('village', $secondCandidate->fresh()->facility()->value('key'));
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'settlement.appeared')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $appearanceRun->id])->count());

        $this->plain($firstCandidate);
        $this->plain($secondCandidate);
        MapCell::query()->whereKey($capital->id)->update(['population' => 1_000]);
        $failureSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::SETTLEMENT_APPEARANCE, 0, 99, 20);
        [$failureContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $failureSeed,
        );
        $failure = $engine->execute('process_cells', $failureContext);
        $this->assertSame(0, $failure->metrics['settlements_appeared']);
        $this->assertSame(0, $firstCandidate->fresh()->population);

        MapCell::query()->whereKey($capital->id)->update(['population' => 0]);
        $this->plain($secondCandidate);
        [$noNeighborContext] = $this->context(
            $world,
            $nation,
            [$secondCandidate->id],
            $this->seedForFirstDraw(TurnRandomStreamFactory::SETTLEMENT_APPEARANCE, 0, 99, 19),
        );
        $noNeighbor = $engine->execute('process_cells', $noNeighborContext);
        $this->assertSame(0, $noNeighbor->metrics['settlements_appeared']);
        $this->assertSame(0, $secondCandidate->fresh()->population);

        $growthSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 1_000, 100);
        $this->settlement($firstCandidate, 'village', 2_900);
        [$townContext, $townRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $growthSeed,
        );
        $town = $engine->execute('process_cells', $townContext);
        $this->assertSame(100, $town->metrics['population_increased']);
        $this->assertSame(1, $town->metrics['stage_transitions']);
        $this->assertSame(3_000, $firstCandidate->fresh()->population);
        $this->assertSame('town', $firstCandidate->fresh()->facility()->value('key'));
        $this->assertSame('town', $this->event($townRun, 'settlement.stage_transitioned')['to_facility_key']);
        $presentedCells = $this->actingAs($user)
            ->getJson("/api/v1/map-spaces/{$space->id}/chunks/{$firstCandidate->chunk_x}/{$firstCandidate->chunk_y}")
            ->assertOk()->json('data.cells');
        $this->assertIsArray($presentedCells);
        /** @var list<array<string, mixed>> $presentedCells */
        $presentedTown = null;
        foreach ($presentedCells as $presentedCell) {
            if ($presentedCell['x'] === $firstCandidate->x && $presentedCell['y'] === $firstCandidate->y) {
                $presentedTown = $presentedCell;
                break;
            }
        }
        $this->assertIsArray($presentedTown);
        $this->assertSame('town', $presentedTown['facility']);
        $this->assertIsArray($presentedTown['details']);
        /** @var list<array<string, mixed>> $presentedDetails */
        $presentedDetails = $presentedTown['details'];
        $populationDetail = null;
        foreach ($presentedDetails as $presentedDetail) {
            if ($presentedDetail['key'] === 'population') {
                $populationDetail = $presentedDetail;
                break;
            }
        }
        $this->assertIsArray($populationDetail);
        $this->assertSame(3_000, $populationDetail['value']);

        $this->settlement($firstCandidate, 'town', 9_900);
        [$cityContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $growthSeed,
        );
        $engine->execute('process_cells', $cityContext);
        $this->assertSame(10_000, $firstCandidate->fresh()->population);
        $this->assertSame('city', $firstCandidate->fresh()->facility()->value('key'));

        $this->settlement($firstCandidate, 'town', 9_950);
        [$maximumContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $growthSeed,
        );
        $engine->execute('process_cells', $maximumContext);
        $this->assertSame(10_000, $firstCandidate->fresh()->population);
        $this->assertSame('city', $firstCandidate->fresh()->facility()->value('key'));

        $this->settlement($capital, 'capital', 1_000);
        $capitalGrowthSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 1_000, 100);
        [$capitalContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $engine->execute('process_cells', $capitalContext);
        $this->assertSame(1_100, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));

        $lossSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::FAMINE_POPULATION_LOSS, 100, 3_000, 100);
        $this->settlement($firstCandidate, 'city', 10_050);
        [$famineStageContext, $famineStageRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $lossSeed,
            true,
        );
        $famineStage = $engine->execute('process_cells', $famineStageContext);
        $this->assertSame(100, $famineStage->metrics['population_decreased']);
        $this->assertSame(1, $famineStage->metrics['stage_transitions']);
        $this->assertSame(9_950, $firstCandidate->fresh()->population);
        $this->assertSame('town', $firstCandidate->fresh()->facility()->value('key'));
        $this->assertSame('town', $this->event($famineStageRun, 'settlement.stage_transitioned')['to_facility_key']);

        $this->settlement($firstCandidate, 'village', 100);
        [$famineContext, $famineRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $lossSeed,
            true,
        );
        $famine = $engine->execute('process_cells', $famineContext);
        $this->assertSame(100, $famine->metrics['population_decreased']);
        $this->assertSame(0, $firstCandidate->fresh()->population);
        $this->assertNull($firstCandidate->fresh()->facility_definition_id);
        $this->assertSame('plain', $firstCandidate->fresh()->terrain()->value('key'));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'famine.applied')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $famineRun->id])->count());

        $this->settlement($capital, 'capital', 50);
        [$capitalFamineContext, $capitalFamineRun] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $lossSeed,
            true,
        );
        $capitalFamine = $engine->execute('process_cells', $capitalFamineContext);
        $capitalFamineEvent = $this->event($capitalFamineRun, 'famine.applied');
        $this->assertSame(0, $capitalFamine->metrics['population_decreased']);
        $this->assertSame(100, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));
        $this->assertSame(0, $capitalFamineEvent['actual_loss']);
        $this->assertTrue($capitalFamineEvent['minimum_population_applied']);
        $this->assertSame(50, $capitalFamineEvent['minimum_population_adjustment']);

        [$capitalRecoveryContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $capitalRecovery = $engine->execute('process_cells', $capitalRecoveryContext);
        $this->assertSame(100, $capitalRecovery->metrics['population_increased']);
        $this->assertSame(200, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));

        $this->settlement($capital, 'capital', 24_950);
        [$capitalMaximumContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $capitalMaximum = $engine->execute('process_cells', $capitalMaximumContext);
        $this->assertSame(50, $capitalMaximum->metrics['population_increased']);
        $this->assertSame(25_000, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));

        $capitalScale = $capital->fresh()->facility_scale;
        $firstUndergroundCity = NationUndergroundFacility::query()->create([
            'nation_id' => $nation->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 1,
            'slot_index' => 0,
            'facility_key' => 'underground_city',
        ]);
        $this->settlement($capital, 'capital', 34_950);
        [$oneUndergroundCityContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $oneUndergroundCity = $engine->execute('process_cells', $oneUndergroundCityContext);
        $this->assertSame(50, $oneUndergroundCity->metrics['population_increased']);
        $this->assertSame(35_000, $capital->fresh()->population);

        NationUndergroundFacility::query()->create([
            'nation_id' => $nation->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 1,
            'slot_index' => 1,
            'facility_key' => 'underground_city',
        ]);
        $this->settlement($capital, 'capital', 44_950);
        [$twoUndergroundCitiesContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $twoUndergroundCities = $engine->execute('process_cells', $twoUndergroundCitiesContext);
        $this->assertSame(50, $twoUndergroundCities->metrics['population_increased']);
        $this->assertSame(45_000, $capital->fresh()->population);
        $this->assertSame($capitalScale, $capital->fresh()->facility_scale);

        $firstUndergroundCity->delete();
        [$removedUndergroundCityContext, $removedUndergroundCityRun] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $removedUndergroundCity = $engine->execute('process_cells', $removedUndergroundCityContext);
        $this->assertSame(0, $removedUndergroundCity->metrics['population_increased']);
        $this->assertSame(100, $removedUndergroundCity->metrics['population_decreased']);
        $this->assertSame(44_900, $capital->fresh()->population);
        $decline = $this->event($removedUndergroundCityRun, 'population.decreased');
        $this->assertSame('above_effective_capital_maximum', $decline['reason']);
        $this->assertSame(35_000, $decline['effective_capital_maximum']);
        $this->assertSame(100, $decline['actual_loss']);

        [$subsequentDeclineContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $subsequentDecline = $engine->execute('process_cells', $subsequentDeclineContext);
        $this->assertSame(100, $subsequentDecline->metrics['population_decreased']);
        $this->assertSame(44_800, $capital->fresh()->population);

        $this->settlement($capital, 'capital', 35_000);
        [$atEffectiveMaximumContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $atEffectiveMaximum = $engine->execute('process_cells', $atEffectiveMaximumContext);
        $this->assertSame(0, $atEffectiveMaximum->metrics['population_increased']);
        $this->assertSame(0, $atEffectiveMaximum->metrics['population_decreased']);
        $this->assertSame(35_000, $capital->fresh()->population);

        NationUndergroundFacility::query()->create([
            'nation_id' => $nation->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 1,
            'slot_index' => 0,
            'facility_key' => 'underground_city',
        ]);
        [$rebuiltUndergroundCityContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
        );
        $rebuiltUndergroundCity = $engine->execute('process_cells', $rebuiltUndergroundCityContext);
        $this->assertSame(100, $rebuiltUndergroundCity->metrics['population_increased']);
        $this->assertSame(0, $rebuiltUndergroundCity->metrics['population_decreased']);
        $this->assertSame(35_100, $capital->fresh()->population);
        $this->assertSame($capitalScale, $capital->fresh()->facility_scale);

        $riotCells = [$firstCandidate, $secondCandidate, $this->ownedEmptyCell($nation, [$capital->id, $firstCandidate->id, $secondCandidate->id])];
        foreach (['farm', 'factory', 'missile_base'] as $index => $facilityKey) {
            $this->facility($riotCells[$index], $facilityKey, 'plain');
        }
        $riotSeed = $this->seedForRepeatedDraw(TurnRandomStreamFactory::FACILITY_RIOT, 0, 3, [0, 0, 0]);
        [$riotContext, $riotRun] = $this->context(
            $world,
            $nation,
            array_map(static fn (MapCell $cell): int => $cell->id, $riotCells),
            $riotSeed,
            true,
        );
        $riot = $engine->execute('process_cells', $riotContext);
        $this->assertSame(3, $riot->metrics['riots']);
        foreach ($riotCells as $cell) {
            $this->assertSame('wasteland', $cell->fresh()->terrain()->value('key'));
            $this->assertNull($cell->fresh()->facility_definition_id);
        }
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'facility.riot')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $riotRun->id])->count());

        $this->facility($firstCandidate, 'farm', 'plain');
        $riotFailureSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::FACILITY_RIOT, 0, 3, 1);
        [$riotFailureContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $riotFailureSeed,
            true,
        );
        $engine->execute('process_cells', $riotFailureContext);
        $this->assertSame('farm', $firstCandidate->fresh()->facility()->value('key'));

        $forest = $forestCells->firstOrFail();
        $this->forest($forest, 500);
        $forest = $forest->fresh(['terrain', 'facility']);
        $forestBefore = $forest->terrain_quantity;
        $user->secretary()->firstOrFail()->skills()
            ->where('skill_key', SecretarySkillCatalog::FOREST_MANAGEMENT)
            ->update(['level' => 10, 'experience' => 0]);
        [$forestContext] = $this->context($world, $nation, [$forest->id], str_repeat('e', 64));
        $forestGrowth = $engine->execute('process_cells', $forestContext);
        $this->assertSame(1, $forestGrowth->metrics['forest_growth']);
        $this->assertSame($forestBefore + 110, $forest->fresh()->terrain_quantity);
        $this->assertSame([], $forestContext->state->pendingSecretaryExperience());
        $forestRoutine = $forestContext->state->routineSummaryMetrics($nation->id);
        $this->assertSame(1, $forestRoutine['forest_growth_cells']);
        $this->assertSame(110, $forestRoutine['forest_growth_quantity']);

        $maximumTrees = $world->rulesetVersion()->firstOrFail()->settings['terrain_quantities']['forest']['maximum_quantity'];
        $forest->fresh()->update(['terrain_quantity' => $maximumTrees - 50]);
        [$forestMaximumContext] = $this->context($world, $nation, [$forest->id], str_repeat('f', 64));
        $engine->execute('process_cells', $forestMaximumContext);
        $this->assertSame($maximumTrees, $forest->fresh()->terrain_quantity);

        $landClearTarget = $forestCells->firstWhere('id', '!=', $forest->id) ?? $this->ownedEmptyCell(
            $nation,
            [$forest->id, $capital->id, $firstCandidate->id, $secondCandidate->id],
        );
        $this->forest($landClearTarget, 500);
        $landClearItem = $this->queueLandClear($user, $nation, $space, $landClearTarget);
        [$commandContext, $commandRun] = $this->context(
            $world,
            $nation,
            [$landClearTarget->id],
            str_repeat('1', 64),
        );
        app(DomesticCommandExecutor::class)->execute($commandContext);
        $afterCommand = $engine->execute('process_cells', $commandContext);
        $this->assertSame('completed', $landClearItem->fresh()->status);
        $this->assertSame('plain', $landClearTarget->fresh()->terrain()->value('key'));
        $this->assertSame(0, $afterCommand->metrics['forest_growth']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'forest.grown')
            ->where('subject_id', $landClearTarget->id)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $commandRun->id])->count());
    }

    public function test_nyowamiya_prepass_tramples_rank_two_facility_without_repopulating_its_occupied_cell(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'ニョワミヤ村発生国', 'ニョワミヤ村発生島主');
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        $settings['turn_processing']['settlement']['appearance_probability'] = [
            'numerator' => 1,
            'denominator' => 1,
        ];
        $ruleset->update(['settings' => $settings]);
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        [$origin, $destination] = $this->sequentialCandidates($nation, $capital);
        $this->mutateCell($origin, 'wasteland', null, 0);
        $this->mutateCell($destination, 'plain', 'farm', 0);
        $destination->fresh()->update(['facility_scale' => 2]);
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $settlementNeighborCoordinate = collect((new GridCoordinate($destination->x, $destination->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        ))->first(fn (GridCoordinate $coordinate): bool => ! (
            ($coordinate->x === $origin->x && $coordinate->y === $origin->y)
            || ($coordinate->x === $capital->x && $coordinate->y === $capital->y)
        ));
        $this->assertInstanceOf(GridCoordinate::class, $settlementNeighborCoordinate);
        $settlementNeighbor = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $settlementNeighborCoordinate->x)
            ->where('y', $settlementNeighborCoordinate->y)
            ->firstOrFail();
        $settlementNeighbor->update(['owner_nation_id' => $nation->id]);
        $this->mutateCell($settlementNeighbor, 'plain', 'farm', 0);

        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'nyowamiya')
            ->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $origin->id,
        ]);
        $direction = collect(range(0, 5))->first(
            function (int $candidate) use ($origin, $destination): bool {
                $neighbor = (new GridCoordinate($origin->x, $origin->y))->neighbor($candidate);

                return $neighbor->x === $destination->x && $neighbor->y === $destination->y;
            },
        );
        $this->assertIsInt($direction);
        $seed = $this->seedForNyoMovementAndSettlement($monster->id, $direction);
        [$context] = $this->context(
            $world,
            $nation,
            [$origin->id, $destination->id, $settlementNeighbor->id],
            $seed,
            ruleset: $ruleset,
        );

        $result = app(CompleteTurnEngine::class)->execute('process_cells', $context);

        $destination = $destination->fresh(['terrain', 'facility']);
        $this->assertSame($destination->id, $monster->fresh()->occupancy()->value('map_cell_id'));
        $this->assertSame('plain', $destination->terrain->key);
        $this->assertNull($destination->facility_definition_id);
        $this->assertNull($destination->facility_scale);
        $this->assertSame(0, $destination->population);
        $this->assertSame(0, $result->metrics['settlements_appeared']);
        $this->assertSame(1, $result->metrics['monster_actions']);
        $this->assertSame(1, $result->metrics['monster_moves']);
        $this->assertSame(1, $result->metrics['cells_trampled']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'settlement.appeared')->count());
    }

    public function test_demographic_skills_raise_noncapital_limits_add_natural_growth_and_normalize_over_cap_population_without_queries(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '人口政策国', '人口政策島主');
        $capital = $nation->capital()->firstOrFail()->cell()->firstOrFail();
        $cells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capital->id)->orderBy('id')->limit(3)->get();
        $this->assertCount(3, $cells);
        [$growthCell, $nearMaximum, $farAboveMaximum] = $cells->all();
        $secretary = $user->secretary()->firstOrFail();
        $secretary->skills()->whereIn('skill_key', [
            SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY,
            SecretarySkillCatalog::INDOMITABLE,
        ])->update(['level' => 10, 'experience' => 0]);

        $this->settlement($growthCell, 'city', 9_000);
        $this->settlement($nearMaximum, 'city', 21_050);
        $this->settlement($farAboveMaximum, 'city', 25_000);
        $this->settlement($capital, 'capital', 25_000);
        [$context, $run] = $this->context(
            $world,
            $nation,
            [$growthCell->id, $nearMaximum->id, $farAboveMaximum->id, $capital->id],
            $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 1_000, 100),
        );
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = app(CompleteTurnEngine::class)->execute('process_cells', $context);
        $secretaryQueries = array_values(array_filter(
            $queries,
            static fn (string $query): bool => str_contains($query, 'secretar'),
        ));

        $this->assertSame([], $secretaryQueries);
        $this->assertSame(325, $result->metrics['population_increased']);
        $this->assertSame(150, $result->metrics['population_decreased']);
        $this->assertSame(9_325, $growthCell->fresh()->population);
        $populationRoutine = $context->state->routineSummaryMetrics($nation->id);
        $this->assertSame(1, $populationRoutine['population_growth_cells']);
        $this->assertSame(325, $populationRoutine['population_growth']);

        $this->assertSame(21_000, $nearMaximum->fresh()->population);
        $this->assertSame(24_900, $farAboveMaximum->fresh()->population);
        foreach ([[$nearMaximum, 50], [$farAboveMaximum, 100]] as [$cell, $expectedLoss]) {
            $decline = DB::table('audit_events')->where('event_type', 'population.decreased')
                ->where('subject_id', $cell->id)
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->sole();
            $metadata = json_decode((string) $decline->metadata, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('above_attraction_maximum', $metadata['reason']);
            $this->assertSame($expectedLoss, $metadata['actual_loss']);
            $this->assertSame(21_000, $metadata['effective_attraction_maximum']);
            $this->assertSame(0, DB::table('audit_events')->where('event_type', 'population.increased')
                ->where('subject_id', $cell->id)
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
        }
        $this->assertSame(25_000, $capital->fresh()->population);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'population.decreased')
            ->where('subject_id', $capital->id)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());
    }

    public function test_attraction_growth_uses_distinct_pre_and_post_ordinary_ranges_and_clamps(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '誘致成長国', '誘致島主');
        $capitalId = $nation->capital()->value('map_cell_id');
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capitalId)->firstOrFail();
        $engine = app(CompleteTurnEngine::class);

        $cases = [
            ['before' => 7_000, 'minimum' => 100, 'maximum' => 3_000, 'draw' => 3_000, 'after' => 10_000],
            ['before' => 10_000, 'minimum' => 100, 'maximum' => 300, 'draw' => 300, 'after' => 10_300],
            ['before' => 5_000, 'minimum' => 100, 'maximum' => 3_000, 'draw' => 3_000, 'after' => 8_000],
            ['before' => 2_000, 'minimum' => 100, 'maximum' => 3_000, 'draw' => 3_000, 'after' => 5_000],
            ['before' => 19_950, 'minimum' => 100, 'maximum' => 300, 'draw' => 300, 'after' => 20_000],
        ];
        foreach ($cases as $index => $case) {
            $this->settlement($cell, 'city', $case['before']);
            [$context] = $this->context(
                $world,
                $nation,
                [$cell->id],
                $this->seedForFirstDraw(
                    TurnRandomStreamFactory::POPULATION_GROWTH,
                    $case['minimum'],
                    $case['maximum'],
                    $case['draw'],
                ),
            );
            $context->state->markAttraction($nation->id);

            $engine->execute('process_cells', $context);

            $this->assertSame($case['after'], $cell->fresh()->population, "Attraction case {$index} failed.");
        }

        $this->settlement($cell, 'village', 1_000);
        [$ordinaryContext] = $this->context(
            $world,
            $nation,
            [$cell->id],
            $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 1_000, 1_000),
        );

        $engine->execute('process_cells', $ordinaryContext);

        $this->assertSame(2_000, $cell->fresh()->population);
    }

    public function test_undersea_city_reuses_settlement_growth_and_one_famine_loss_then_discards_below_3000(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '海底人口国', '海底人口島主');
        $capitalId = $nation->capital()->value('map_cell_id');
        $cells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capitalId)->orderBy('id')->limit(4)->get();
        $this->assertCount(4, $cells);
        [$natural, $attraction, $minimum, $overlap] = $cells->all();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        $settings['turn_processing']['disasters']['fire']['probability'] = ['numerator' => 0, 'denominator' => 1];
        $ruleset->settings = $settings;
        $ruleset->save();
        $engine = app(CompleteTurnEngine::class);
        $growthSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 1_000, 100);

        $this->underseaCity($natural, 9_950);
        [$naturalContext] = $this->context($world, $nation, [$natural->id], $growthSeed, ruleset: $ruleset);
        $naturalResult = $engine->execute('process_cells', $naturalContext);
        $this->assertSame(50, $naturalResult->metrics['population_increased']);
        $this->assertSame(10_000, $natural->fresh()->population);
        $this->assertSame('undersea_city', $natural->fresh()->facility()->value('key'));
        $this->assertSame(0, $naturalResult->metrics['stage_transitions']);

        $this->underseaCity($attraction, 19_950);
        [$attractionContext] = $this->context($world, $nation, [$attraction->id], $growthSeed, ruleset: $ruleset);
        $attractionContext->state->markAttraction($nation->id);
        $attractionResult = $engine->execute('process_cells', $attractionContext);
        $this->assertSame(50, $attractionResult->metrics['population_increased']);
        $this->assertSame(20_000, $attraction->fresh()->population);
        $this->assertSame('undersea_city', $attraction->fresh()->facility()->value('key'));
        $this->assertSame(0, $attractionResult->metrics['stage_transitions']);

        $lossSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::FAMINE_POPULATION_LOSS, 100, 3_000, 100);
        $this->underseaCity($minimum, 3_100);
        [$minimumContext, $minimumRun] = $this->context(
            $world,
            $nation,
            [$minimum->id],
            $lossSeed,
            ruleset: $ruleset,
        );
        $minimumContext->state->markUnderseaCityMaintenanceFailure($minimum->id);
        $engine->execute('process_cells', $minimumContext);
        $this->assertSame(3_000, $minimum->fresh()->population);
        $this->assertSame('undersea_city', $minimum->fresh()->facility()->value('key'));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'famine.applied')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $minimumRun->id])->count());

        $this->underseaCity($minimum, 3_099);
        [$discardContext] = $this->context($world, $nation, [$minimum->id], $lossSeed, ruleset: $ruleset);
        $discardContext->state->markUnderseaCityMaintenanceFailure($minimum->id);
        $engine->execute('process_cells', $discardContext);
        $discarded = $minimum->fresh(['terrain', 'facility']);
        $this->assertSame(0, $discarded->population);
        $this->assertNull($discarded->facility_definition_id);
        $this->assertSame('sea', $discarded->terrain->key);
        $this->assertNull($discarded->owner_nation_id);

        $this->underseaCity($overlap, 5_000);
        [$overlapContext, $overlapRun] = $this->context(
            $world,
            $nation,
            [$overlap->id],
            $lossSeed,
            true,
            $ruleset,
        );
        $overlapContext->state->markUnderseaCityMaintenanceFailure($overlap->id);
        $overlapResult = $engine->execute('process_cells', $overlapContext);
        $this->assertSame(100, $overlapResult->metrics['population_decreased']);
        $this->assertSame(4_900, $overlap->fresh()->population);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'famine.applied')
            ->where('subject_id', $overlap->id)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $overlapRun->id])->count());
    }

    public function test_uniform_population_growth_ignores_signed_world_edges_and_expansion_provenance(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '海際度廃止国',
            '境界試験島主',
        );
        $expansion = app(WorldExpansionService::class);
        $expansion->expand($world, new MapBounds(0, 59, 0, 59, 16), new MapBounds(0, 63, 0, 63, 16));
        $expansion->expand($world->fresh(), new MapBounds(0, 63, 0, 63, 16), new MapBounds(-16, 63, 0, 63, 16));
        $space = $expansion->expand(
            $world->fresh(),
            new MapBounds(-16, 63, 0, 63, 16),
            new MapBounds(-16, 63, -16, 63, 16),
        );
        $seed = $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 1_000, 1_000);
        $coordinates = [
            'former origin edge' => [0, 0],
            'world center' => [20, 20],
            'negative corner' => [-16, -16],
            'negative x corner' => [-16, 63],
            'negative y corner' => [63, -16],
            'positive corner' => [63, 63],
            'new negative chunk' => [-8, -8],
        ];

        [$terrainContext] = $this->context($world, $nation, [], str_repeat('7', 64));
        DB::connection()->flushQueryLog();
        DB::enableQueryLog();
        $terrainContextResult = app(CompleteTurnEngine::class)->execute(
            'calculate_terrain_context',
            $terrainContext,
        );
        $terrainContextQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(6_400, $terrainContextResult->metrics['surface_cells']);
        $this->assertArrayNotHasKey('sea_edge_cells', $terrainContextResult->metrics);
        $this->assertCount(2, $terrainContextQueries);
        $this->assertCount(1, array_filter(
            $terrainContextQueries,
            static fn (array $query): bool => str_contains($query['query'], 'map_cells'),
        ));
        $this->assertCount(0, array_filter(
            $terrainContextQueries,
            static fn (array $query): bool => str_contains($query['query'], 'terrain_definitions'),
        ));

        foreach ($coordinates as $label => [$x, $y]) {
            $cell = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $x)->where('y', $y)->firstOrFail();
            $cell->owner_nation_id = $nation->id;
            $cell->save();
            $this->settlement($cell, 'village', 1_000);
            [$context] = $this->context($world, $nation, [$cell->id], $seed);

            app(CompleteTurnEngine::class)->execute('process_cells', $context);

            $this->assertSame(2_000, $cell->fresh()->population, $label);
            $this->assertSame(
                1_000,
                $context->state->routineSummaryMetrics($nation->id)['population_growth'],
                $label,
            );
        }

        $appearanceSeed = $this->seedForFirstDraw(
            TurnRandomStreamFactory::SETTLEMENT_APPEARANCE,
            0,
            99,
            19,
        );
        foreach (['world center' => [20, 20], 'negative expanded edge' => [-16, -16]] as $label => [$x, $y]) {
            $target = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $x)->where('y', $y)->firstOrFail();
            $target->owner_nation_id = $nation->id;
            $target->save();
            $this->plain($target);
            $neighborCoordinate = (new GridCoordinate($x, $y))->neighborsWithin(
                $space->min_x,
                $space->max_x,
                $space->min_y,
                $space->max_y,
            )[0];
            $neighbor = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $neighborCoordinate->x)->where('y', $neighborCoordinate->y)->firstOrFail();
            $neighbor->owner_nation_id = $nation->id;
            $neighbor->save();
            $this->settlement($neighbor, 'village', 1_000);
            [$context] = $this->context($world, $nation, [$target->id], $appearanceSeed);

            app(CompleteTurnEngine::class)->execute('process_cells', $context);

            $this->assertSame(100, $target->fresh()->population, $label);
            $this->assertSame('village', $target->fresh()->facility()->value('key'), $label);
        }
    }

    /** @return array{MapCell, MapCell} */
    private function sequentialCandidates(Nation $nation, MapCell $capital): array
    {
        $cells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->where('id', '!=', $capital->id)->orderBy('id')->get();
        $capitalCoordinate = new GridCoordinate($capital->x, $capital->y);
        foreach ($cells as $first) {
            $firstCoordinate = new GridCoordinate($first->x, $first->y);
            if ($capitalCoordinate->distanceTo($firstCoordinate) !== 1) {
                continue;
            }
            foreach ($cells as $second) {
                $secondCoordinate = new GridCoordinate($second->x, $second->y);
                if ($capitalCoordinate->distanceTo($secondCoordinate) === 2
                    && $firstCoordinate->distanceTo($secondCoordinate) === 1) {
                    return [$first, $second];
                }
            }
        }

        $this->fail('Initial island does not contain a two-step settlement propagation path.');
    }

    private function plain(MapCell $cell): void
    {
        $this->mutateCell($cell, 'plain', null, 0);
    }

    private function forest(MapCell $cell, int $quantity): void
    {
        $this->mutateCell($cell, 'forest', null, 0);
        $cell->fresh()->update(['terrain_quantity' => $quantity]);
    }

    private function settlement(MapCell $cell, string $facilityKey, int $population): void
    {
        $this->mutateCell($cell, 'plain', $facilityKey, $population);
    }

    private function underseaCity(MapCell $cell, int $population): void
    {
        $this->mutateCell($cell, 'sea', 'undersea_city', $population);
    }

    private function facility(MapCell $cell, string $facilityKey, string $terrainKey): void
    {
        $this->mutateCell($cell, $terrainKey, $facilityKey, 0);
    }

    /**
     * @param  list<int>  $excludedIds
     * @return array{MapCell, MapCell, MapCell}
     */
    private function eastwardSeaLine(MapSpace $space, array $excludedIds = []): array
    {
        $origins = MapCell::query()->where('map_space_id', $space->id)
            ->whereNotIn('id', $excludedIds)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->get();
        foreach ($origins as $origin) {
            $first = (new GridCoordinate($origin->x, $origin->y))->neighbor(GridCoordinate::EAST);
            $second = $first->neighbor(GridCoordinate::EAST);
            $line = MapCell::query()->where('map_space_id', $space->id)
                ->whereNotIn('id', $excludedIds)
                ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
                ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
                ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
                ->where(function ($query) use ($first, $second): void {
                    $query->where(fn ($cell) => $cell->where('x', $first->x)->where('y', $first->y))
                        ->orWhere(fn ($cell) => $cell->where('x', $second->x)->where('y', $second->y));
                })
                ->orderBy('x')->get()->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y);
            $destination = $line->get($first->x.':'.$first->y);
            $later = $line->get($second->x.':'.$second->y);
            if ($destination instanceof MapCell && $later instanceof MapCell) {
                return [$origin, $destination, $later];
            }
        }

        $this->fail('Surface test map did not provide an empty eastward deep-sea line.');
    }

    /** @return array{MapCell, MapCell, MapCell, MapCell} */
    private function remoteTreasureRoute(MapSpace $space): array
    {
        $origins = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->get();
        foreach ($origins as $origin) {
            $coordinate = new GridCoordinate($origin->x, $origin->y);
            $required = [
                'east' => $coordinate->neighbor(GridCoordinate::EAST),
                'west' => $coordinate->neighbor(GridCoordinate::WEST),
                'remote' => $coordinate->neighbor(GridCoordinate::EAST)
                    ->neighbor(GridCoordinate::EAST)
                    ->neighbor(GridCoordinate::EAST)
                    ->neighbor(GridCoordinate::EAST),
            ];
            $cells = MapCell::query()->where('map_space_id', $space->id)
                ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
                ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
                ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
                ->where(function ($query) use ($required): void {
                    foreach ($required as $candidate) {
                        $query->orWhere(fn ($cell) => $cell
                            ->where('x', $candidate->x)->where('y', $candidate->y));
                    }
                })->get()->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y);
            $east = $cells->get($required['east']->x.':'.$required['east']->y);
            $west = $cells->get($required['west']->x.':'.$required['west']->y);
            $remote = $cells->get($required['remote']->x.':'.$required['remote']->y);
            if ($east instanceof MapCell && $west instanceof MapCell && $remote instanceof MapCell) {
                return [$origin, $east, $west, $remote];
            }
        }

        $this->fail('Surface test map did not provide a remote Treasure route with two movement choices.');
    }

    /** @return array{MapCell, MapCell} */
    private function ownedLandWithSeaNeighbor(MapSpace $space, Nation $nation): array
    {
        $owned = MapCell::query()->where('map_space_id', $space->id)
            ->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', static fn ($query) => $query->where('is_water', false))
            ->orderBy('id')->get();
        foreach ($owned as $land) {
            foreach ((new GridCoordinate($land->x, $land->y))->neighborsWithin(
                $space->min_x,
                $space->max_x,
                $space->min_y,
                $space->max_y,
            ) as $coordinate) {
                $sea = MapCell::query()->where('map_space_id', $space->id)
                    ->where('x', $coordinate->x)->where('y', $coordinate->y)
                    ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
                    ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
                    ->whereHas('terrain', static fn ($query) => $query->where('key', 'sea'))
                    ->first();
                if ($sea instanceof MapCell) {
                    return [$land, $sea];
                }
            }
        }

        foreach ($owned as $land) {
            foreach ((new GridCoordinate($land->x, $land->y))->neighborsWithin(
                $space->min_x,
                $space->max_x,
                $space->min_y,
                $space->max_y,
            ) as $coordinate) {
                $candidate = MapCell::query()->where('map_space_id', $space->id)
                    ->where('x', $coordinate->x)->where('y', $coordinate->y)
                    ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
                    ->with(['terrain', 'facility'])->first();
                if (! $candidate instanceof MapCell) {
                    continue;
                }

                $states = app(MapCellStateService::class);
                $states->setFacility($candidate, null);
                $states->transitionTerrain(
                    $candidate,
                    TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
                );
                $candidate->owner_nation_id = null;
                $candidate->population = 0;
                $candidate->save();

                return [$land, $candidate];
            }
        }

        $this->fail('Surface test map did not provide a usable cell next to owned land.');
    }

    private function mutateCell(MapCell $cell, string $terrainKey, ?string $facilityKey, int $population): void
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        if ($facilityKey !== null) {
            $states->setFacility($cell, FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail());
        }
        $cell->population = $population;
        $cell->save();
    }

    /** @param list<int> $excludedIds */
    private function ownedEmptyCell(Nation $nation, array $excludedIds): MapCell
    {
        return MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $excludedIds)->whereNull('facility_definition_id')->firstOrFail();
    }

    private function queueLandClear(
        User $user,
        Nation $nation,
        MapSpace $space,
        MapCell $target,
    ): NationCommandQueueItem {
        $queue = NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $space->id, 'version' => 1],
        );
        $definition = CommandDefinition::query()->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
            ->where('key', 'land_clear')->firstOrFail();
        $membership = NationMembership::query()->where('user_id', $user->id)->where('nation_id', $nation->id)->firstOrFail();

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

    /**
     * @param  list<int>  $cellIds
     * @return array{TurnContext, TurnRun}
     */
    private function context(
        World $world,
        Nation $nation,
        array $cellIds,
        string $seed,
        bool $famine = false,
        ?RulesetVersion $ruleset = null,
    ): array {
        $ruleset ??= $world->rulesetVersion()->firstOrFail();
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
        $state->setStableNationIds([$nation->id]);
        $state->setDevelopmentNationIds([$nation->id]);
        $state->setSurfaceCellIds($cellIds);
        if ($famine) {
            $state->markFamine($nation->id);
        }

        $context = new TurnContext($world, $run, $ruleset, 1, $seed, new TurnRandomStreamFactory($seed), $state);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);

        return [$context, $run];
    }

    private function seedForSettlementSequence(int $firstExpected, int $secondExpected): string
    {
        $label = TurnRandomStreamFactory::SETTLEMENT_APPEARANCE;
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "settlement-sequence:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream($label);
            if ($stream->integer(0, 99) === $firstExpected && $stream->integer(0, 99) === $secondExpected) {
                return $seed;
            }
        }

        $this->fail('Unable to find the settlement propagation draw sequence.');
    }

    private function seedForNyoMovementAndSettlement(int $monsterId, int $direction): string
    {
        foreach (range(0, 100_000) as $candidate) {
            $seed = hash('sha256', "nyowamiya-settlement:{$candidate}");
            $movement = (new TurnRandomStreamFactory($seed))->stream(
                TurnRandomStreamFactory::monsterMovement($monsterId, 1),
            );
            $settlement = (new TurnRandomStreamFactory($seed))->stream(
                TurnRandomStreamFactory::SETTLEMENT_APPEARANCE,
            );
            if ($movement->integer(0, 5) === $direction && $settlement->integer(0, 99) < 20) {
                return $seed;
            }
        }

        $this->fail('Unable to find deterministic Nyo movement and settlement draws.');
    }

    /** @param list<int> $expected */
    private function seedForRepeatedDraw(string $label, int $minimum, int $maximum, array $expected): string
    {
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "{$label}:sequence:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream($label);
            $actual = [];
            foreach ($expected as $_draw) {
                $actual[] = $stream->integer($minimum, $maximum);
            }
            if ($actual === $expected) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic sequence for {$label}.");
    }

    private function seedForFirstDraw(string $label, int $minimum, int $maximum, int $expected): string
    {
        return $this->seedForRepeatedDraw($label, $minimum, $maximum, [$expected]);
    }

    /** @return array<string, mixed> */
    private function event(TurnRun $run, string $eventType): array
    {
        $metadata = DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('metadata');

        return json_decode((string) $metadata, true, 512, JSON_THROW_ON_ERROR);
    }
}
