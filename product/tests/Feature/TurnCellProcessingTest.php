<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TurnCellProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_settlement_growth_famine_riot_and_forest_processing(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'セル処理国');
        $engine = app(CompleteTurnEngine::class);
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        [$firstCandidate, $secondCandidate] = $this->sequentialCandidates($nation, $capital);
        $forestCells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->whereNotIn('id', [$firstCandidate->id, $secondCandidate->id])
            ->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(1, $forestCells->count());

        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        MapCell::query()->whereKey($capital->id)->update(['population' => 1_000]);
        $this->plain($firstCandidate);
        $this->plain($secondCandidate);
        $settlementSeed = $this->seedForSettlementSequence(19, 19);
        [$appearanceContext, $appearanceRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id, $secondCandidate->id],
            $settlementSeed,
            [$firstCandidate->id => 0, $secondCandidate->id => 0],
        );
        $appearance = $engine->execute('process_cells', $appearanceContext);
        $this->assertSame(2, $appearance->metrics['settlements_appeared']);
        $this->assertSame(100, $firstCandidate->fresh()->population);
        $this->assertSame('village', $firstCandidate->fresh()->facility()->value('key'));
        $this->assertSame(100, $secondCandidate->fresh()->population);
        $this->assertSame('village', $secondCandidate->fresh()->facility()->value('key'));
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'settlement.appeared')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $appearanceRun->id])->count());

        $this->plain($firstCandidate);
        $this->plain($secondCandidate);
        MapCell::query()->whereKey($capital->id)->update(['population' => 1_000]);
        $failureSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::SETTLEMENT_APPEARANCE, 0, 99, 20);
        [$failureContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $failureSeed,
            [$firstCandidate->id => 0],
        );
        $failure = $engine->execute('process_cells', $failureContext);
        $this->assertSame(0, $failure->metrics['settlements_appeared']);
        $this->assertSame(0, $firstCandidate->fresh()->population);

        MapCell::query()->whereKey($capital->id)->update(['population' => 0]);
        $this->plain($secondCandidate);
        [$noNeighborContext] = $this->context(
            $world,
            $nation,
            [$secondCandidate->id],
            $this->seedForFirstDraw(TurnRandomStreamFactory::SETTLEMENT_APPEARANCE, 0, 99, 19),
            [$secondCandidate->id => 0],
        );
        $noNeighbor = $engine->execute('process_cells', $noNeighborContext);
        $this->assertSame(0, $noNeighbor->metrics['settlements_appeared']);
        $this->assertSame(0, $secondCandidate->fresh()->population);

        $growthSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 900, 100);
        $this->settlement($firstCandidate, 'village', 2_900);
        [$townContext, $townRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $growthSeed,
            [$firstCandidate->id => 24],
        );
        $town = $engine->execute('process_cells', $townContext);
        $this->assertSame(100, $town->metrics['population_increased']);
        $this->assertSame(1, $town->metrics['stage_transitions']);
        $this->assertSame(3_000, $firstCandidate->fresh()->population);
        $this->assertSame('town', $firstCandidate->fresh()->facility()->value('key'));
        $this->assertSame('town', $this->event($townRun, 'settlement.stage_transitioned')['to_facility_key']);
        $presentedCells = $this->actingAs($user)
            ->getJson("/api/v1/map-spaces/{$space->id}/chunks/{$firstCandidate->chunk_x}/{$firstCandidate->chunk_y}")
            ->assertOk()->json('data.cells');
        $this->assertIsArray($presentedCells);
        /** @var list<array<string, mixed>> $presentedCells */
        $presentedTown = null;
        foreach ($presentedCells as $presentedCell) {
            if ($presentedCell['x'] === $firstCandidate->x && $presentedCell['y'] === $firstCandidate->y) {
                $presentedTown = $presentedCell;
                break;
            }
        }
        $this->assertIsArray($presentedTown);
        $this->assertSame('town', $presentedTown['facility']);
        $this->assertIsArray($presentedTown['details']);
        /** @var list<array<string, mixed>> $presentedDetails */
        $presentedDetails = $presentedTown['details'];
        $populationDetail = null;
        foreach ($presentedDetails as $presentedDetail) {
            if ($presentedDetail['key'] === 'population') {
                $populationDetail = $presentedDetail;
                break;
            }
        }
        $this->assertIsArray($populationDetail);
        $this->assertSame(3_000, $populationDetail['value']);

        $this->settlement($firstCandidate, 'town', 9_900);
        [$cityContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $growthSeed,
            [$firstCandidate->id => 24],
        );
        $engine->execute('process_cells', $cityContext);
        $this->assertSame(10_000, $firstCandidate->fresh()->population);
        $this->assertSame('city', $firstCandidate->fresh()->facility()->value('key'));

        $this->settlement($firstCandidate, 'village', 1_999);
        [$maximumContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            str_repeat('d', 64),
            [$firstCandidate->id => 0],
        );
        $engine->execute('process_cells', $maximumContext);
        $this->assertSame(2_000, $firstCandidate->fresh()->population);
        $this->assertSame('village', $firstCandidate->fresh()->facility()->value('key'));

        $this->settlement($capital, 'capital', 1_000);
        $capitalGrowthSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::POPULATION_GROWTH, 100, 300, 100);
        [$capitalContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $capitalGrowthSeed,
            [$capital->id => 0],
        );
        $engine->execute('process_cells', $capitalContext);
        $this->assertSame(1_100, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));

        $lossSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::FAMINE_POPULATION_LOSS, 100, 3_000, 100);
        $this->settlement($firstCandidate, 'city', 10_050);
        [$famineStageContext, $famineStageRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $lossSeed,
            [$firstCandidate->id => 0],
            true,
        );
        $famineStage = $engine->execute('process_cells', $famineStageContext);
        $this->assertSame(100, $famineStage->metrics['population_decreased']);
        $this->assertSame(1, $famineStage->metrics['stage_transitions']);
        $this->assertSame(9_950, $firstCandidate->fresh()->population);
        $this->assertSame('town', $firstCandidate->fresh()->facility()->value('key'));
        $this->assertSame('town', $this->event($famineStageRun, 'settlement.stage_transitioned')['to_facility_key']);

        $this->settlement($firstCandidate, 'village', 100);
        [$famineContext, $famineRun] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $lossSeed,
            [$firstCandidate->id => 0],
            true,
        );
        $famine = $engine->execute('process_cells', $famineContext);
        $this->assertSame(100, $famine->metrics['population_decreased']);
        $this->assertSame(0, $firstCandidate->fresh()->population);
        $this->assertNull($firstCandidate->fresh()->facility_definition_id);
        $this->assertSame('plain', $firstCandidate->fresh()->terrain()->value('key'));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'famine.applied')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $famineRun->id])->count());

        $this->settlement($capital, 'capital', 100);
        [$capitalFamineContext] = $this->context(
            $world,
            $nation,
            [$capital->id],
            $lossSeed,
            [$capital->id => 0],
            true,
        );
        $engine->execute('process_cells', $capitalFamineContext);
        $this->assertSame(0, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));

        $riotCells = [$firstCandidate, $secondCandidate, $this->ownedEmptyCell($nation, [$capital->id, $firstCandidate->id, $secondCandidate->id])];
        foreach (['farm', 'factory', 'missile_base'] as $index => $facilityKey) {
            $this->facility($riotCells[$index], $facilityKey, 'plain');
        }
        $riotSeed = $this->seedForRepeatedDraw(TurnRandomStreamFactory::FACILITY_RIOT, 0, 3, [0, 0, 0]);
        [$riotContext, $riotRun] = $this->context(
            $world,
            $nation,
            array_map(static fn (MapCell $cell): int => $cell->id, $riotCells),
            $riotSeed,
            [],
            true,
        );
        $riot = $engine->execute('process_cells', $riotContext);
        $this->assertSame(3, $riot->metrics['riots']);
        foreach ($riotCells as $cell) {
            $this->assertSame('wasteland', $cell->fresh()->terrain()->value('key'));
            $this->assertNull($cell->fresh()->facility_definition_id);
        }
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'facility.riot')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $riotRun->id])->count());

        $this->facility($firstCandidate, 'farm', 'plain');
        $riotFailureSeed = $this->seedForFirstDraw(TurnRandomStreamFactory::FACILITY_RIOT, 0, 3, 1);
        [$riotFailureContext] = $this->context(
            $world,
            $nation,
            [$firstCandidate->id],
            $riotFailureSeed,
            [],
            true,
        );
        $engine->execute('process_cells', $riotFailureContext);
        $this->assertSame('farm', $firstCandidate->fresh()->facility()->value('key'));

        $forest = $forestCells->firstOrFail();
        $this->forest($forest, 500);
        $forest = $forest->fresh(['terrain', 'facility']);
        $forestBefore = $forest->terrain_quantity;
        [$forestContext, $forestRun] = $this->context($world, $nation, [$forest->id], str_repeat('e', 64));
        $forestGrowth = $engine->execute('process_cells', $forestContext);
        $this->assertSame(1, $forestGrowth->metrics['forest_growth']);
        $this->assertSame($forestBefore + 100, $forest->fresh()->terrain_quantity);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'forest.grown')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $forestRun->id])->count());

        $maximumTrees = $world->rulesetVersion()->firstOrFail()->settings['terrain_quantities']['forest']['maximum_quantity'];
        $forest->fresh()->update(['terrain_quantity' => $maximumTrees - 50]);
        [$forestMaximumContext] = $this->context($world, $nation, [$forest->id], str_repeat('f', 64));
        $engine->execute('process_cells', $forestMaximumContext);
        $this->assertSame($maximumTrees, $forest->fresh()->terrain_quantity);

        $landClearTarget = $forestCells->firstWhere('id', '!=', $forest->id) ?? $this->ownedEmptyCell(
            $nation,
            [$forest->id, $capital->id, $firstCandidate->id, $secondCandidate->id],
        );
        $this->forest($landClearTarget, 500);
        $landClearItem = $this->queueLandClear($user, $nation, $space, $landClearTarget);
        [$commandContext, $commandRun] = $this->context(
            $world,
            $nation,
            [$landClearTarget->id],
            str_repeat('1', 64),
        );
        app(DomesticCommandExecutor::class)->execute($commandContext);
        $afterCommand = $engine->execute('process_cells', $commandContext);
        $this->assertSame('completed', $landClearItem->fresh()->status);
        $this->assertSame('plain', $landClearTarget->fresh()->terrain()->value('key'));
        $this->assertSame(0, $afterCommand->metrics['forest_growth']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'forest.grown')
            ->where('subject_id', $landClearTarget->id)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $commandRun->id])->count());
    }

    /** @return array{MapCell, MapCell} */
    private function sequentialCandidates(Nation $nation, MapCell $capital): array
    {
        $cells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->where('id', '!=', $capital->id)->orderBy('id')->get();
        $capitalCoordinate = new GridCoordinate($capital->x, $capital->y);
        foreach ($cells as $first) {
            $firstCoordinate = new GridCoordinate($first->x, $first->y);
            if ($capitalCoordinate->distanceTo($firstCoordinate) !== 1) {
                continue;
            }
            foreach ($cells as $second) {
                $secondCoordinate = new GridCoordinate($second->x, $second->y);
                if ($capitalCoordinate->distanceTo($secondCoordinate) === 2
                    && $firstCoordinate->distanceTo($secondCoordinate) === 1) {
                    return [$first, $second];
                }
            }
        }

        $this->fail('Initial island does not contain a two-step settlement propagation path.');
    }

    private function plain(MapCell $cell): void
    {
        $this->mutateCell($cell, 'plain', null, 0);
    }

    private function forest(MapCell $cell, int $quantity): void
    {
        $this->mutateCell($cell, 'forest', null, 0);
        $cell->fresh()->update(['terrain_quantity' => $quantity]);
    }

    private function settlement(MapCell $cell, string $facilityKey, int $population): void
    {
        $this->mutateCell($cell, 'plain', $facilityKey, $population);
    }

    private function facility(MapCell $cell, string $facilityKey, string $terrainKey): void
    {
        $this->mutateCell($cell, $terrainKey, $facilityKey, 0);
    }

    private function mutateCell(MapCell $cell, string $terrainKey, ?string $facilityKey, int $population): void
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        if ($facilityKey !== null) {
            $states->setFacility($cell, FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail());
        }
        $cell->population = $population;
        $cell->save();
    }

    /** @param list<int> $excludedIds */
    private function ownedEmptyCell(Nation $nation, array $excludedIds): MapCell
    {
        return MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $excludedIds)->whereNull('facility_definition_id')->firstOrFail();
    }

    private function queueLandClear(
        User $user,
        Nation $nation,
        MapSpace $space,
        MapCell $target,
    ): NationCommandQueueItem {
        $queue = NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $space->id, 'version' => 1],
        );
        $definition = CommandDefinition::query()->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
            ->where('key', 'land_clear')->firstOrFail();
        $membership = NationMembership::query()->where('user_id', $user->id)->where('nation_id', $nation->id)->firstOrFail();

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $definition->id,
            'queue_position' => 1,
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => $membership->id,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => [],
        ])->load('definition');
    }

    /**
     * @param  list<int>  $cellIds
     * @param  array<int, int>  $seaEdges
     * @return array{TurnContext, TurnRun}
     */
    private function context(
        World $world,
        Nation $nation,
        array $cellIds,
        string $seed,
        array $seaEdges = [],
        bool $famine = false,
        ?RulesetVersion $ruleset = null,
    ): array {
        $ruleset ??= $world->rulesetVersion()->firstOrFail();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 1,
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
        $state = new TurnState;
        $state->setStableNationIds([$nation->id]);
        $state->setDevelopmentNationIds([$nation->id]);
        $state->setSurfaceCellIds($cellIds);
        $state->setSeaEdgeByCellId($seaEdges);
        if ($famine) {
            $state->markFamine($nation->id);
        }

        return [
            new TurnContext($world, $run, $ruleset, 1, $seed, new TurnRandomStreamFactory($seed), $state),
            $run,
        ];
    }

    private function seedForSettlementSequence(int $firstExpected, int $secondExpected): string
    {
        $label = TurnRandomStreamFactory::SETTLEMENT_APPEARANCE;
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "settlement-sequence:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream($label);
            if ($stream->integer(0, 99) === $firstExpected && $stream->integer(0, 99) === $secondExpected) {
                return $seed;
            }
        }

        $this->fail('Unable to find the settlement propagation draw sequence.');
    }

    /** @param list<int> $expected */
    private function seedForRepeatedDraw(string $label, int $minimum, int $maximum, array $expected): string
    {
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "{$label}:sequence:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream($label);
            $actual = [];
            foreach ($expected as $_draw) {
                $actual[] = $stream->integer($minimum, $maximum);
            }
            if ($actual === $expected) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic sequence for {$label}.");
    }

    private function seedForFirstDraw(string $label, int $minimum, int $maximum, int $expected): string
    {
        return $this->seedForRepeatedDraw($label, $minimum, $maximum, [$expected]);
    }

    /** @return array<string, mixed> */
    private function event(TurnRun $run, string $eventType): array
    {
        $metadata = DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('metadata');

        return json_decode((string) $metadata, true, 512, JSON_THROW_ON_ERROR);
    }
}
