<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\DisasterTurnService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Domain\World\WorldGenerationProfile;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LandSubsidenceTurnTest extends TestCase
{
    use RefreshDatabase;

    public function test_land_area_definition_is_shared_by_private_public_and_eligibility_paths(): void
    {
        [$world, $nation, , $space, $owner] = $this->worldAndNation();
        $this->resetSurface($space);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->limit(8)->get();
        $this->setCell($cells[0], 'sea', null, $nation->id, 0);
        $this->setCell($cells[1], 'shallow', null, $nation->id, 0);
        $this->setCell($cells[2], 'sea', 'seabed_oil_field', $nation->id, 0);
        $this->setCell($cells[3], 'mountain', null, $nation->id, 0);
        $this->setCell($cells[4], 'mountain', 'mine', $nation->id, 0);
        $this->setCell($cells[5], 'plain', 'capital', $nation->id, 1_000);
        $this->setCell($cells[6], 'plain', 'village', $nation->id, 500);
        $this->setCell($cells[7], 'plain', 'farm', $nation->id, 0);

        $this->assertSame(5, app(NationLandAreaCalculator::class)->forNation($nation));
        $this->actingAs($owner)->getJson('/api/v1/me/nation')
            ->assertOk()->assertJsonPath('data.owned_land_cells', 5);
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()->assertJsonPath('data.0.owned_land_cells', 5);
        $this->getJson("/api/v1/public/nations/{$nation->id}")
            ->assertOk()->assertJsonPath('data.owned_land_cells', 5);
    }

    public function test_exact_100_and_101_land_cell_eligibility_uses_the_shared_area_calculator(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation();
        $this->resetSurface($space);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->get();
        foreach ($cells->take(100) as $cell) {
            $this->setCell($cell, 'plain', null, $nation->id, 0);
        }

        $this->assertSame(100, app(NationLandAreaCalculator::class)->forNation($nation));
        [$safeContext] = $this->context($world, $ruleset, hash('sha256', 'exactly-100'));
        $this->assertSame(0, app(DisasterTurnService::class)->executeGlobal($safeContext)['land_subsidence_nations']);

        $this->setCell($cells[100], 'mountain', 'mine', $nation->id, 0);
        $this->assertSame(101, app(NationLandAreaCalculator::class)->forNation($nation));
        [$eligibleContext, $run] = $this->context($world, $ruleset, hash('sha256', 'exactly-101'));
        $this->assertSame(1, app(DisasterTurnService::class)->executeGlobal($eligibleContext)['land_subsidence_nations']);
        $this->assertSame(101, $this->event($run, 'land_subsidence.triggered')['owned_land_cells_before']);
    }

    #[DataProvider('dormantStateProvider')]
    public function test_dormant_nations_are_excluded_and_keep_their_map_and_capital_frozen(string $state): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation();
        $this->resetSurface($space);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->limit(101)->get();
        foreach ($cells as $cell) {
            $this->setCell($cell, 'plain', null, $nation->id, 0);
        }
        $capital = $cells[0];
        $this->setCell($capital, 'plain', 'capital', $nation->id, 1_000);
        $nation->capital()->firstOrFail()->update([
            'map_cell_id' => $capital->id, 'x' => $capital->x, 'y' => $capital->y,
        ]);
        $nation->update(['state' => $state]);
        $before = $this->surfaceSnapshot($space);
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', "land-subsidence-{$state}"));

        $metrics = app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(0, $metrics['land_subsidence_nations']);
        $this->assertSame($before, $this->surfaceSnapshot($space));
        $this->assertFalse(DB::table('audit_events')
            ->where('event_type', 'land_subsidence.triggered')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->exists());
    }

    /** @return array<string, array{string}> */
    public static function dormantStateProvider(): array
    {
        return [
            'frozen' => ['dormant_frozen'],
            'contestable' => ['dormant_contestable'],
        ];
    }

    public function test_snapshot_effects_preserve_mountains_capital_foreign_cells_and_player_secrecy(): void
    {
        [$world, $nation, $ruleset, $space, $owner] = $this->worldAndNation();
        $rival = app(NationCreationService::class)->create(User::factory()->create(), $world, '他国');
        $this->resetSurface($space);

        for ($y = 8; $y <= 18; $y++) {
            for ($x = 8; $x <= 18; $x++) {
                $this->setCell($this->cellAt($space, $x, $y), 'plain', null, $nation->id, 0);
            }
        }
        $coastalShallow = $this->cellAt($space, 8, 13);
        $ownedShallow = $this->cellAt($space, 8, 14);
        $coastalLand = $this->cellAt($space, 9, 13);
        $innerLand = $this->cellAt($space, 10, 13);
        $mountain = $this->cellAt($space, 13, 8);
        $foreignShallow = $this->cellAt($space, 8, 12);
        $unrelatedShallow = $this->cellAt($space, 25, 25);
        $neutralLand = $this->cellAt($space, 26, 26);
        $oil = $this->cellAt($space, 24, 24);
        $edge = $this->cellAt($space, 0, 0);
        $capital = $this->cellAt($space, 18, 13);

        $this->setCell($coastalShallow, 'shallow', null, null, 0);
        $this->setCell($ownedShallow, 'shallow', null, $nation->id, 0);
        $this->setCell($mountain, 'mountain', 'mine', $nation->id, 0);
        $this->setCell($foreignShallow, 'shallow', null, $rival->id, 0);
        $this->setCell($unrelatedShallow, 'shallow', null, null, 0);
        $this->setCell($neutralLand, 'plain', 'farm', null, 0);
        $this->setCell($oil, 'sea', 'seabed_oil_field', $nation->id, 0);
        $this->setCell($edge, 'plain', null, $nation->id, 0);
        $this->setCell($capital, 'plain', 'capital', $nation->id, 1_000);
        $capitalRecord = $nation->capital()->firstOrFail();
        $capitalRecord->update(['map_cell_id' => $capital->id, 'x' => $capital->x, 'y' => $capital->y]);

        $areaBefore = app(NationLandAreaCalculator::class)->forNation($nation);
        $this->assertGreaterThan(100, $areaBefore);
        $mountainBefore = $mountain->fresh()->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population',
            'terrain_quantity', 'facility_scale', 'facility_experience', 'facility_operational_state', 'version',
        ]);
        $foreignBefore = $foreignShallow->fresh()->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version',
        ]);
        $oilBefore = $oil->fresh()->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version',
        ]);
        $chunkVersionsBefore = DB::table('map_chunks')->where('map_space_id', $space->id)->pluck('version', 'id');
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'snapshot-effects'));

        $metrics = app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(1, $metrics['land_subsidence_nations']);
        $this->assertSame('sea', $coastalShallow->fresh()->terrain()->value('key'));
        $this->assertNull($coastalShallow->fresh()->owner_nation_id);
        $this->assertSame('sea', $ownedShallow->fresh()->terrain()->value('key'));
        $this->assertNull($ownedShallow->fresh()->owner_nation_id);
        $this->assertSame('shallow', $coastalLand->fresh()->terrain()->value('key'));
        $this->assertNull($coastalLand->fresh()->owner_nation_id);
        $this->assertSame('plain', $innerLand->fresh()->terrain()->value('key'), 'new shallow must not cascade inward');
        $this->assertSame($mountainBefore, $mountain->fresh()->only(array_keys($mountainBefore)));
        $this->assertSame($foreignBefore, $foreignShallow->fresh()->only(array_keys($foreignBefore)));
        $this->assertSame('shallow', $unrelatedShallow->fresh()->terrain()->value('key'));
        $this->assertNull($neutralLand->fresh()->owner_nation_id);
        $this->assertSame('plain', $neutralLand->fresh()->terrain()->value('key'));
        $this->assertSame($oilBefore, $oil->fresh()->only(array_keys($oilBefore)));
        $this->assertSame('shallow', $edge->fresh()->terrain()->value('key'), 'out-of-bounds must count as water');

        $capital = $capital->fresh(['terrain', 'facility']);
        $capitalRecord->refresh();
        $this->assertSame(700, $capital->population);
        $this->assertSame('capital', $capital->facility?->key);
        $this->assertSame('plain', $capital->terrain->key);
        $this->assertSame($nation->id, $capital->owner_nation_id);
        $this->assertSame([$capital->id, $capital->x, $capital->y], [
            $capitalRecord->map_cell_id, $capitalRecord->x, $capitalRecord->y,
        ]);

        $aggregate = app(CompleteTurnEngine::class)->execute('aggregate_nations', $context);
        $changedChunkIds = $context->state->changedMapChunkIds();
        $chunkVersionsAfter = DB::table('map_chunks')->where('map_space_id', $space->id)->pluck('version', 'id');
        foreach ($chunkVersionsBefore as $chunkId => $version) {
            $expected = (int) $version + (in_array((int) $chunkId, $changedChunkIds, true) ? 1 : 0);
            $this->assertSame($expected, (int) $chunkVersionsAfter[$chunkId]);
        }
        $areaAfter = app(NationLandAreaCalculator::class)->forNation($nation);
        $this->assertLessThan($areaBefore, $areaAfter);
        $this->assertSame($areaAfter, $aggregate->metrics['owned_land_cells']);

        $event = $this->event($run, 'land_subsidence.triggered');
        $this->assertSame($nation->id, $event['nation_id']);
        $this->assertSame($nation->nation_number, $event['nation_number']);
        $this->assertSame($areaBefore, $event['owned_land_cells_before']);
        $this->assertSame(100, $event['effective_safe_land_cells']);
        $this->assertGreaterThan(0, $event['changed_to_sea_count']);
        $this->assertGreaterThan(0, $event['changed_to_shallow_count']);
        $this->assertGreaterThan(0, $event['protected_mountain_count']);
        $this->assertSame(700, $event['capital_damage'][0]['after_population']);

        $this->actingAs($owner)->getJson('/api/v1/me/nation')
            ->assertOk()
            ->assertJsonPath('data.owned_land_cells', $areaAfter);
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonPath('data.0.owned_land_cells', $areaAfter);
        $this->getJson("/api/v1/public/nations/{$nation->id}")
            ->assertOk()
            ->assertJsonPath('data.owned_land_cells', $areaAfter);

        $world->update(['current_turn' => 2]);
        $playerEvents = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")
            ->assertOk();
        $body = $playerEvents->getContent();
        $this->assertStringContainsString('land_subsidence.triggered', $body);
        $capitalEvent = collect($playerEvents->json('data.groups'))
            ->flatMap(static fn (array $group): array => $group['events'])
            ->firstWhere('type', 'capital.disaster_damaged');
        $this->assertIsArray($capitalEvent);
        $this->assertStringContainsString('地盤沈下により首都人口が30%減少', $capitalEvent['message']);
        foreach (['"draw"', 'snapshot_applied', 'affected_nation_ids', 'random_seed'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'land_subsidence_triggered',
                'nation_number' => $nation->nation_number,
            ]);
    }

    public function test_shared_neutral_shallow_is_changed_once_for_simultaneous_nations(): void
    {
        [$world, $first, $ruleset, $space] = $this->worldAndNation();
        $second = app(NationCreationService::class)->create(User::factory()->create(), $world, '第二沈下国');
        $this->resetSurface($space);
        $shared = $this->cellAt($space, 15, 15);
        $this->setCell($shared, 'shallow', null, null, 0);
        $neighbors = (new GridCoordinate(15, 15))->neighborsWithin(0, 31, 0, 31);
        $this->setCell($this->cellAt($space, $neighbors[0]->x, $neighbors[0]->y), 'plain', null, $first->id, 0);
        $this->setCell($this->cellAt($space, $neighbors[3]->x, $neighbors[3]->y), 'plain', null, $second->id, 0);

        $excluded = [$shared->id];
        $firstCells = MapCell::query()->where('map_space_id', $space->id)->where('x', '<', 14)
            ->whereNotIn('id', $excluded)->orderBy('id')->limit(100)->get();
        $secondCells = MapCell::query()->where('map_space_id', $space->id)->where('x', '>', 16)
            ->whereNotIn('id', $excluded)->orderBy('id')->limit(100)->get();
        foreach ($firstCells as $cell) {
            $this->setCell($cell, 'plain', null, $first->id, 0);
        }
        foreach ($secondCells as $cell) {
            $this->setCell($cell, 'plain', null, $second->id, 0);
        }
        $this->assertGreaterThan(100, app(NationLandAreaCalculator::class)->forNation($first));
        $this->assertGreaterThan(100, app(NationLandAreaCalculator::class)->forNation($second));
        $version = $shared->version;
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'simultaneous-nations'));

        $metrics = app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame(2, $metrics['land_subsidence_nations']);
        $this->assertSame('sea', $shared->fresh()->terrain()->value('key'));
        $this->assertSame($version + 1, $shared->fresh()->version);
        $events = DB::table('audit_events')->where('event_type', 'land_subsidence.triggered')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $metadata = json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
            $this->assertGreaterThanOrEqual(1, $metadata['changed_to_sea_count']);
            $this->assertTrue($metadata['snapshot_applied']);
        }
    }

    public function test_transaction_rollback_and_same_seed_retry_replay_the_same_result(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation();
        $this->resetSurface($space);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->limit(101)->get();
        foreach ($cells as $cell) {
            $this->setCell($cell, 'plain', null, $nation->id, 0);
        }
        $before = $this->surfaceSnapshot($space);
        $seed = hash('sha256', 'land-subsidence-rollback-retry');
        [$rollbackContext, $rollbackRun] = $this->context($world, $ruleset, $seed);
        $rolledBackResult = [];

        try {
            DB::transaction(function () use ($rollbackContext, $rollbackRun, $space, &$rolledBackResult): void {
                app(DisasterTurnService::class)->executeGlobal($rollbackContext);
                app(CompleteTurnEngine::class)->execute('aggregate_nations', $rollbackContext);
                $rolledBackResult = [
                    'surface' => $this->surfaceSnapshot($space),
                    'event' => $this->event($rollbackRun, 'land_subsidence.triggered'),
                ];
                unset($rolledBackResult['event']['turn_run_id']);
                throw new RuntimeException('rollback probe');
            });
            $this->fail('Expected rollback probe failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback probe', $exception->getMessage());
        }
        $this->assertSame($before, $this->surfaceSnapshot($space));

        [$retryContext, $retryRun] = $this->context($world, $ruleset, $seed);
        app(DisasterTurnService::class)->executeGlobal($retryContext);
        app(CompleteTurnEngine::class)->execute('aggregate_nations', $retryContext);
        $retryEvent = $this->event($retryRun, 'land_subsidence.triggered');
        unset($retryEvent['turn_run_id']);
        $this->assertSame($rolledBackResult['surface'], $this->surfaceSnapshot($space));
        $this->assertSame($rolledBackResult['event'], $retryEvent);
    }

    public function test_capital_damage_clamps_to_the_existing_minimum_population(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation();
        $this->resetSurface($space);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->limit(101)->get();
        foreach ($cells as $cell) {
            $this->setCell($cell, 'plain', null, $nation->id, 0);
        }
        $capital = $cells[0];
        $this->setCell($capital, 'plain', 'capital', $nation->id, 50);
        $nation->capital()->firstOrFail()->update([
            'map_cell_id' => $capital->id, 'x' => $capital->x, 'y' => $capital->y,
        ]);
        [$context] = $this->context($world, $ruleset, hash('sha256', 'capital-minimum'));

        app(DisasterTurnService::class)->executeGlobal($context);

        $capital = $capital->fresh(['terrain', 'facility']);
        $this->assertSame(100, $capital->population);
        $this->assertSame('capital', $capital->facility?->key);
        $this->assertSame('plain', $capital->terrain->key);
        $this->assertSame($nation->id, $capital->owner_nation_id);
    }

    public function test_production_60_by_60_world_runs_the_integrated_land_subsidence_phase(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation(WorldGenerationProfile::Production);
        $this->assertSame([0, 59, 0, 59], [$space->min_x, $space->max_x, $space->min_y, $space->max_y]);
        $this->resetSurface($space);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->limit(101)->get();
        foreach ($cells as $cell) {
            $this->setCell($cell, 'plain', null, $nation->id, 0);
        }
        [$context] = $this->context($world, $ruleset, hash('sha256', 'production-60x60'));

        $global = app(CompleteTurnEngine::class)->execute('global_disasters', $context);
        $aggregate = app(CompleteTurnEngine::class)->execute('aggregate_nations', $context);

        $this->assertSame(1, $global->metrics['land_subsidence_nations']);
        $this->assertLessThan(101, $aggregate->metrics['owned_land_cells']);
        $this->assertSame(3_600, MapCell::query()->where('map_space_id', $space->id)->count());
    }

    /** @return array{World, Nation, RulesetVersion, MapSpace, User} */
    private function worldAndNation(WorldGenerationProfile $profile = WorldGenerationProfile::Debug32x32): array
    {
        $world = app(OceanWorldGenerator::class)->initialize($profile);
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '沈下試験国');
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        foreach (['earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption'] as $key) {
            $settings['turn_processing']['disasters'][$key]['probability'] = ['numerator' => 0, 'denominator' => 1];
        }
        $settings['turn_processing']['disasters']['land_subsidence']['probability'] = ['numerator' => 1, 'denominator' => 1];
        $ruleset->settings = $settings;
        $ruleset->save();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();

        return [$world, $nation, $ruleset->fresh(), $space, $owner];
    }

    /** @return array{TurnContext, TurnRun} */
    private function context(World $world, RulesetVersion $ruleset, string $seed): array
    {
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
        $nationIds = Nation::query()->where('world_id', $world->id)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->all();
        $state = new TurnState;
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);
        $state->setSurfaceCellIds([]);

        return [
            new TurnContext($world, $run, $ruleset, 2, $seed, new TurnRandomStreamFactory($seed), $state),
            $run,
        ];
    }

    private function resetSurface(MapSpace $space): void
    {
        $sea = TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
        MapCell::query()->where('map_space_id', $space->id)->update([
            'terrain_definition_id' => $sea->id,
            'facility_definition_id' => null,
            'owner_nation_id' => null,
            'population' => 0,
            'terrain_quantity' => null,
            'facility_scale' => null,
            'facility_experience' => null,
            'facility_operational_state' => null,
        ]);
    }

    private function setCell(
        MapCell $cell,
        string $terrainKey,
        ?string $facilityKey,
        ?int $ownerNationId,
        int $population,
    ): void {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        if ($facilityKey !== null) {
            $states->setFacility($cell, FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail());
        }
        $cell->owner_nation_id = $ownerNationId;
        $cell->population = $population;
        $cell->save();
    }

    private function cellAt(MapSpace $space, int $x, int $y): MapCell
    {
        return MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $x)->where('y', $y)->with(['terrain', 'facility'])->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function event(TurnRun $run, string $eventType): array
    {
        $metadata = DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->orderBy('id')->value('metadata');
        $this->assertNotNull($metadata, "Missing {$eventType} event.");
        $decoded = is_array($metadata) ? $metadata : json_decode((string) $metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<array<string, int|null>> */
    private function surfaceSnapshot(MapSpace $space): array
    {
        return MapCell::query()->where('map_space_id', $space->id)->orderBy('id')
            ->get(['id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version'])
            ->map(static fn (MapCell $cell): array => [
                'id' => $cell->id,
                'terrain_definition_id' => $cell->terrain_definition_id,
                'facility_definition_id' => $cell->facility_definition_id,
                'owner_nation_id' => $cell->owner_nation_id,
                'population' => $cell->population,
                'version' => $cell->version,
            ])->all();
    }
}
