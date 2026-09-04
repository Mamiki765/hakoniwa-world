<?php

namespace Tests\Feature;

use App\Application\CapitalPlacementService;
use App\Application\InitialIslandGenerator;
use App\Application\InitialIslandPlan;
use App\Application\NationCreationService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\GridCoordinate;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Ship;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class NationCreationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_nation_creation_generates_legacy_inspired_island_capital_and_territory(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '最初の国', '試験島主');

        $this->assertSame(1, $nation->nation_number);
        $this->assertSame($world->current_turn, $nation->registered_turn);
        $this->assertSame(2000, $nation->idle_counter);
        $this->assertSame(100, $nation->money);
        $this->assertSame([
            'fish' => 0, 'industrial_goods' => 0, 'minerals' => 0, 'monster_meat' => 0,
            'oil' => 0, 'wheat' => 10_000,
        ], NationResource::query()
            ->where('nation_id', $nation->id)
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resources.resource_definition_id')
            ->pluck('amount', 'key')->sortKeys()->all());
        $this->assertSame(3, ResourceDefinition::query()->where('category', 'food')->count());
        $this->assertSame(3, ResourceDefinition::query()
            ->where('category', 'food')->where('unit', 'ton')->where('unit_label', 'トン')->count());
        $oil = ResourceDefinition::query()->where('key', 'oil')->sole();
        $this->assertSame(['石油', 'energy', 'ten_thousand_barrels', '万バレル', true, true, 'sale.oil'], [
            $oil->name, $oil->category, $oil->unit, $oil->unit_label,
            $oil->storable, $oil->tradable, $oil->sale_price_key,
        ]);
        $this->assertSame(6, $nation->salePolicies()->count());
        $this->assertDatabaseHas('nation_resource_sale_policies', [
            'nation_id' => $nation->id,
            'resource_definition_id' => $oil->id,
            'policy' => 'stockpile',
            'keep_amount' => null,
            'version' => 1,
        ]);
        $this->assertSame(5_000, app(NationCapacityResolver::class)->resolve($nation)->resource('oil'));
        $this->assertSame(1000, $nation->capital->cell()->value('population'));
        $this->assertSame(3, $this->terrainCount('forest'));
        $this->assertSame(3, MapCell::query()->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->where('terrain_quantity', 500)->count());
        $this->assertSame(1, $this->terrainCount('mountain'));
        $this->assertSame(1, $this->facilityCount('village'));
        $this->assertSame(1, $this->facilityCount('missile_base'));
        $this->assertSame(1, $this->facilityCount('capital'));
        $this->assertSame(19, MapCell::query()->where('owner_nation_id', $nation->id)->count());
        $shallowCells = MapCell::query()->whereHas('terrain', fn ($query) => $query->where('key', 'shallow'))->get();
        $this->assertGreaterThanOrEqual(3, $shallowCells->count());
        foreach ($shallowCells as $shallow) {
            $this->assertNull($shallow->owner_nation_id);
            $this->assertNull($shallow->facility_definition_id);
            $this->assertLessThanOrEqual(
                5,
                (new GridCoordinate($nation->capital->x, $nation->capital->y))
                    ->distanceTo(new GridCoordinate($shallow->x, $shallow->y)),
            );
        }
        $this->assertGreaterThanOrEqual(1, MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->count());
        $this->assertSame('sea', MapCell::query()->where('x', 20)->where('y', 20)->firstOrFail()->terrain()->value('key'));
    }

    public function test_second_nation_does_not_overlap_and_capitals_are_at_least_twelve_apart(): void
    {
        $world = $this->lightweightWorld();
        $service = app(NationCreationService::class);
        $first = $service->create(User::factory()->create(), $world, '第一国', '試験島主');
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $blockedCenter = app(CapitalPlacementService::class)->candidates($space, 1)[0];
        $blockedCell = MapCell::query()
            ->where('map_space_id', $space->id)
            ->where('x', $blockedCenter->x)
            ->where('y', $blockedCenter->y)
            ->firstOrFail();
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $first->id,
            'map_cell_id' => $blockedCell->id,
            'ship_type_key' => 'fishing',
            'current_hp' => 1,
            'max_hp' => 1,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $second = $service->create(User::factory()->create(), $world, '第二国', '試験島主');
        $a = new GridCoordinate($first->capital->x, $first->capital->y);
        $b = new GridCoordinate($second->capital->x, $second->capital->y);

        $this->assertSame(1, $first->nation_number);
        $this->assertSame(2, $second->nation_number);
        $this->assertGreaterThanOrEqual(12, $a->distanceTo($b));
        $this->assertNotSame($first->capital->map_cell_id, $second->capital->map_cell_id);
        $this->assertNotSame($blockedCell->id, $second->capital->map_cell_id);
        $this->assertSame($blockedCell->id, $ship->fresh()->map_cell_id);
        $this->assertSame(38, MapCell::query()->whereNotNull('owner_nation_id')->count());
    }

    public function test_same_user_cannot_create_two_nations_in_one_world(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $service = app(NationCreationService::class);
        $service->create($user, $world, '一つ目', '試験島主');

        $this->expectException(DomainException::class);
        $service->create($user, $world, '二つ目', '試験島主');
    }

    public function test_initial_shallow_coordinates_are_deterministic_for_the_same_seed_and_state(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $service = app(NationCreationService::class);

        DB::beginTransaction();
        $first = $service->create($user, $world, '再現国', '試験島主');
        $firstCoordinates = MapCell::query()
            ->whereHas('terrain', fn ($query) => $query->where('key', 'shallow'))
            ->orderBy('x')->orderBy('y')->get(['x', 'y'])->map->only(['x', 'y'])->all();
        DB::rollBack();

        $second = $service->create($user, $world, '再現国', '試験島主');
        $secondCoordinates = MapCell::query()
            ->whereHas('terrain', fn ($query) => $query->where('key', 'shallow'))
            ->orderBy('x')->orderBy('y')->get(['x', 'y'])->map->only(['x', 'y'])->all();

        $this->assertSame([$first->capital->x, $first->capital->y], [$second->capital->x, $second->capital->y]);
        $this->assertSame($firstCoordinates, $secondCoordinates);
    }

    public function test_generator_failure_rolls_back_nation_island_capital_membership_and_request(): void
    {
        $world = $this->lightweightWorld();
        $this->app->bind(InitialIslandGenerator::class, fn () => new class implements InitialIslandGenerator
        {
            public function plan(MapSpace $mapSpace, Nation $nation, GridCoordinate $center, string $seed): InitialIslandPlan
            {
                MapCell::query()->where('map_space_id', $mapSpace->id)->where('x', $center->x)->where('y', $center->y)->update(['population' => 999]);
                throw new RuntimeException('injected island failure');
            }

            public function apply(InitialIslandPlan $plan, MapSpace $mapSpace, Nation $nation): NationCapital
            {
                throw new RuntimeException('unreachable island apply');
            }

            public function generate(MapSpace $mapSpace, Nation $nation, GridCoordinate $center, string $seed): NationCapital
            {
                MapCell::query()->where('map_space_id', $mapSpace->id)->where('x', $center->x)->where('y', $center->y)->update(['population' => 999]);
                throw new RuntimeException('injected island failure');
            }
        });

        try {
            app(NationCreationService::class)->create(User::factory()->create(), $world, '失敗国', '試験島主');
            $this->fail('Expected island failure.');
        } catch (RuntimeException) {
            $this->assertSame(0, Nation::query()->count());
            $this->assertSame(0, NationCapital::query()->count());
            $this->assertSame(0, NationResource::query()->count());
            $this->assertSame(0, DB::table('nation_memberships')->count());
            $this->assertSame(0, DB::table('nation_creation_requests')->count());
            $this->assertSame(0, MapCell::query()->where('population', '>', 0)->count());
        }
    }

    private function terrainCount(string $key): int
    {
        return DB::table('map_cells')->join('terrain_definitions', 'terrain_definitions.id', '=', 'map_cells.terrain_definition_id')->where('terrain_definitions.key', $key)->count();
    }

    private function facilityCount(string $key): int
    {
        return DB::table('map_cells')->join('facility_definitions', 'facility_definitions.id', '=', 'map_cells.facility_definition_id')->where('facility_definitions.key', $key)->count();
    }
}
