<?php

namespace Tests\Feature;

use App\Application\BuriedTreasureService;
use App\Application\CurrentDatabaseBaseline;
use App\Application\DomesticCommandExecutor;
use App\Application\MonsterTurnService;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryTurnService;
use App\Application\SurfaceShipTurnService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\BuriedTreasure;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use App\Services\MapCellPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class SurfaceShipDestinationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_destination_replaces_heading_and_clears_without_spending_a_turn_or_queue_slot(): void
    {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'warship');
        $ship->update(['heading' => GridCoordinate::WEST]);
        $path = $this->path($nation, $ship);
        $target = ['x' => $origin->x + 3, 'y' => $origin->y];
        $queueCount = NationCommandQueueItem::query()->count();
        $money = $nation->money;
        $this->actingAs($owner)->patchJson($path, ['destination' => $target, 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.heading', null)
            ->assertJsonPath('data.destination', $target)->assertJsonPath('data.version', 2);
        $this->patchJson($path, ['destination' => $target, 'expected_version' => 2])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($path, ['destination' => null, 'expected_version' => 1])->assertConflict();
        $this->patchJson(str_replace('/destination', '/heading', $path), ['heading' => GridCoordinate::EAST, 'expected_version' => 2])
            ->assertOk()->assertJsonPath('data.heading', GridCoordinate::EAST)
            ->assertJsonPath('data.destination', null)->assertJsonPath('data.version', 3);
        $this->patchJson($path, ['destination' => $target, 'expected_version' => 3])->assertOk();
        $this->patchJson($path, ['destination' => null, 'expected_version' => 4])
            ->assertOk()->assertJsonPath('data.heading', null)
            ->assertJsonPath('data.destination', null)->assertJsonPath('data.version', 5);
        $this->assertSame([1, $money, $queueCount], [
            $world->fresh()->current_turn, $nation->fresh()->money, NationCommandQueueItem::query()->count(),
        ]);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'ship.destination.updated')
            ->where('visibility', '!=', 'admin')->count());
        $this->context($world, $nation, 2)->run->update(['is_dry_run' => false, 'status' => TurnRun::STATUS_PENDING]);
        $this->patchJson($path, ['destination' => $target, 'expected_version' => 5])->assertConflict();
        $this->assertSame(['heading' => null, 'destination' => null, 'version' => 5], $ship->fresh()->course());
    }

    public function test_destination_validation_uses_visible_sea_without_exposing_hidden_facilities_or_foreign_orders(): void
    {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'fishing');
        $path = $this->path($nation, $ship);
        $targetCell = MapCell::query()->where('map_space_id', $origin->map_space_id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($q) => $q->where('key', 'sea'))
            ->orderByDesc('x')->orderByDesc('y')->firstOrFail();
        // A secret sea facility has the same accepted target projection as ordinary sea.
        app(MapCellStateService::class)->setFacility($targetCell, FacilityDefinition::query()->where('key', 'seabed_base')->firstOrFail());
        $targetCell->save();
        $target = ['x' => $targetCell->x, 'y' => $targetCell->y];
        $this->getJson("/api/v1/nations/{$nation->id}/ships")->assertUnauthorized();
        $this->patchJson($path, ['destination' => $target, 'expected_version' => 1])->assertUnauthorized();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->patchJson($path, ['destination' => $target, 'expected_version' => 1])->assertForbidden();
        $this->actingAs($owner)->patchJson($path, ['destination' => $target, 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.destination', $target);
        $this->getJson("/api/v1/nations/{$nation->id}/ships")
            ->assertOk()->assertJsonPath('data.0.destination', $target)->assertJsonPath('data.0.version', 2);
        $presenter = app(MapCellPresenter::class);
        $this->assertSame($target, $presenter->present($origin->fresh(), $nation->id, 1)['ship']['destination']);
        foreach ([null, $nation->id + 100] as $viewer) {
            $view = $presenter->present($origin->fresh(), $viewer, 1, withinViewerVisibility: true)['ship'];
            $this->assertSame([null, null, null], [$view['heading'], $view['destination'], $view['version']]);
        }
        $land = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($q) => $q->where('is_water', false))->firstOrFail();
        $this->patchJson($path, ['destination' => ['x' => $land->x, 'y' => $land->y], 'expected_version' => 2])->assertUnprocessable();
        $this->patchJson($path, ['destination' => ['x' => $origin->x], 'expected_version' => 2])->assertUnprocessable();
        $this->patchJson($path, ['destination' => ['x' => 100000, 'y' => 100000], 'expected_version' => 2])->assertUnprocessable();
        $this->assertSame($target, $ship->fresh()->course()['destination']);
    }

    public function test_explorer_obeys_destination_collects_on_route_and_resumes_treasure_chase_after_arrival(): void
    {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'exploration');
        $definition = app(SurfaceShipCatalog::class)->definition($world->rulesetVersion->settings, $ship->ship_type_key);
        $initialOil = $definition->movementOilUnits * 3;
        $this->oil($nation)->update(['amount' => $initialOil]);
        $goal = $this->cell($origin, 2);
        $ship->update(['destination_x' => $goal->x, 'destination_y' => $goal->y]);
        $context = $this->context($world, $nation, 2);
        $treasures = app(BuriedTreasureService::class);
        $west = $treasures->create($context, $this->cell($origin, -1), 'natural', false);
        $onRoute = $treasures->create($context, $this->cell($origin, 1), 'natural', false);
        $this->move($context, $ship);
        $this->assertSame($this->cell($origin, 1)->id, $ship->fresh()->map_cell_id);
        $this->assertSame(BuriedTreasure::STATE_ACTIVE, $west->fresh()->state);
        $this->assertSame(BuriedTreasure::STATE_COLLECTED, $onRoute->fresh()->state);
        $this->assertNotNull($ship->fresh()->course()['destination']);
        $this->move($this->context($world, $nation, 3), $ship);
        $this->assertSame($goal->id, $ship->fresh()->map_cell_id);
        $this->assertNull($ship->fresh()->course()['destination']);
        $this->assertSame('arrived', $this->endReason($ship));
        $this->assertSame(2, DB::table('audit_events')->where('subject_id', $ship->id)->where('event_type', 'ship.moved')->count());
        $this->assertSame($initialOil - 2 * $definition->movementOilUnits, $this->oil($nation)->fresh()->amount);
        $this->move($this->context($world, $nation, 4), $ship);
        $this->assertSame($this->cell($origin, 1)->id, $ship->fresh()->map_cell_id);
    }

    #[DataProvider('nonDeepSeaTreasureTerrains')]
    public function test_explorer_ignores_non_deep_sea_treasure_and_collects_a_deep_sea_target(string $terrainKey): void
    {
        [$world, $owner, $nation] = $this->fixture();
        [$shore, $shallowCell, $origin, $seaCell] = $this->coastalTreasureRoute($nation);
        $nation->update(['money' => 3 * CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)->where('key', 'reclaim')->value('cost_money')]);
        $context = $this->context($world, $nation, 2);
        $treasures = app(BuriedTreasureService::class);
        $shallowTreasure = $treasures->create($context, $shallowCell, 'natural', false);
        // Extend the real coast, then reclaim the treasure's sea cell through the public queue.
        $this->reclaim($owner, $nation, $shore, $context);
        if ($shallowCell->fresh()->terrain->key === 'sea') {
            $this->reclaim($owner, $nation, $shallowCell, $this->context($world, $nation, 3));
        }
        $this->assertSame('shallow', $shallowCell->fresh()->terrain->key);
        $this->assertNull($shallowCell->fresh()->owner_nation_id);
        $this->assertSame(BuriedTreasure::STATE_ACTIVE, $shallowTreasure->fresh()->state);

        if ($terrainKey === 'wasteland') {
            // A full inventory also legitimately preserves treasure when shallow becomes owned land.
            $secretary = $owner->secretary()->sole();
            $freeSlots = SecretaryItemGrantService::INVENTORY_CAPACITY - $secretary->itemInstances()->count();
            for ($slot = 1; $slot <= $freeSlots; $slot++) {
                $secretary->itemInstances()->create([
                    'item_key' => SecretaryItemCatalog::RING, 'level' => 1, 'equipped_slot' => null,
                    'grant_key' => 'test:non-deep-sea-full:'.$slot, 'obtained_at' => now(),
                ]);
            }
            $this->reclaim($owner, $nation, $shallowCell, $this->context($world, $nation, 4));
            $secretary->itemInstances()->where('grant_key', 'like', 'test:non-deep-sea-full:%')->delete();
            $this->assertSame($nation->id, $shallowCell->fresh()->owner_nation_id);
        }
        $this->assertSame($terrainKey, $shallowCell->fresh()->terrain->key);
        $this->assertSame(BuriedTreasure::STATE_ACTIVE, $shallowTreasure->fresh()->state);
        $ship = $this->ship($world, $nation, $origin, 'exploration');
        $definition = app(SurfaceShipCatalog::class)->definition($world->rulesetVersion->settings, $ship->ship_type_key);
        $initialOil = 3 * $definition->movementOilUnits;
        $this->oil($nation)->update(['amount' => $initialOil]);
        $sailingContext = $this->context($world, $nation, 5);
        $seaTreasure = $treasures->create($sailingContext, $seaCell, 'natural', false);

        foreach (range(5, 7) as $turn) {
            $metrics = $this->move($turn === 5 ? $sailingContext : $this->context($world, $nation, $turn), $ship);
            $this->assertSame(1, $metrics['ship_moves']);
            $this->assertNotSame($shallowCell->id, $ship->fresh()->map_cell_id);
        }

        $this->assertSame($seaCell->id, $ship->fresh()->map_cell_id);
        $this->assertSame(BuriedTreasure::STATE_COLLECTED, $seaTreasure->fresh()->state);
        $this->assertSame(BuriedTreasure::STATE_ACTIVE, $shallowTreasure->fresh()->state);
        $this->assertSame($terrainKey, $shallowCell->fresh()->terrain->key);
        $this->assertSame($initialOil - 3 * $definition->movementOilUnits, $this->oil($nation)->fresh()->amount);
        $this->assertSame(1, $owner->secretary()->sole()->itemInstances()
            ->where('item_key', $seaTreasure->reward_snapshot['item_key'])->count());
        $this->assertNull($ship->fresh()->course()['heading']);
        $this->assertNull($ship->fresh()->course()['destination']);
    }

    /** @return array<string, array{string}> */
    public static function nonDeepSeaTreasureTerrains(): array
    {
        return ['shallow' => ['shallow'], 'reclaimed_land' => ['wasteland']];
    }

    #[DataProvider('treasureFacilityCases')]
    public function test_explorer_respects_deep_sea_facility_entry_rules_when_selecting_treasure(
        string $facilityKey,
        bool $canEnter,
    ): void {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'exploration');
        $context = $this->context($world, $nation, 2);
        $treasures = app(BuriedTreasureService::class);
        $nearCell = $this->cell($origin, 1);
        $nearTreasure = $treasures->create($context, $nearCell, 'natural', false);
        app(MapCellStateService::class)->setFacility(
            $nearCell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
        );
        $nearCell->owner_nation_id = $nation->id;
        $nearCell->save();
        $farCell = $this->cell($origin, 3);
        $farTreasure = $treasures->create($context, $farCell, 'natural', false);

        // The public facility blocks the direct east step, so its sea detour takes one extra move.
        foreach (range(2, $canEnter ? 2 : 5) as $turn) {
            $this->move($turn === 2 ? $context : $this->context($world, $nation, $turn), $ship);
        }

        $this->assertSame($canEnter ? $nearCell->id : $farCell->id, $ship->fresh()->map_cell_id);
        $this->assertSame($canEnter ? BuriedTreasure::STATE_COLLECTED : BuriedTreasure::STATE_ACTIVE, $nearTreasure->fresh()->state);
        $this->assertSame($canEnter ? BuriedTreasure::STATE_ACTIVE : BuriedTreasure::STATE_COLLECTED, $farTreasure->fresh()->state);
        $this->assertSame(1, $owner->secretary()->sole()->itemInstances()
            ->where('item_key', $nearTreasure->reward_snapshot['item_key'])->count());
    }

    /** @return array<string, array{string, bool}> */
    public static function treasureFacilityCases(): array
    {
        return [
            'public_facility_blocks_entry' => ['seabed_oil_field', false],
            'disguised_sea_facility_allows_entry' => ['seabed_base', true],
        ];
    }

    public function test_blocked_last_step_clears_without_arrival_and_warship_still_attacks(): void
    {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'warship');
        $goal = $this->cell($origin, 1);
        $pirate = $this->ship($world, null, $goal, 'pirate');
        $ship->update(['destination_x' => $goal->x, 'destination_y' => $goal->y]);
        $hp = $pirate->current_hp;
        $money = $nation->money;
        $metrics = $this->move($this->context($world, $nation, 2), $ship);
        $this->assertSame($origin->id, $ship->fresh()->map_cell_id);
        $this->assertNull($ship->fresh()->course()['destination']);
        $this->assertSame('blocked', $this->endReason($ship));
        $this->assertSame(0, $metrics['ship_moves']);
        $this->assertSame(10, $this->oil($nation)->fresh()->amount);
        $this->assertLessThan($hp, $pirate->fresh()->current_hp);
        $this->assertLessThan($money, $nation->fresh()->money);
        $events = app(PlayerIslandEventService::class);
        $ownerEvent = collect($events->ownerPage($nation, anchorTurn: 2)['groups'])->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'ship.destination.ended');
        $this->assertNotNull($ownerEvent);
        $this->assertTrue($ownerEvent['confidential']);
        $this->assertStringNotContainsString('ship.destination.ended', json_encode($events->publicNationPage($nation, anchorTurn: 2), JSON_THROW_ON_ERROR));
    }

    public function test_no_closer_step_ends_order_and_auto_ship_returns_to_normal_movement(): void
    {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'fishing');
        $goal = $this->cell($origin, 3);
        // At distance three only the east neighbor is closer; other sea neighbors permit auto drift.
        $barrier = $this->cell($origin, 1);
        $barrier->update(['terrain_definition_id' => TerrainDefinition::query()->where('key', 'shallow')->value('id')]);
        $ship->update(['destination_x' => $goal->x, 'destination_y' => $goal->y]);
        $metrics = $this->move($this->context($world, $nation, 2), $ship);
        $this->assertSame('blocked', $this->endReason($ship));
        $this->assertNull($ship->fresh()->course()['destination']);
        $this->assertNotSame($origin->id, $ship->fresh()->map_cell_id);
        $this->assertSame(1, $metrics['ship_moves']);
        $this->assertSame(1, (new GridCoordinate($origin->x, $origin->y))->distanceTo(
            new GridCoordinate($ship->fresh()->cell->x, $ship->fresh()->cell->y),
        ));
        $this->assertGreaterThan(0, $metrics['ship_fish_applied']);
    }

    public function test_no_port_and_no_fuel_retain_destination_using_existing_stopped_behavior(): void
    {
        [$world, $owner, $nation, $origin, $port] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'warship');
        $goal = $this->cell($origin, 2);
        $ship->update(['destination_x' => $goal->x, 'destination_y' => $goal->y]);
        $target = $ship->fresh()->course()['destination'];
        $this->oil($nation)->update(['amount' => 0]);
        $metrics = $this->move($this->context($world, $nation, 2), $ship);
        $this->assertSame(1, $metrics['ship_fuel_shortages']);
        $this->assertSame([$origin->id, $target], [$ship->fresh()->map_cell_id, $ship->fresh()->course()['destination']]);
        app(MapCellStateService::class)->setFacility($port, null);
        $port->save();
        $this->oil($nation)->update(['amount' => 10]);
        $metrics = $this->move($this->context($world, $nation, 3), $ship);
        $this->assertSame(1, $metrics['ship_no_port']);
        $this->assertSame([$origin->id, $target], [$ship->fresh()->map_cell_id, $ship->fresh()->course()['destination']]);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'ship.destination.ended')->count());
    }

    public function test_changed_target_ends_with_owner_log_and_current_cell_arrival_does_not_auto_move(): void
    {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'fishing');
        $this->actingAs($owner)->patchJson($this->path($nation, $ship), [
            'destination' => ['x' => $origin->x, 'y' => $origin->y], 'expected_version' => 1,
        ])->assertOk();
        $this->move($this->context($world, $nation, 2), $ship);
        $this->assertSame([$origin->id, null], [$ship->fresh()->map_cell_id, $ship->fresh()->course()['destination']]);
        $this->assertSame('arrived', $this->endReason($ship));
        $goal = $this->cell($origin, 2);
        $ship->fresh()->update(['destination_x' => $goal->x, 'destination_y' => $goal->y]);
        $goal->update(['terrain_definition_id' => TerrainDefinition::query()->where('key', 'shallow')->value('id')]);
        $this->move($this->context($world, $nation, 3), $ship);
        $this->assertNull($ship->fresh()->course()['destination']);
        $this->assertSame('terrain_changed', $this->endReason($ship));
        $this->assertNotEmpty(collect(app(PlayerIslandEventService::class)->ownerPage($nation, anchorTurn: 3)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->where('target_turn', 3)->where('type', 'ship.destination.ended'));
    }

    public function test_additive_upgrade_preserves_existing_ship_heading_position_and_version(): void
    {
        [$world, $owner, $nation, $origin] = $this->fixture();
        $ship = $this->ship($world, $nation, $origin, 'warship');
        $ship->update(['heading' => GridCoordinate::EAST, 'version' => 7]);
        DB::statement('ALTER TABLE ships DROP CONSTRAINT ships_course_check, DROP COLUMN destination_x, DROP COLUMN destination_y');
        $before = DB::table('ships')->where('id', $ship->id)->first();
        $migration = require database_path('migrations/2026_10_14_000000_add_ship_destinations.php');
        $migration->up();
        $after = DB::table('ships')->where('id', $ship->id)->first();
        $attributes = (array) $after;
        unset($attributes['destination_x'], $attributes['destination_y']);
        $this->assertSame((array) $before, $attributes);
        $this->assertSame(['heading' => GridCoordinate::EAST, 'destination' => null, 'version' => 7], $ship->fresh()->course());
        app(CurrentDatabaseBaseline::class)->assertExisting();
        DB::statement('ALTER TABLE ships ALTER COLUMN destination_x TYPE bigint');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database differs');
        app(CurrentDatabaseBaseline::class)->assertExisting();
    }

    /** @return array{World, User, Nation, MapCell, MapCell} */
    private function fixture(): array
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '目的地国', '航行島主');
        $port = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($q) => $q->where('key', 'plain'))->firstOrFail();
        app(MapCellStateService::class)->setFacility($port, FacilityDefinition::query()->where('key', 'port')->firstOrFail());
        $port->save();
        $this->oil($nation)->update(['amount' => 10]);
        $sea = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($q) => $q->where('key', 'sea'))
            ->orderBy('id')->get()->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y);
        foreach ($sea as $origin) {
            $complete = true;
            foreach (range(-1, 3) as $dx) {
                foreach (range(-1, 1) as $dy) {
                    $complete = $complete && $sea->has(($origin->x + $dx).':'.($origin->y + $dy));
                }
            }
            if ($complete) {
                return [$world, $owner, $nation, $origin, $port];
            }
        }
        $this->fail('No empty sea patch for destination boundaries.');
    }

    private function ship(World $world, ?Nation $nation, MapCell $cell, string $type): Ship
    {
        $definition = collect(app(SurfaceShipCatalog::class)->definitions($world->rulesetVersion->settings))->firstWhere('key', $type);

        return Ship::query()->create([
            'world_id' => $world->id, 'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation?->id, 'map_cell_id' => $cell->id, 'ship_type_key' => $type,
            'current_hp' => $definition->maximumHp, 'max_hp' => $definition->maximumHp,
            'state' => Ship::STATE_ACTIVE, 'version' => 1,
        ]);
    }

    /** @return array{MapCell, MapCell, MapCell, MapCell} */
    private function coastalTreasureRoute(Nation $nation): array
    {
        $space = $this->surfaceMapSpace($nation->world()->firstOrFail());
        $cells = MapCell::query()->where('map_space_id', $space->id)
            ->with('terrain')->orderBy('id')->get()->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y);
        foreach ($cells as $shore) {
            if ($shore->terrain->key !== 'shallow' || $shore->owner_nation_id !== null
                || $shore->facility_definition_id !== null) {
                continue;
            }
            $coordinate = new GridCoordinate($shore->x, $shore->y);
            $adjacentLand = collect($coordinate->neighborsWithin($space->min_x, $space->max_x, $space->min_y, $space->max_y))
                ->map(fn (GridCoordinate $neighbor): ?MapCell => $cells->get($neighbor->x.':'.$neighbor->y))
                ->contains(fn (?MapCell $cell): bool => $cell?->owner_nation_id === $nation->id && ! $cell->terrain->is_water);
            if (! $adjacentLand) {
                continue;
            }
            foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $heading) {
                $route = [];
                $next = $coordinate;
                foreach (range(0, 4) as $step) {
                    $next = $next->neighbor($heading);
                    $cell = $cells->get($next->x.':'.$next->y);
                    if ($cell === null || $cell->terrain->key !== 'sea' || $cell->owner_nation_id !== null
                        || $cell->facility_definition_id !== null) {
                        break;
                    }
                    $route[] = $cell;
                }
                if (count($route) === 5) {
                    return [$shore, $route[0], $route[1], $route[4]];
                }
            }
        }
        $this->fail('No reclaimable coast with an open sea route for treasure tracking.');
    }

    private function reclaim(User $owner, Nation $nation, MapCell $cell, TurnContext $context): void
    {
        $this->actingAs($owner)->postJson("/api/v1/nations/{$nation->id}/map-spaces/{$cell->map_space_id}/command-queue", [
            'command_key' => 'reclaim', 'target_x' => $cell->x, 'target_y' => $cell->y,
            'position' => 1, 'request_key' => fake()->uuid(),
            'expected_version' => NationCommandQueue::query()->where('nation_id', $nation->id)->value('version') ?? 1,
        ])->assertCreated();
        $metrics = app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame(1, $metrics['successes'], json_encode(NationCommandQueueItem::query()
            ->whereHas('queue', fn ($query) => $query->where('nation_id', $nation->id))
            ->orderByDesc('id')->value('failure_metadata'), JSON_THROW_ON_ERROR));
    }

    private function cell(MapCell $origin, int $dx): MapCell
    {
        return MapCell::query()->where('map_space_id', $origin->map_space_id)
            ->where('x', $origin->x + $dx)->where('y', $origin->y)->firstOrFail();
    }

    private function oil(Nation $nation): NationResource
    {
        return NationResource::query()->firstOrCreate([
            'nation_id' => $nation->id, 'resource_definition_id' => ResourceDefinition::query()->where('key', 'oil')->value('id'),
        ], ['amount' => 0]);
    }

    private function path(Nation $nation, Ship $ship): string
    {
        return "/api/v1/nations/{$nation->id}/ships/{$ship->id}/destination";
    }

    private function context(World $world, Nation $nation, int $turn): TurnContext
    {
        $seed = hash('sha256', 'ship-destination-'.$turn);
        $run = TurnRun::query()->create([
            'world_id' => $world->id, 'target_turn' => $turn, 'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => $seed, 'source' => 'manual', 'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN, 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds([$nation->id]);
        $state->setDevelopmentNationIds([$nation->id]);
        $state->setSurfaceCellIds(MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)->orderBy('id')->pluck('id')->all());
        $context = new TurnContext($world, $run, $world->rulesetVersion, $turn, $seed, new TurnRandomStreamFactory($seed), $state);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);

        return $context;
    }

    /** @return array<string, int> */
    private function move(TurnContext $context, Ship $ship): array
    {
        $space = $this->surfaceMapSpace($context->world);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility', 'ownerNation'])
            ->get()->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();
        $ships = app(SurfaceShipTurnService::class);
        $batch = $ships->load($context, $space);
        $monsters = app(MonsterTurnService::class)->load($context);
        $ships->processCell($context, $space, $ship->fresh()->cell, $cells, $monsters, $batch);
        // A later cell pass must not move an arriving ship for a second time.
        $ships->processCell($context, $space, $ship->fresh()->cell, $cells, $monsters, $batch);
        app(SecretaryTurnService::class)->flushExperience($context);

        return $batch->metrics();
    }

    private function endReason(Ship $ship): string
    {
        $metadata = DB::table('audit_events')->where('subject_id', $ship->id)
            ->where('event_type', 'ship.destination.ended')->orderByDesc('id')->value('metadata');
        $this->assertNotNull($metadata, 'The destination must end with an explicit owner event.');

        return json_decode($metadata, true, flags: JSON_THROW_ON_ERROR)['reason'];
    }
}
