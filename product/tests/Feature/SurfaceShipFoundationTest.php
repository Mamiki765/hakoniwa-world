<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\Map\MapCellStateService;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SurfaceShipFoundationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_ship_rows_enforce_surface_identity_capacity_and_separate_occupancy(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '船舶基盤国', '船舶島主');
        $cells = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->limit(9)->get();
        $this->assertCount(9, $cells);
        $this->assertTrue(Schema::hasTable('ships'));
        foreach (['home_port_map_cell_id', 'level', 'experience'] as $excludedColumn) {
            $this->assertFalse(Schema::hasColumn('ships', $excludedColumn));
        }

        $fishing = [];
        foreach (range(0, 2) as $index) {
            $fishing[] = $this->createShip($world, $nation, $cells[$index], 'fishing', 1);
        }
        $this->assertCount(3, $nation->ships()->where('state', Ship::STATE_ACTIVE)
            ->where('ship_type_key', 'fishing')->get());
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[3], 'fishing', 1),
            'fourth active Ship of one type',
        );

        $tourist = $this->createShip($world, $nation, $cells[3], 'tourist', 2);
        $this->assertSame($cells[3]->id, $tourist->cell()->value('id'));
        $this->assertSame($world->ruleset_version_id, $tourist->rulesetVersion()->value('id'));
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[4], 'tourist', 1),
            'Ship maximum HP outside its Ruleset snapshot',
        );
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[4], 'unknown', 1),
            'Ship type outside its Ruleset snapshot',
        );
        $historicalRulesetId = (int) DB::table('ruleset_versions')
            ->where('key', 'hakoniwa-2s-plus-v19')->value('id');
        $this->assertConstraintRejects(
            fn () => Ship::query()->create([
                'world_id' => $world->id,
                'ruleset_version_id' => $historicalRulesetId,
                'nation_id' => $nation->id,
                'map_cell_id' => $cells[4]->id,
                'ship_type_key' => 'fishing',
                'current_hp' => 1,
                'max_hp' => 1,
                'heading' => null,
                'state' => Ship::STATE_ACTIVE,
                'version' => 1,
            ]),
            'new Ship bound to a historical Ruleset snapshot',
        );
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[3], 'exploration', 2),
            'second active Ship on one cell',
        );

        $seabedBaseCell = $this->withFacility($cells[4], 'seabed_base', $nation);
        $coexisting = $this->createShip($world, $nation, $seabedBaseCell, 'exploration', 2);
        $this->assertSame('seabed_base', $coexisting->cell()->firstOrFail()->facility()->value('key'));
        $this->assertConstraintRejects(
            fn () => $coexisting->cell()->firstOrFail()->update([
                'terrain_definition_id' => TerrainDefinition::query()->where('key', 'shallow')->value('id'),
            ]),
            'occupied Ship cell changing away from deep sea',
        );
        $this->assertConstraintRejects(
            fn () => $coexisting->cell()->firstOrFail()->update([
                'facility_definition_id' => FacilityDefinition::query()->where('key', 'seabed_oil_field')->value('id'),
            ]),
            'occupied Ship cell receiving a public sea facility',
        );

        $oilFieldCell = $this->withFacility($cells[5], 'seabed_oil_field', $nation);
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $oilFieldCell, 'exploration', 2),
            'Ship on a public sea facility',
        );

        $monster = $this->monster($world);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cells[6]->id,
        ]);
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[6], 'exploration', 2),
            'Ship sharing a Monster cell',
        );
        $shipBeforeMonster = $this->createShip($world, $nation, $cells[7], 'exploration', 2);
        $otherMonster = $this->monster($world);
        $this->assertConstraintRejects(
            fn () => MonsterOccupancy::query()->create([
                'monster_instance_id' => $otherMonster->id,
                'map_cell_id' => $shipBeforeMonster->map_cell_id,
            ]),
            'Monster sharing a Ship cell',
        );

        $fishing[0]->update([
            'map_cell_id' => null,
            'state' => Ship::STATE_REMOVED,
            'removal_reason' => 'scuttled',
            'removed_at' => now(),
            'version' => 2,
        ]);
        $replacement = $this->createShip($world, $nation, $cells[8], 'fishing', 1);
        $this->assertSame(Ship::STATE_REMOVED, $fishing[0]->fresh()->state);
        $this->assertSame(Ship::STATE_ACTIVE, $replacement->state);
    }

    public function test_owner_can_change_active_ship_heading_with_optimistic_version_without_spending_a_turn(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '進路変更国', '進路島主');
        $cell = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))->firstOrFail();
        $ship = $this->createShip($world, $nation, $cell, 'fishing', 1);
        $path = "/api/v1/nations/{$nation->id}/ships/{$ship->id}/heading";

        $this->actingAs($owner)->patchJson($path, ['heading' => 0, 'expected_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.heading', 0)
            ->assertJsonPath('data.version', 2);
        $this->assertSame(1, $world->fresh()->current_turn);
        $this->actingAs($owner)->patchJson($path, ['heading' => 1, 'expected_version' => 1])
            ->assertConflict();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->patchJson($path, ['heading' => null, 'expected_version' => 2])
            ->assertForbidden();
        $nation->update([
            'state' => 'recovery',
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
        ]);
        $this->actingAs($owner)->patchJson($path, ['heading' => null, 'expected_version' => 2])
            ->assertUnprocessable();
        $this->assertSame([0, 2], [$ship->fresh()->heading, $ship->fresh()->version]);
    }

    private function createShip(
        World $world,
        Nation $nation,
        MapCell $cell,
        string $type,
        int $maxHp,
    ): Ship {
        return Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id,
            'map_cell_id' => $cell->id,
            'ship_type_key' => $type,
            'current_hp' => $maxHp,
            'max_hp' => $maxHp,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
    }

    private function withFacility(MapCell $cell, string $facilityKey, Nation $owner): MapCell
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
        );
        $cell->owner_nation_id = $owner->id;
        $cell->save();

        return $cell->fresh(['terrain', 'facility']);
    }

    private function monster(World $world): MonsterInstance
    {
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')->firstOrFail();

        return MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
    }

    private function assertConstraintRejects(callable $mutation, string $label): void
    {
        try {
            DB::transaction($mutation);
            $this->fail("Database accepted {$label}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
