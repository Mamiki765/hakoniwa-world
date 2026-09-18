<?php

namespace Tests\Feature;

use App\Application\NationBasicStatusProjection;
use App\Application\NationCreationService;
use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Facility\FacilityRankPolicy;
use App\Domain\Facility\MissileBaseRules;
use App\Domain\Map\MapCellStateService;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\ProductionDefinition;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class FacilityAndMapStateTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_ruleset_defines_typed_facility_capacity_and_production(): void
    {
        $ruleset = RulesetVersion::query()->where('key', config('hakoniwa.ruleset.key'))->firstOrFail();
        $capacities = app(FacilityCapacityService::class);

        foreach ([
            'farm' => [10, 2, 50, 10000],
            'factory' => [30, 10, 100, 30000],
            'mine' => [5, 5, 200, 5000],
        ] as $key => [$initial, $increment, $maximum, $people]) {
            $facility = FacilityDefinition::query()->where('key', $key)->firstOrFail();
            $description = $capacities->describe($facility, $capacities->initialScale($facility));

            $this->assertTrue($facility->enabled);
            $this->assertSame($initial, $description['initial_scale']);
            $this->assertSame($increment, $description['scale_increment']);
            $this->assertSame($maximum, $description['maximum_scale']);
            $this->assertSame(1000, $description['scale_unit_people']);
            $this->assertSame($people, $description['capacity_people']);
        }

        $this->assertSame([
            'factory_industrial_goods', 'farm_wheat', 'mine_minerals',
        ], ProductionDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->orderBy('key')->pluck('key')->all());
        $this->assertSame(['industrial_goods', 'minerals'], ResourceDefinition::query()
            ->whereIn('key', ['industrial_goods', 'minerals'])->orderBy('key')->pluck('key')->all());
    }

    public function test_cell_state_values_are_separate_and_reset_with_terrain_or_facility(): void
    {
        [$user, $nation] = $this->nation('状態国');
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();

        $this->assertSame(0, $forest->population);
        $this->assertSame(500, $forest->terrain_quantity);
        $this->assertNull($forest->facility_scale);
        $this->assertNull($forest->facility_experience);

        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain($forest, $plain);
        $this->assertNull($forest->terrain_quantity);

        $farm = FacilityDefinition::query()->where('key', 'farm')->firstOrFail();
        app(MapCellStateService::class)->setFacility($forest, $farm);
        $this->assertSame(10, $forest->facility_scale);
        $this->assertNull($forest->facility_experience);
        $this->assertSame(0, $forest->population);

        app(MapCellStateService::class)->setFacility($forest, null);
        $this->assertNull($forest->facility_definition_id);
        $this->assertNull($forest->facility_scale);
        $this->assertNull($forest->facility_experience);
        $this->assertNull($forest->facility_operational_state);
        $this->assertNotNull($user->id);
    }

    public function test_facility_scale_above_maximum_is_rejected(): void
    {
        $farm = FacilityDefinition::query()->where('key', 'farm')->firstOrFail();

        $this->expectException(DomainException::class);
        app(FacilityCapacityService::class)->validateScale($farm, 51);
    }

    public function test_rank_two_contract_drives_scale_boundaries_capacity_and_shared_map_details(): void
    {
        [$user, $nation, $mapSpace] = $this->nation('ランク施設国');
        $world = $nation->world()->with('rulesetVersion')->firstOrFail();
        $settings = $world->rulesetVersion->settings;
        $ranks = app(FacilityRankPolicy::class);

        foreach ([
            'farm' => [50, 1, 100],
            'factory' => [100, 5, 200],
            'mine' => [200, 2, 400],
        ] as $key => [$rankOneMaximum, $rankTwoIncrement, $rankTwoMaximum]) {
            $facility = FacilityDefinition::query()->where('key', $key)->firstOrFail();
            $this->assertSame(1, $ranks->rank($settings, $key, $rankOneMaximum));
            $this->assertSame(2, $ranks->rank($settings, $key, $rankOneMaximum + $rankTwoIncrement));
            $this->assertSame($rankOneMaximum, $ranks->expandedScale($settings, $facility, $rankOneMaximum - 1));
            $this->assertSame($rankOneMaximum + $rankTwoIncrement, $ranks->expandedScale($settings, $facility, $rankOneMaximum));
            $this->assertSame($rankTwoMaximum, $ranks->maximumScale($settings, $facility));
        }

        $farm = FacilityDefinition::query()->where('key', 'farm')->firstOrFail();
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        app(MapCellStateService::class)->setFacility($cell, $farm, 51, null, 100);
        $cell->save();

        $status = app(NationBasicStatusProjection::class)->forNation($nation->fresh());
        $this->assertSame(51_000, $status['farm_capacity_people']);
        $this->assertSame('tile.large_farm', $ranks->presentation($settings, $farm, 51)['asset_key']);

        $presented = $this->cellFromResponse(
            $this->actingAs($user)->getJson($this->chunkUrl($mapSpace, $cell))->assertOk()->json('data.cells'),
            $cell,
        );
        $details = collect($presented['details'])->keyBy('key');
        $this->assertSame('大農場', $presented['facility_name']);
        $this->assertSame('大農場', $presented['display_name']);
        $this->assertSame('tile.farm', $presented['asset']['key']);
        $this->assertSame(2, $details['facility_rank']['value']);
        $this->assertSame('51,000人規模', $details['facility_capacity']['formatted']);
        $this->assertSame('森3個分の台風耐性', $details['facility_effect']['formatted']);

        $cell->update(['facility_scale' => 50]);
        $presentedAfterDowngrade = $this->cellFromResponse(
            $this->actingAs($user)->getJson($this->chunkUrl($mapSpace, $cell))->assertOk()->json('data.cells'),
            $cell,
        );
        $downgradeDetails = collect($presentedAfterDowngrade['details'])->keyBy('key');
        $this->assertSame('農場', $presentedAfterDowngrade['facility_name']);
        $this->assertSame(1, $downgradeDetails['facility_rank']['value']);
        $this->assertSame('50,000人規模', $downgradeDetails['facility_capacity']['formatted']);
        $this->assertSame(
            '50,000人規模で再度農場整備するとランク2へ',
            $downgradeDetails['facility_promotion']['formatted'],
        );
    }

    public function test_owner_sees_missile_state_and_other_viewers_receive_indistinguishable_forest(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('秘匿国');
        $base = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('facility', fn ($query) => $query->where('key', 'missile_base'))->firstOrFail();
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();
        $missileRules = app(MissileBaseRules::class);
        $definition = $base->facility()->firstOrFail();

        $this->assertSame(0, $base->facility_experience);
        $this->assertNull($base->facility_scale);
        $this->assertSame(1, $missileRules->level($definition, 0));
        $this->assertSame(2, $missileRules->level($definition, 20));
        $this->assertSame(5, $missileRules->level($definition, 200));
        $this->assertSame(5, $missileRules->launchCapacity($definition, 200));

        $ownerResponse = $this->actingAs($owner)->getJson($this->chunkUrl($mapSpace, $base));
        $ownerResponse->assertOk()->assertHeader('Vary', 'Cookie');
        $cacheControl = explode(', ', (string) $ownerResponse->headers->get('Cache-Control'));
        sort($cacheControl);
        $this->assertSame(['max-age=0', 'no-store', 'private'], $cacheControl);
        $ownerCell = $this->cellFromResponse($ownerResponse->json('data.cells'), $base);
        $ownerDetails = collect($ownerCell['details'])->keyBy('key');
        $this->assertSame('missile_base', $ownerCell['facility']);
        $this->assertSame(0, $ownerDetails['facility_experience']['value']);
        $this->assertSame(1, $ownerDetails['facility_level']['value']);
        $this->assertSame(1, $ownerDetails['launch_capacity']['value']);
        $this->assertArrayNotHasKey('population', $ownerDetails->all());

        $outsider = User::factory()->create();
        $publicResponse = $this->actingAs($outsider)->getJson($this->chunkUrl($mapSpace, $base))->assertOk();
        $publicBase = $this->cellFromResponse($publicResponse->json('data.cells'), $base);
        $publicForest = $this->cellFromResponse($publicResponse->json('data.cells'), $forest);
        $this->assertSame('forest', $publicBase['terrain']);
        $this->assertNull($publicBase['facility']);
        $this->assertSame('tile.forest', $publicBase['asset']['key']);
        $this->assertSame($publicForest['details'], $publicBase['details']);
        $this->assertSame(
            'ペリドット海域',
            collect($publicBase['details'])->keyBy('key')['sea_area']['value'],
        );
        $this->assertSame(array_keys($publicForest), array_keys($publicBase));

        foreach (['x', 'y', 'aria_label'] as $key) {
            unset($publicBase[$key], $publicForest[$key]);
        }
        $this->assertSame($publicForest, $publicBase);
        $encoded = json_encode($publicBase, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('missile_base', $encoded);
        $this->assertStringNotContainsString('experience', $encoded);
        $this->assertStringNotContainsString('launch', $encoded);

        app(MapCellStateService::class)->setFacility($base, null);
        $this->assertNull($base->facility_experience);
        $this->assertNull($base->facility_operational_state);
    }

    public function test_facility_capacity_descriptor_is_formatted_without_population_zero(): void
    {
        [$user, $nation, $mapSpace] = $this->nation('施設国');
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        app(MapCellStateService::class)->setFacility($cell, FacilityDefinition::query()->where('key', 'factory')->firstOrFail());
        $cell->save();

        $presented = $this->cellFromResponse(
            $this->actingAs($user)->getJson($this->chunkUrl($mapSpace, $cell))->assertOk()->json('data.cells'),
            $cell,
        );
        $details = collect($presented['details'])->keyBy('key');
        $this->assertSame('30,000人規模', $details['facility_capacity']['formatted']);
        $this->assertFalse($details->has('planned_production'));
        $this->assertFalse($details->has('population'));
    }

    /** @return array{User, Nation, MapSpace} */
    private function nation(string $name): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name, '試験島主');

        return [$user, $nation, MapSpace::query()->where('world_id', $world->id)->firstOrFail()];
    }

    private function chunkUrl(MapSpace $mapSpace, MapCell $cell): string
    {
        return "/api/v1/map-spaces/{$mapSpace->id}/chunks/{$cell->chunk_x}/{$cell->chunk_y}";
    }

    /** @param array<int, array<string, mixed>> $cells @return array<string, mixed> */
    private function cellFromResponse(array $cells, MapCell $expected): array
    {
        $cell = collect($cells)->first(fn (array $cell): bool => $cell['x'] === $expected->x && $cell['y'] === $expected->y);
        $this->assertIsArray($cell);

        return $cell;
    }
}
