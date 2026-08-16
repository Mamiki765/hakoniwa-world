<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\SecretaryTurnService;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\SecretarySkill;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryTurnIntegrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_attempt_start_batch_load_is_fixed_in_memory_and_new_level_begins_next_attempt(): void
    {
        $world = $this->lightweightWorld();
        $nations = [];
        foreach (['第一snapshot国', '第二snapshot国', '第三snapshot国'] as $name) {
            $nations[] = $this->nation($world, $name)[1];
        }
        $nationIds = array_map(static fn (Nation $nation): int => $nation->id, $nations);
        $context = $this->context($world, 2, hash('sha256', 'secretary batch snapshot'), $nationIds);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $this->assertSame(3, $result->metrics['secretary_snapshots']);
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "secretaries"'),
        )->count());
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "secretary_skills"'),
        )->count());

        $queries = [];
        foreach ($nationIds as $nationId) {
            $this->assertSame(
                0,
                $context->state->secretarySkillLevel($nationId, SecretarySkillCatalog::AGRICULTURAL_POLICY),
            );
            $this->assertTrue($context->state->consumeFinalDefenseInterception($nationId));
            $this->assertFalse($context->state->consumeFinalDefenseInterception($nationId));
        }
        $this->assertSame([], $queries, 'TurnState Secretary reads must not issue per-Nation queries.');

        $firstNationId = $nations[0]->id;
        $context->state->awardSecretaryExperience(
            $firstNationId,
            SecretarySkillCatalog::AGRICULTURAL_POLICY,
        );
        app(SecretaryTurnService::class)->flushExperience($context);
        $this->assertSame(
            0,
            $context->state->secretarySkillLevel($firstNationId, SecretarySkillCatalog::AGRICULTURAL_POLICY),
            'A flush must not mutate the attempt-start snapshot.',
        );
        $this->assertSame(1, $this->skill($nations[0], SecretarySkillCatalog::AGRICULTURAL_POLICY)->level);

        $nextAttempt = $this->context($world, 3, hash('sha256', 'secretary next snapshot'), $nationIds);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($nextAttempt, $nationIds);
        $this->assertSame(
            1,
            $nextAttempt->state->secretarySkillLevel(
                $firstNationId,
                SecretarySkillCatalog::AGRICULTURAL_POLICY,
            ),
        );
    }

    public function test_all_three_production_skills_apply_their_integer_multiplier_to_the_matching_output(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '生産秘書国');
        $this->facilityCell($nation, 'farm');
        $this->facilityCell($nation, 'factory');
        $this->facilityCell($nation, 'mine');
        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        $nation->capital()->firstOrFail()->cell()->update(['population' => 3_000]);
        $levels = [
            SecretarySkillCatalog::AGRICULTURAL_POLICY => 1_000,
            SecretarySkillCatalog::SPECIALTY_DEVELOPMENT => 500,
            SecretarySkillCatalog::GOLD_VEIN_SURVEY => 250,
        ];
        foreach ($levels as $skillKey => $level) {
            $this->skill($nation, $skillKey)->update(['level' => $level]);
        }
        $context = $this->context($world, 2, hash('sha256', 'secretary production'), [$nation->id]);
        $engine = app(CompleteTurnEngine::class);
        $engine->execute('prepare_turn', $context);

        $engine->execute('nation_economy', $context);

        $workforce = $context->ruleset->settings['turn_processing']['workforce'];
        $food = $this->event($context->run, 'resource.food_produced');
        $industrial = $this->event($context->run, 'resource.industrial_produced');
        $minerals = $this->event($context->run, 'resource.mineral_produced');
        $this->assertSame(
            intdiv($food['workers'] * $workforce['farm_output_per_worker'] * 2_000, 1_000),
            $food['requested_tons'],
        );
        $this->assertSame(
            intdiv($industrial['workers'] * $workforce['factory_output_per_worker'] * 1_500, 1_000),
            $industrial['produced_units'],
        );
        $this->assertSame(
            intdiv($minerals['workers'] * $workforce['mine_output_per_worker'] * 1_250, 1_000),
            $minerals['produced_units'],
        );
    }

    public function test_development_xp_is_one_per_successful_execution_and_zero_for_queue_cancel_or_failure(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '整備経験国');
        $nation->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $plain = $this->ownedTerrainWithoutFacility($nation, 'plain');
        $service = app(CommandQueueService::class);
        $farm = $this->queue($service, $user, $nation, $space, 'build_farm', $plain, 3);

        $this->assertSame(0, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->experience);
        $context = $this->context($world, 2, hash('sha256', 'farm quantity execution'), [$nation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);
        app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame(2, $farm->fresh()->quantity);
        $this->assertSame('queued', $farm->fresh()->status);
        $this->assertSame([
            $nation->id => [SecretarySkillCatalog::AGRICULTURAL_POLICY => 1],
        ], $context->state->pendingSecretaryExperience());
        app(SecretaryTurnService::class)->flushExperience($context);
        $this->assertSame(1, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->level);
        $this->assertSame(0, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->experience);

        $queueVersion = (int) $nation->commandQueue()->value('version');
        $service->cancel($user, $nation, $farm->fresh(), $queueVersion);
        $this->assertSame('cancelled', $farm->fresh()->status);
        $this->assertSame(0, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->experience);

        $invalidMine = $this->queue(
            $service,
            $user,
            $nation,
            $space,
            'build_mine',
            $this->ownedTerrainWithoutFacility($nation, 'plain'),
        );
        $failureContext = $this->context($world, 3, hash('sha256', 'invalid mine execution'), [$nation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($failureContext, [$nation->id]);
        $failure = app(DomesticCommandExecutor::class)->execute($failureContext);

        $this->assertSame(1, $failure['failures']);
        $this->assertSame('failed', $invalidMine->fresh()->status);
        $this->assertSame([], $failureContext->state->pendingSecretaryExperience());
        $this->assertSame(0, $this->skill($nation, SecretarySkillCatalog::GOLD_VEIN_SURVEY)->experience);
    }

    public function test_failed_attempt_rolls_back_development_xp_and_retry_commits_it_once(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '整備再試行国');
        $space = $this->surfaceMapSpace($world);
        $item = $this->queue(
            app(CommandQueueService::class),
            $user,
            $nation,
            $space,
            'build_farm',
            $this->ownedTerrainWithoutFacility($nation, 'plain'),
        );
        $seed = hash('sha256', 'secretary development retry');

        try {
            DB::transaction(function () use ($world, $nation, $seed): void {
                $context = $this->context($world, 2, $seed, [$nation->id]);
                app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);
                app(DomesticCommandExecutor::class)->execute($context);
                app(SecretaryTurnService::class)->flushExperience($context);
                $this->assertSame(1, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->level);

                throw new RuntimeException('force Secretary XP rollback');
            });
            $this->fail('The forced rollback did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force Secretary XP rollback', $exception->getMessage());
        }

        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame(0, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->level);
        $retry = $this->context($world, 2, $seed, [$nation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($retry, [$nation->id]);
        app(DomesticCommandExecutor::class)->execute($retry);
        app(SecretaryTurnService::class)->flushExperience($retry);

        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(1, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->level);
        $this->assertSame(0, $this->skill($nation, SecretarySkillCatalog::AGRICULTURAL_POLICY)->experience);
    }

    /** @return array{User, Nation} */
    private function nation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name, '試験島主')];
    }

    private function facilityCell(Nation $nation, string $facilityKey): MapCell
    {
        $terrainKey = $facilityKey === 'mine' ? 'mountain' : 'plain';
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', $terrainKey))
            ->first();
        if (! $cell instanceof MapCell) {
            $cell = MapCell::query()->where('owner_nation_id', $nation->id)
                ->whereNull('facility_definition_id')->firstOrFail();
            app(MapCellStateService::class)->transitionTerrain(
                $cell,
                TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail(),
            );
        }
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
        );
        $cell->facility_scale = 1;
        $cell->save();

        return $cell->fresh(['terrain', 'facility']);
    }

    private function ownedTerrainWithoutFacility(Nation $nation, string $terrainKey): MapCell
    {
        return MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', $terrainKey))
            ->firstOrFail();
    }

    private function skill(Nation $nation, string $skillKey): SecretarySkill
    {
        $secretaryId = NationMembership::query()->where('nation_id', $nation->id)
            ->where('role', 'owner')->firstOrFail()->user()->firstOrFail()->secretary()->value('id');

        return SecretarySkill::query()->where('secretary_id', $secretaryId)
            ->where('skill_key', $skillKey)->firstOrFail();
    }

    private function queue(
        CommandQueueService $service,
        User $user,
        Nation $nation,
        MapSpace $space,
        string $key,
        MapCell $cell,
        int $quantity = 1,
    ): NationCommandQueueItem {
        $version = (int) ($nation->commandQueue()->value('version') ?? 1);

        return $service->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: $key,
            targetX: $cell->x,
            targetY: $cell->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: $version,
            quantity: $quantity,
            quantityProvided: true,
        )['item'];
    }

    /** @param list<int> $nationIds */
    private function context(World $world, int $targetTurn, string $seed, array $nationIds): TurnContext
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
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
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);

        return new TurnContext(
            $world,
            $run,
            $ruleset,
            $targetTurn,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
    }

    /** @return array<string, mixed> */
    private function event(TurnRun $run, string $eventType): array
    {
        $metadata = DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->value('metadata');
        $this->assertIsString($metadata);

        return json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
    }
}
