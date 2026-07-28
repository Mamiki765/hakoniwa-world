<?php

namespace Tests\Feature;

use App\Application\InitialIslandGenerator;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Domain\Map\GridCoordinate;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class NationCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nation_creation_generates_legacy_inspired_island_capital_and_territory(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '最初の国');

        $this->assertSame(100, $nation->money);
        $this->assertSame([
            'fish' => 0, 'industrial_goods' => 0, 'minerals' => 0, 'monster_meat' => 0, 'wheat' => 10_000,
        ], NationResource::query()
            ->where('nation_id', $nation->id)
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resources.resource_definition_id')
            ->pluck('amount', 'key')->sortKeys()->all());
        $this->assertSame(3, ResourceDefinition::query()->where('category', 'food')->count());
        $this->assertSame(3, ResourceDefinition::query()
            ->where('category', 'food')->where('unit', 'ton')->where('unit_label', 'トン')->count());
        $this->assertSame(5, $nation->salePolicies()->count());
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
        $world = app(OceanWorldGenerator::class)->initialize();
        $service = app(NationCreationService::class);
        $first = $service->create(User::factory()->create(), $world, '第一国');
        $second = $service->create(User::factory()->create(), $world, '第二国');
        $a = new GridCoordinate($first->capital->x, $first->capital->y);
        $b = new GridCoordinate($second->capital->x, $second->capital->y);

        $this->assertGreaterThanOrEqual(12, $a->distanceTo($b));
        $this->assertNotSame($first->capital->map_cell_id, $second->capital->map_cell_id);
        $this->assertSame(38, MapCell::query()->whereNotNull('owner_nation_id')->count());
    }

    public function test_same_user_cannot_create_two_nations_in_one_world(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $service = app(NationCreationService::class);
        $service->create($user, $world, '一つ目');

        $this->expectException(DomainException::class);
        $service->create($user, $world, '二つ目');
    }

    public function test_initial_shallow_coordinates_are_deterministic_for_the_same_seed_and_state(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $service = app(NationCreationService::class);

        DB::beginTransaction();
        $first = $service->create($user, $world, '再現国');
        $firstCoordinates = MapCell::query()
            ->whereHas('terrain', fn ($query) => $query->where('key', 'shallow'))
            ->orderBy('x')->orderBy('y')->get(['x', 'y'])->map->only(['x', 'y'])->all();
        DB::rollBack();

        $second = $service->create($user, $world, '再現国');
        $secondCoordinates = MapCell::query()
            ->whereHas('terrain', fn ($query) => $query->where('key', 'shallow'))
            ->orderBy('x')->orderBy('y')->get(['x', 'y'])->map->only(['x', 'y'])->all();

        $this->assertSame([$first->capital->x, $first->capital->y], [$second->capital->x, $second->capital->y]);
        $this->assertSame($firstCoordinates, $secondCoordinates);
    }

    public function test_initial_island_completes_when_fewer_coastal_candidates_exist_than_the_configured_minimum(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $settings = config('hakoniwa.published_rulesets.roadmap-pr6-v1');
        $settings['key'] = 'test-shallow-candidate-shortage-v1';
        $settings['initial_island_minimum_shallow_cells'] = 1000;
        $ruleset = app(RulesetPublisher::class)->publish($settings);
        $world->update(['ruleset_version_id' => $ruleset->id]);

        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '候補不足国');

        $this->assertNotNull($nation->capital);
        $this->assertSame(19, MapCell::query()->where('owner_nation_id', $nation->id)->count());
        $this->assertLessThan(1000, $this->terrainCount('shallow'));
    }

    public function test_generator_failure_rolls_back_nation_island_capital_membership_and_request(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $this->app->bind(InitialIslandGenerator::class, fn () => new class implements InitialIslandGenerator
        {
            public function generate(MapSpace $mapSpace, Nation $nation, GridCoordinate $center, string $seed): NationCapital
            {
                MapCell::query()->where('map_space_id', $mapSpace->id)->where('x', $center->x)->where('y', $center->y)->update(['population' => 999]);
                throw new RuntimeException('injected island failure');
            }
        });

        try {
            app(NationCreationService::class)->create(User::factory()->create(), $world, '失敗国');
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
