<?php

namespace Tests\Feature;

use App\Application\InitialIslandGenerator;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Domain\Hex\HexCoordinate;
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
        $this->assertSame(['fish' => 0, 'monster_meat' => 0, 'wheat' => 100], NationResource::query()
            ->where('nation_id', $nation->id)
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resources.resource_definition_id')
            ->pluck('amount', 'key')->sortKeys()->all());
        $this->assertSame(3, ResourceDefinition::query()->where('category', 'food')->count());
        $this->assertSame(1000, $nation->capital->cell()->value('population'));
        $this->assertSame(3, $this->terrainCount('forest'));
        $this->assertSame(1, $this->terrainCount('mountain'));
        $this->assertSame(1, $this->facilityCount('village'));
        $this->assertSame(1, $this->facilityCount('missile_base'));
        $this->assertSame(1, $this->facilityCount('capital'));
        $this->assertSame(19, MapCell::query()->where('owner_nation_id', $nation->id)->count());
        $this->assertSame('sea', MapCell::query()->where('q', 20)->where('r', 20)->firstOrFail()->terrain()->value('key'));
    }

    public function test_second_nation_does_not_overlap_and_capitals_are_at_least_twelve_apart(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $service = app(NationCreationService::class);
        $first = $service->create(User::factory()->create(), $world, '第一国');
        $second = $service->create(User::factory()->create(), $world, '第二国');
        $a = new HexCoordinate($first->capital->q, $first->capital->r);
        $b = new HexCoordinate($second->capital->q, $second->capital->r);

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

    public function test_generator_failure_rolls_back_nation_island_capital_membership_and_request(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $this->app->bind(InitialIslandGenerator::class, fn () => new class implements InitialIslandGenerator
        {
            public function generate(MapSpace $mapSpace, Nation $nation, HexCoordinate $center, string $seed): NationCapital
            {
                MapCell::query()->where('map_space_id', $mapSpace->id)->where('q', $center->q)->where('r', $center->r)->update(['population' => 999]);
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
