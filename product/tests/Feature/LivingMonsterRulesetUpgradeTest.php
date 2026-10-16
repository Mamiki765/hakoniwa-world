<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\MonsterTurnService;
use App\Application\SurfaceShipTurnService;
use App\Application\TurnRunner;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Turn\TurnSeedGenerator;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\NationMonsterKillStat;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\Ship;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\CommandAndMissileTestCase;

final class LivingMonsterRulesetUpgradeTest extends CommandAndMissileTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private array $history = [];

    public function test_official_turn_after_upgrade_includes_the_old_sea_monster_and_preserves_separate_occupancy(): void
    {
        [$world, , $monster] = $this->upgradedSeaEncounter('fishing');
        $this->app->instance(TurnSeedGenerator::class, new class implements TurnSeedGenerator
        {
            public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
            {
                return hash('sha256', 'living monster upgrade official turn');
            }
        });

        $run = app(TurnRunner::class)->run($world);

        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status, $run->failure_message ?? '');
        $this->assertSame($run->target_turn, $world->fresh()->current_turn);
        $this->assertTrue(DB::table('audit_events')->where('subject_id', $monster->id)
            ->where('event_type', 'monster.moved')->exists());
        $this->assertFalse(DB::table('ships')->join('monster_occupancies', 'monster_occupancies.map_cell_id', '=', 'ships.map_cell_id')
            ->where('ships.world_id', $world->id)->where('ships.state', Ship::STATE_ACTIVE)->exists());
        $this->assertHistoryUnchanged();
    }

    public function test_warship_kills_old_monster_and_clears_the_shared_removal_batch_after_upgrade(): void
    {
        [$world, $nation, $monster, $ship] = $this->upgradedSeaEncounter('warship');
        $ship->update(['heading' => null]);
        $context = $this->context($world, $world->current_turn + 1, hash('sha256', 'old monster warship'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);
        $space = $this->surfaceMapSpace($world);
        $cells = MapCell::query()->where('map_space_id', $space->id)->with(['terrain', 'facility', 'ownerNation'])
            ->get()->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y)->all();
        $context->state->setSurfaceCellIds(array_map(fn (MapCell $cell): int => $cell->id, array_values($cells)));
        $monsters = app(MonsterTurnService::class)->load($context);
        $shipService = app(SurfaceShipTurnService::class);
        $ships = $shipService->load($context, $space);
        $occupiedCellId = $monster->occupancy()->value('map_cell_id');

        $shipService->processCell(
            $context, $space, $cells[$ship->cell->x.':'.$ship->cell->y], $cells, $monsters, $ships,
        );

        $this->assertSame('killed', $monster->fresh()->state);
        $this->assertNull($monsters->occupancyAt($occupiedCellId));
        $this->assertFalse($monster->occupancy()->exists());
        $this->assertSame($this->history[0][0]->monster_definition_id, $monster->fresh()->monster_definition_id);
        $this->assertDatabaseHas('nation_monster_kill_stats', [
            'world_id' => $world->id, 'nation_id' => $nation->id, 'kill_count' => 1,
            'monster_definition_id' => MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
                ->where('key', $monster->definition->key)->sole()->id,
        ]);
        $this->assertHistoryUnchanged();
    }

    private function upgradedSeaEncounter(string $shipType): array
    {
        $next = config('hakoniwa.ruleset');
        config(['hakoniwa.ruleset' => require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v36.php')]);
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '生存怪獣移行');
        $port = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        app(MapCellStateService::class)->setFacility($port, FacilityDefinition::query()->where('key', 'port')->sole());
        $port->save();
        $water = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereBetween('x', [5, 25])->whereBetween('y', [5, 25])
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))->orderBy('id')->get()
            ->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y);
        foreach ($water as $origin) {
            $coordinate = (new GridCoordinate($origin->x, $origin->y))->neighbor(GridCoordinate::EAST);
            $target = $water->get($coordinate->x.':'.$coordinate->y);
            if ($target !== null) {
                break;
            }
        }
        $this->assertInstanceOf(MapCell::class, $target);
        $monster = $this->monster($world, $target, 'aoi_inora');
        $monster->update(['current_hp' => 1]);
        $dead = $monster->replicate();
        $dead->fill(['current_hp' => 0, 'state' => 'killed', 'removal_reason' => 'warship', 'removed_at' => now()])->save();
        $stat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id, 'nation_id' => $nation->id, 'monster_definition_id' => $monster->monster_definition_id,
            'kill_count' => 1, 'first_killed_turn' => 1, 'last_killed_turn' => 1, 'version' => 1,
        ]);
        $this->history = array_map(fn ($model): array => [$model, $model->fresh()->getAttributes()], [$dead, $stat, $world->rulesetVersion, $monster->definition]);
        $shipDefinition = app(SurfaceShipCatalog::class)->definition($world->rulesetVersion->settings, $shipType);
        $ship = Ship::query()->create([
            'world_id' => $world->id, 'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id, 'map_cell_id' => $origin->id, 'ship_type_key' => $shipType,
            'current_hp' => $shipDefinition->maximumHp, 'max_hp' => $shipDefinition->maximumHp,
            'heading' => GridCoordinate::EAST, 'state' => Ship::STATE_ACTIVE, 'version' => 1,
        ]);
        $fuel = $next['surface_ships']['movement']['fuel_resource_key'];
        NationResource::query()->updateOrCreate([
            'nation_id' => $nation->id, 'resource_definition_id' => ResourceDefinition::query()->where('key', $fuel)->sole()->id,
        ], ['amount' => 100]);
        config(['hakoniwa.ruleset' => $next]);
        (require database_path('migrations/2026_10_14_130000_publish_undersea_construction_ship_ruleset.php'))->up();
        (require database_path('migrations/2026_10_15_010000_publish_ecopo_ruleset.php'))->up();
        $world->refresh()->unsetRelation('rulesetVersion');

        return [$world, $nation, $monster, $ship];
    }

    private function assertHistoryUnchanged(): void
    {
        foreach ($this->history as [$model, $attributes]) {
            $this->assertSame($attributes, $model->fresh()->getAttributes());
        }
    }
}
