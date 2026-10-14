<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\DomesticCommandExecutor;
use App\Application\KarmaTurnService;
use App\Application\SurfaceShipRemovalService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\World;
use App\Services\MapCellPresenter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\Support\CommandAndMissileTestCase;

final class UnderseaConstructionShipTest extends CommandAndMissileTestCase
{
    use UsesReusableSurfaceWorld;

    public static function underseaCommands(): array
    {
        return [
            'base' => ['build_seabed_base', 'seabed_base', 1],
            'city' => ['build_undersea_city', 'undersea_city', 1],
            'fire station' => ['build_undersea_fire_station', 'undersea_fire_station', 1],
        ];
    }

    #[DataProvider('underseaCommands')]
    public function test_ship_builds_undersea_facilities_through_existing_commands_and_stays_at_triple_cost(
        string $commandKey, string $facilityKey, int $quantity,
    ): void {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '工作船建設');
        $target = $this->remoteSea($world);
        $ship = $this->ship($world, $nation, $target);
        $nation->update(['money' => 100_000]);
        $capital = $nation->capital()->sole()->cell()->sole();
        $capital->update(['population' => 10_000]);
        $command = CommandDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)->where('key', $commandKey)->sole();
        $service = app(CommandQueueService::class);
        $params = ['construction_ship_id' => $ship->id];
        $this->assertPlayerFacing(fn () => $this->queue($service, $user, $nation, $this->surfaceMapSpace($world), 'excavate', $target, parameters: $params));
        $service->validateTarget($nation, $this->surfaceMapSpace($world), $command, $target, parameters: $params);
        $preview = $this->actingAs($user)->getJson('/api/v1/nations/'.$nation->id.'/map-spaces/'.$this->surfaceMapSpace($world)->id.'/command-definitions?target_x='.$target->x.'&target_y='.$target->y.'&construction_ship_id='.$ship->id);
        $commands = collect($preview->assertOk()->json('data.commands'));
        $this->assertSame($command->cost_money * 3, $commands->firstWhere('key', $commandKey)['cost_money']);
        $this->assertSame('currently_executable', $commands->firstWhere('key', $commandKey)['execution_preview_status']);
        $this->assertNull($commands->firstWhere('key', $commandKey)['distance_reason_code']);
        $this->assertNull($commands->firstWhere('key', $commandKey)['projected_distance_reason_code']);
        $item = $this->queue($service, $user, $nation, $this->surfaceMapSpace($world), $commandKey, $target, $quantity, parameters: $params);
        $plan = $this->getJson('/api/v1/nations/'.$nation->id.'/map-spaces/'.$this->surfaceMapSpace($world)->id.'/command-queue')->assertOk();
        $planned = collect($plan->json('data.items'))->firstWhere('id', $item->id);
        $this->assertSame($commands->firstWhere('key', $commandKey)['cost_money'], $planned['effective_cost_money']);
        $this->assertSame([null, null, $ship->id], [$planned['target_x'], $planned['target_y'], $planned['parameters']['construction_ship_id']]);
        if ($commandKey === 'build_seabed_base') {
            $queue = $item->queue()->with('items.definition')->sole();
            $projection = $service->projectCellStateBeforePosition($target, $queue, 2, $nation, $this->surfaceMapSpace($world));
            $this->assertNull($projection['facility_key']);
            $this->assertNull($projection['owner_nation_id']);
        }
        $buildCell = $this->remoteSea($world, $target->id);
        $ship->update(['map_cell_id' => $buildCell->id]);
        $context = $this->context($world, 2, hash('sha256', 'working ship '.$commandKey), [$nation->id]);
        app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame($facilityKey, $buildCell->fresh()->facility?->key);
        $this->assertSame($nation->id, $buildCell->fresh()->owner_nation_id);
        $this->assertNull($target->fresh()->facility_definition_id);
        $this->assertNull($target->fresh()->owner_nation_id);
        $this->assertSame([$target->x, $target->y], [$item->fresh()->target_x, $item->fresh()->target_y]);
        $this->assertSame(100_000 - $command->cost_money * $quantity * 3, (int) $nation->fresh()->money);
        $this->assertSame([$buildCell->id, Ship::STATE_ACTIVE, 1], [$ship->fresh()->map_cell_id, $ship->fresh()->state, $ship->fresh()->current_hp]);
        $event = DB::table('audit_events')->where('event_type', 'command.success')->where('subject_id', $item->id)->sole();
        $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$buildCell->x, $buildCell->y, $buildCell->id], [$event->x, $event->y, $metadata['after']['cell_id']]);

    }

    public function test_ship_reference_is_owned_and_rechecked_without_falling_back_to_normal_construction(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '船参照国');
        [, $foreign] = $this->nation($world, '他国船');
        $target = $this->remoteSea($world);
        $ship = $this->ship($world, $foreign, $target);
        $service = app(CommandQueueService::class);
        $enqueue = fn () => $this->queue($service, $user, $nation, $this->surfaceMapSpace($world), 'build_seabed_base', $target, 1, parameters: ['construction_ship_id' => $ship->id]);
        $this->assertPlayerFacing($enqueue);
        $ship->update(['nation_id' => $nation->id]);
        $item = $enqueue();
        $path = '/api/v1/nations/'.$nation->id.'/map-spaces/'.$this->surfaceMapSpace($world)->id.'/command-queue';
        $plannedCost = $this->actingAs($user)->getJson($path)->assertOk()->json('data.items.0.effective_cost_money');
        // Even a valid nearby land anchor cannot rescue a plan whose selected Ship is gone.
        $land = MapCell::query()->where('map_space_id', $target->map_space_id)->where('x', $target->x + 1)->where('y', $target->y)->sole();
        app(MapCellStateService::class)->transitionTerrain($land, TerrainDefinition::query()->where('key', 'plain')->sole());
        $land->owner_nation_id = $nation->id;
        $land->save();
        $ordinaryCell = $this->remoteSea($world);
        $ordinary = $this->ship($world, $nation, $ordinaryCell, 'fishing');
        $this->assertPlayerFacing(fn () => $this->queue($service, $user, $nation, $this->surfaceMapSpace($world), 'build_undersea_fire_station', $ordinaryCell, parameters: ['construction_ship_id' => $ordinary->id]));
        $context = $this->context($world, 2, hash('sha256', 'missing working ship'), [$nation->id]);
        app(SurfaceShipRemovalService::class)->sinkAtCell($context, $target, 'test');
        $this->getJson($path)->assertOk()->assertJsonPath('data.items.0.effective_cost_money', $plannedCost);
        $money = (int) $nation->fresh()->money;
        app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame('invalid_parameter', $item->fresh()->failure_code);
        $this->assertNull($target->fresh()->facility_definition_id);
        $this->assertSame($money + $world->rulesetVersion->settings['turn_processing']['automatic_finance_money'], (int) $nation->fresh()->money);
        // An ordinary plan at the same target still uses ordinary distance and cost,
        // even when a replacement working ship is present.
        $replacement = $this->ship($world, $nation, $target);
        $normal = $this->queue($service, $user, $nation->fresh(), $this->surfaceMapSpace($world), 'build_undersea_fire_station', $target);
        $cost = CommandDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)->where('key', 'build_undersea_fire_station')->value('cost_money');
        $nation->update(['money' => 100_000]);
        app(DomesticCommandExecutor::class)->execute($this->context($world, 3, hash('sha256', 'normal construction'), [$nation->id]));
        $this->assertSame('completed', $normal->fresh()->status);
        $this->assertSame(100_000 - $cost, (int) $nation->fresh()->money);
        $this->assertSame($target->id, $replacement->fresh()->map_cell_id);
    }

    public function test_only_the_working_ship_is_hidden_outside_other_nations_vision(): void
    {
        $world = $this->lightweightWorld();
        [, $owner] = $this->nation($world, '工作船所有');
        [, $viewer] = $this->nation($world, '船閲覧国');
        $cell = $this->remoteSea($world);
        $work = $this->ship($world, $owner, $cell);
        $presenter = app(MapCellPresenter::class);
        $name = app(SurfaceShipCatalog::class)->definition($world->rulesetVersion->settings, 'undersea_construction')->name;
        $hidden = $presenter->present($cell->fresh(), $viewer->id, 1);
        $this->assertNull($hidden['ship']);
        $this->assertStringNotContainsString($name, json_encode($hidden, JSON_UNESCAPED_UNICODE));
        $visible = $presenter->present($cell->fresh(), $viewer->id, 1, withinViewerVisibility: true);
        $this->assertSame($name, $visible['ship']['name']);
        $ownShip = $presenter->present($cell->fresh(), $owner->id, 1)['ship'];
        $this->assertSame($work->id, $ownShip['id']);
        $this->assertTrue($ownShip['undersea_construction']);
        $this->assertSame($ownShip['movement_mode'], app(SurfaceShipCatalog::class)->definition($world->rulesetVersion->settings, $work->ship_type_key)->movementMode);
        $this->assertSame([false, null], [$visible['ship']['undersea_construction'], $visible['ship']['movement_mode']]);
        $this->assertNull($presenter->present($cell->fresh(), null, 1)['ship']);
        $ordinaryCell = $this->remoteSea($world, $cell->id);
        $ordinary = $this->ship($world, $owner, $ordinaryCell, 'fishing');
        $this->assertSame($ordinary->id, $presenter->present($ordinaryCell->fresh(), $viewer->id, 1)['ship']['id']);
    }

    public function test_working_ship_build_uses_one_per_nation_and_the_database_enforces_it(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '造船国');
        $this->ownedWaterFacility($nation, 'port');
        $nation->update(['money' => 30_000]);
        $command = CommandDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)->where('key', 'build_ship')->sole();
        $definition = app(SurfaceShipCatalog::class)->definition($world->rulesetVersion->settings, 'undersea_construction');
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $this->surfaceMapSpace($world), 'build_ship', null, $definition->selector);
        app(DomesticCommandExecutor::class)->execute($this->context($world, 2, hash('sha256', 'build working ship'), [$nation->id]));
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(20_000, (int) $nation->fresh()->money);
        $ship = Ship::activeForNation($world->id, $nation->id)->sole();
        $this->assertSame([1, 1, null], [$ship->current_hp, $ship->max_hp, $ship->heading]);
        $again = $this->queue(app(CommandQueueService::class), $user, $nation->fresh(), $this->surfaceMapSpace($world), 'build_ship', null, $definition->selector);
        app(DomesticCommandExecutor::class)->execute($this->context($world, 3, hash('sha256', 'second working ship'), [$nation->id]));
        $this->assertSame('ship_capacity_reached', $again->fresh()->failure_code);
        try {
            DB::transaction(fn () => $this->ship($world, $nation, $this->remoteSea($world)));
            $this->fail('A second active working ship bypassed the database capacity guard.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_heading_only_movement_spends_ten_oil_and_construction_follows_before_next_movement(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '工作船航行');
        $this->ownedWaterFacility($nation, 'port');
        $origin = $this->remoteSea($world);
        $destination = MapCell::query()->where('map_space_id', $origin->map_space_id)->where('x', $origin->x + 1)->where('y', $origin->y)->sole();
        app(MapCellStateService::class)->transitionTerrain($destination, TerrainDefinition::query()->where('key', 'sea')->sole());
        $ship = $this->ship($world, $nation, $origin);
        $oil = NationResource::query()->where('nation_id', $nation->id)->whereHas('definition', fn ($q) => $q->where('key', 'oil'))->sole();
        $oil->update(['amount' => 30]);
        $this->executeCellResolution($this->context($world, 2, hash('sha256', 'working ship no heading'), [$nation->id]), $origin);
        $this->assertSame($origin->id, $ship->fresh()->map_cell_id);
        $this->assertSame(30, $oil->fresh()->amount);
        $ship->update(['heading' => GridCoordinate::EAST]);
        $nation->update(['money' => 100_000]);
        // The finance predecessor consumes Turn 3 while the ship moves; construction waits for Turn 4.
        $this->queue(app(CommandQueueService::class), $user, $nation->fresh(), $this->surfaceMapSpace($world), 'finance', null);
        $item = $this->queue(app(CommandQueueService::class), $user, $nation->fresh(), $this->surfaceMapSpace($world), 'build_undersea_fire_station', $origin, parameters: ['construction_ship_id' => $ship->id]);
        $this->executeCellResolution($this->context($world, 3, hash('sha256', 'working ship heading'), [$nation->id]), $origin);
        $this->assertSame($destination->id, $ship->fresh()->map_cell_id);
        $this->assertSame(20, $oil->fresh()->amount);
        $this->assertSame(1, $ship->fresh()->current_hp);
        $this->assertSame('queued', $item->fresh()->status);
        $context = $this->context($world, 4, hash('sha256', 'working ship moved before building'), [$nation->id]);
        app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertNull($origin->fresh()->facility_definition_id);
        $this->assertSame('undersea_fire_station', $destination->fresh()->facility?->key);
        $next = MapCell::query()->where('map_space_id', $origin->map_space_id)->where('x', $destination->x + 1)->where('y', $destination->y)->sole();
        app(MapCellStateService::class)->transitionTerrain($next, TerrainDefinition::query()->where('key', 'sea')->sole());
        $this->executeCellResolution($context, $destination);
        $this->assertSame($next->id, $ship->fresh()->map_cell_id);
        $this->assertSame(10, $oil->fresh()->amount);
        $this->assertSame('undersea_fire_station', $destination->fresh()->facility?->key);
        $this->assertNull($next->fresh()->facility_definition_id);
    }

    public function test_moved_construction_rechecks_the_new_cell_and_records_its_failure_location(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '移動先の建設条件');
        $origin = $this->remoteSea($world);
        $target = $this->remoteSea($world, $origin->id);
        $ship = $this->ship($world, $nation, $origin);
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $this->surfaceMapSpace($world), 'build_seabed_base', $origin, parameters: ['construction_ship_id' => $ship->id]);
        app(MapCellStateService::class)->setFacility($target, FacilityDefinition::query()->where('key', 'seabed_base')->sole());
        $target->update(['owner_nation_id' => $nation->id]);
        $ship->update(['map_cell_id' => $target->id]);
        $money = $item->definition->cost_money * 3;
        $nation->update(['money' => $money]);
        app(DomesticCommandExecutor::class)->execute($this->context($world, 2, hash('sha256', 'blocked construction destination'), [$nation->id]));
        $this->assertSame('facility_exists', $item->fresh()->failure_code);
        $this->assertSame([$target->x, $target->y], [$item->fresh()->failure_metadata['x'], $item->fresh()->failure_metadata['y']]);
        $this->assertNull($origin->fresh()->facility_definition_id);
        $this->assertSame('seabed_base', $target->fresh()->facility?->key);
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $world->rulesetVersion)->money;
        $finance = min($world->rulesetVersion->settings['turn_processing']['automatic_finance_money'], max(0, $capacity - $money));
        $this->assertSame($money + $finance, (int) $nation->fresh()->money);
    }

    public function test_sinking_the_working_ship_does_not_add_karma_or_destroy_the_facility_below_it(): void
    {
        [$world, $user, $firing, $targetNation] = $this->combatants('工作船撃沈');
        $base = $this->missileBase($firing);
        $target = $this->remoteSea($world);
        $ship = $this->ship($world, $targetNation, $target);
        app(MapCellStateService::class)->setFacility($target, FacilityDefinition::query()->where('key', 'seabed_base')->sole());
        $target->owner_nation_id = $targetNation->id;
        $target->save();
        $item = $this->queue(app(CommandQueueService::class), $user, $firing, $this->surfaceMapSpace($world), 'pp_missile', $target);
        $radius = $item->definition->metadata['deviation_radius'];
        $context = $this->context($world, 2, $this->seedForImpactIndex($item, $target, $radius, $target), [$firing->id, $targetNation->id]);
        $context->state->setLifecycleNationIds([$firing->id, $targetNation->id]);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        $karma->snapshotMissileBoundary($context);
        $this->resolveMissile($context, $base);
        $this->assertSame(Ship::STATE_REMOVED, $ship->fresh()->state);
        $this->assertSame(0, $context->state->karmaLedgerForNation($firing->id)['crime_points']);
        $this->assertSame('seabed_base', $target->fresh()->facility->key);
        $this->assertSame($targetNation->id, $target->fresh()->owner_nation_id);
    }

    public function test_ship_construction_requires_the_full_tripled_cost_before_charging(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '工作船資金');
        $target = $this->remoteSea($world);
        $ship = $this->ship($world, $nation, $target);
        $cost = CommandDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)->where('key', 'build_seabed_base')->value('cost_money');
        $nation->update(['money' => $cost * 2]);
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $this->surfaceMapSpace($world), 'build_seabed_base', $target, parameters: ['construction_ship_id' => $ship->id]);
        app(DomesticCommandExecutor::class)->execute($this->context($world, 2, hash('sha256', 'working ship insufficient funds'), [$nation->id]));
        $this->assertSame('insufficient_funds', $item->fresh()->failure_code);
        $this->assertNull($target->fresh()->facility_definition_id);
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $world->rulesetVersion)->money;
        $expectedFinance = min($world->rulesetVersion->settings['turn_processing']['automatic_finance_money'], max(0, $capacity - $cost * 2));
        $this->assertSame($cost * 2 + $expectedFinance, $nation->fresh()->money);
        $this->assertSame(Ship::STATE_ACTIVE, $ship->fresh()->state);
    }

    private function remoteSea(World $world, ?int $excluded = null): MapCell
    {
        $space = $this->surfaceMapSpace($world);
        $owned = MapCell::query()->where('map_space_id', $space->id)->whereNotNull('owner_nation_id')->get(['x', 'y']);

        return MapCell::query()->where('map_space_id', $space->id)->whereNull('owner_nation_id')
            ->whereNull('facility_definition_id')->doesntHave('ship')->where('id', '<>', $excluded ?? 0)
            ->whereBetween('x', [$space->min_x + 2, $space->max_x - 2])
            ->whereHas('terrain', fn ($q) => $q->where('key', 'sea'))->orderBy('id')->get()
            ->firstOrFail(fn (MapCell $cell) => ! $owned->contains(fn (MapCell $anchor) => (new GridCoordinate($cell->x, $cell->y))->distanceTo(new GridCoordinate($anchor->x, $anchor->y)) <= 4));
    }

    private function ship(World $world, Nation $nation, MapCell $cell, string $type = 'undersea_construction'): Ship
    {
        $definition = app(SurfaceShipCatalog::class)->definition($world->rulesetVersion->settings, $type);

        return Ship::query()->create([
            'world_id' => $world->id, 'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id, 'map_cell_id' => $cell->id, 'ship_type_key' => $type,
            'current_hp' => $definition->maximumHp, 'max_hp' => $definition->maximumHp,
            'heading' => null, 'state' => Ship::STATE_ACTIVE, 'version' => 1,
        ]);
    }
}
