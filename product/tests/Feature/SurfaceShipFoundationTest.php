<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\Map\MapCellStateService;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Ship\SurfaceShipDefinition;
use App\Models\CommandDefinition;
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
use App\Services\AssetManifestResolver;
use App\Services\MapCellPresenter;
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
        $shipView = app(MapCellPresenter::class)->present($cells[0]->fresh(), $nation->id, 1);
        $this->assertNull($shipView['owner_nation_id']);
        $this->assertSame($nation->nation_number, $shipView['ship']['owner_nation']['nation_number']);
        $this->assertStringContainsString('このマスの所有者 中立', $shipView['aria_label']);
        $this->assertStringContainsString(
            "船の所有者 {$nation->name} N{$nation->nation_number}",
            $shipView['aria_label'],
        );
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

        $nation->update(['state' => 'abandoned']);
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[0], 'exploration', 2),
            'active Ship newly owned by an abandoned Nation',
        );
    }

    public function test_npc_ship_rows_use_only_npc_definitions_and_present_without_a_nation_owner(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'NPC検証国', 'NPC検証島主');
        $cells = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->limit(4)->get();
        $this->assertCount(4, $cells);

        $pirate = $this->createNpcShip($world, $cells[0], 'pirate', 2, 3);
        $treasure = $this->createNpcShip($world, $cells[1], 'treasure', 1, 1);
        $this->assertNull($pirate->nation);
        $this->assertNull($treasure->nation);

        $view = app(MapCellPresenter::class)->present($cells[0]->fresh(), $nation->id, 1);
        $this->assertSame(['pirate', '海賊船', 2, 3, null], [
            $view['ship']['key'],
            $view['ship']['name'],
            $view['ship']['current_hp'],
            $view['ship']['max_hp'],
            $view['ship']['owner_nation'],
        ]);
        $this->assertStringContainsString('船 海賊船 HP 2/3', $view['aria_label']);
        $this->assertStringNotContainsString('船の所有者', $view['aria_label']);

        $buildCommand = CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'build_ship')
            ->firstOrFail();
        $this->assertSame(
            ['fishing', 'tourist', 'exploration', 'warship'],
            array_map(
                static fn (SurfaceShipDefinition $definition): string => $definition->key,
                app(SurfaceShipCatalog::class)->options($buildCommand),
            ),
        );
        $this->assertSame('ship-pirate.gif', app(AssetManifestResolver::class)->filenameForAssetKey('ship.pirate'));
        $this->assertSame('ship-treasure.gif', app(AssetManifestResolver::class)->filenameForAssetKey('ship.treasure'));

        $this->assertConstraintRejects(
            fn () => $this->createNpcShip($world, $cells[2], 'fishing', 1, 1),
            'Player Ship without a Nation',
        );
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[2], 'pirate', 3),
            'NPC-only Ship owned by a Nation',
        );
        $this->assertConstraintRejects(
            fn () => $this->createShip($world, $nation, $cells[0], 'fishing', 1),
            'Player Ship sharing an NPC Ship cell',
        );

        $monster = $this->monster($world);
        $this->assertConstraintRejects(
            fn () => MonsterOccupancy::query()->create([
                'monster_instance_id' => $monster->id,
                'map_cell_id' => $treasure->map_cell_id,
            ]),
            'Monster sharing an NPC Ship cell',
        );
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
        $headingEvent = DB::table('audit_events')->where('event_type', 'ship.heading.updated')->sole();
        $this->assertSame([
            $world->id,
            1,
            $nation->id,
            $cell->x,
            $cell->y,
            'admin',
        ], [
            $headingEvent->world_id,
            $headingEvent->turn,
            $headingEvent->nation_id,
            $headingEvent->x,
            $headingEvent->y,
            $headingEvent->visibility,
        ]);
        $headingMetadata = json_decode($headingEvent->metadata, true, flags: JSON_THROW_ON_ERROR);
        $expectedHeadingMetadata = [
            'world_id' => $world->id,
            'target_turn' => 1,
            'nation_id' => $nation->id,
            'before' => null,
            'after' => 0,
        ];
        ksort($headingMetadata);
        ksort($expectedHeadingMetadata);
        $this->assertSame($expectedHeadingMetadata, $headingMetadata);
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

    private function createNpcShip(
        World $world,
        MapCell $cell,
        string $type,
        int $currentHp,
        int $maxHp,
    ): Ship {
        return Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $cell->id,
            'ship_type_key' => $type,
            'current_hp' => $currentHp,
            'max_hp' => $maxHp,
            'population' => $type === 'pirate' ? 7_500 : null,
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
