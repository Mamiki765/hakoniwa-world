<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\SecretaryOldBowService;
use App\Application\SecretaryTurnService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\SecretaryItemInstance;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesHistoricalRulesetDatabaseFixtures;
use Tests\TestCase;

final class SecretaryItemEffectsTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesHistoricalRulesetDatabaseFixtures;

    public function test_v10_prepare_adds_no_item_query_snapshot_metric_or_effect(): void
    {
        $world = $this->lightweightWorld();
        $this->switchToV10Ruleset($world);
        [, $nation] = $this->nation($world, 'v10装備互換国');
        $context = $this->context($world, hash('sha256', 'v10 item free'), [$nation->id]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $this->assertFalse($context->state->hasSecretaryItemEffectSnapshot($nation->id));
        $this->assertArrayNotHasKey('secretary_item_effect_snapshots', $result->metrics);
        $this->assertSame(0, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'secretary_item_instances'),
        )->count());
        $label = TurnRandomStreamFactory::secretaryOldBow($nation->id, 'trigger', 1);
        $expectedFirstDraw = (new TurnRandomStreamFactory($context->randomSeed))->stream($label)->integer(0, 9_999);
        $this->assertSame([], app(SecretaryOldBowService::class)->execute(
            $context,
            $this->surfaceMapSpace($world),
            true,
        ));
        $this->assertSame($expectedFirstDraw, $context->random->stream($label)->integer(0, 9_999));

        $context->run->update([
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_FAILED,
            'failure_context' => ['same_seed_retry' => true],
        ]);
        $retry = $this->retryContext($context);
        $retryResult = app(CompleteTurnEngine::class)->execute('prepare_turn', $retry);
        $this->assertArrayNotHasKey('secretary_item_effect_snapshots', $retryResult->metrics);
        $this->assertSame([], app(SecretaryOldBowService::class)->execute(
            $retry,
            $this->surfaceMapSpace($world),
            true,
        ));
        $this->assertSame($expectedFirstDraw, $retry->random->stream($label)->integer(0, 9_999));
    }

    public function test_v11_shaped_prepare_batch_loads_equipped_items_once_and_keeps_an_immutable_stable_snapshot(): void
    {
        $world = $this->lightweightWorld();
        [$firstUser, $firstNation] = $this->nation($world, '第一装備snapshot国');
        [, $secondNation] = $this->nation($world, '第二装備snapshot国');
        $ring = $this->ring($firstUser, 3, 2, 'snapshot-ring-2');
        $this->ring($firstUser, 4, 3, 'snapshot-ring-3');
        $this->ring($firstUser, 5, 4, 'snapshot-ring-4');
        $this->ring($firstUser, 6, 5, 'snapshot-ring-5');
        $unequipped = null;
        foreach (range(1, 45) as $number) {
            $created = $this->ring(
                $firstUser,
                ($number % 10) + 1,
                null,
                "snapshot-warehouse-ring-{$number}",
            );
            $unequipped ??= $created;
        }
        $surface = $this->surfaceMapSpace($world);
        foreach (range(1, 3) as $number) {
            $extra = $surface->replicate();
            $extra->key = "snapshot-space-{$number}";
            $extra->name = "snapshot space {$number}";
            $extra->save();
        }
        $this->assertSame(50, $firstUser->secretary->itemInstances()->count());
        $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $seed = hash('sha256', 'v11 item snapshot');
        $context = $this->context($world, $seed, [$firstNation->id, $secondNation->id]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $this->assertSame(2, $result->metrics['secretary_item_effect_snapshots']);
        $this->assertSame(6, $result->metrics['secretary_item_effect_items']);
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'secretary_item_instances'),
        )->count());
        $snapshot = $context->state->secretaryItemEffectSnapshot($firstNation->id);
        $this->assertSame(1, $snapshot['equipment_version']);
        $this->assertSame(
            [
                SecretaryItemCatalog::OLD_BOW,
                SecretaryItemCatalog::RING,
                SecretaryItemCatalog::RING,
                SecretaryItemCatalog::RING,
                SecretaryItemCatalog::RING,
            ],
            array_column($snapshot['items'], 'item_key'),
        );
        $this->assertSame([1, 2, 3, 4, 5], array_column($snapshot['items'], 'equipped_slot'));
        $this->assertNotContains($unequipped->id, array_column($snapshot['items'], 'item_instance_id'));
        $skillSnapshot = $context->state->secretarySnapshot($firstNation->id);

        $retry = $this->retryContext($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots(
            $retry,
            [$firstNation->id, $secondNation->id],
        );
        $this->assertSame($snapshot, $retry->state->secretaryItemEffectSnapshot($firstNation->id));
        $this->assertSame($skillSnapshot, $retry->state->secretarySnapshot($firstNation->id));

        $ring->update(['equipped_slot' => null, 'level' => 4]);
        $this->assertSame($snapshot, $context->state->secretaryItemEffectSnapshot($firstNation->id));
    }

    public function test_prepare_fails_closed_for_unknown_or_out_of_range_equipped_items(): void
    {
        $world = $this->lightweightWorld();
        $fixtures = [];
        foreach (['unknown_item', 'ring_level_11'] as $case) {
            [$user, $nation] = $this->nation($world, "不正装備{$case}国");
            $user->secretary->itemInstances()->create([
                'item_key' => $case === 'unknown_item' ? 'unknown_item' : SecretaryItemCatalog::RING,
                'level' => $case === 'unknown_item' ? 1 : 11,
                'equipped_slot' => 2,
                'grant_key' => "test:invalid:{$case}",
                'obtained_at' => now(),
            ]);
            $fixtures[$case] = $nation;
        }
        $this->switchToItemRuleset($world);
        $world = $world->fresh();

        foreach ($fixtures as $case => $nation) {
            $context = $this->context(
                $world,
                hash('sha256', "invalid equipped {$case}"),
                [$nation->id],
            );

            try {
                app(CompleteTurnEngine::class)->execute('prepare_turn', $context);
                $this->fail("Expected {$case} to fail Secretary Item snapshot preparation.");
            } catch (\DomainException $exception) {
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'Unknown Secretary item')
                    || str_contains($exception->getMessage(), 'level outside the global catalog'),
                );
            }
            $this->assertSame(0, $context->state->secretaryItemEffectSnapshotCount());
        }
    }

    public function test_ring_bonus_applies_after_base_automatic_finance_and_records_one_log(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '自動資金指輪国');
        $this->ring($user, 3, 2, 'automatic-ring-3');
        $this->ring($user, 2, 3, 'automatic-ring-2');
        $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $nation->update(['money' => 9_987]);
        $idleBefore = $nation->idle_counter;
        $context = $this->context($world, hash('sha256', 'automatic ring finance'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $metrics = app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame(9_999, $nation->fresh()->money);
        $this->assertSame(1, $metrics['automatic_finance']);
        $this->assertSame(1, $metrics['idle_counter_increments']);
        $this->assertSame($idleBefore + 1, $nation->fresh()->idle_counter);
        $this->assertSame(5, $metrics['secretary_ring_bonus_requested']);
        $this->assertSame(2, $metrics['secretary_ring_bonus_applied']);
        $this->assertSame(3, $metrics['secretary_ring_bonus_overflow']);
        $metadata = $this->event($context, 'command.automatic_finance');
        $this->assertSame('automatic', $metadata['source']);
        $this->assertSame(10, $metadata['base_requested']);
        $this->assertSame(10, $metadata['base_applied']);
        $this->assertSame(5, $metadata['ring_equipped_level_sum']);
        $this->assertSame(2, $metadata['ring_bonus_applied']);
        $this->assertSame(12, $metadata['total_applied']);
        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'command.automatic_finance')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $context->run->id])
            ->count());
    }

    public function test_ring_bonus_fully_overflows_at_capacity_without_changing_idle_semantics(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '指輪満額overflow国');
        $this->ring($user, 4, 2, 'full-overflow-ring');
        $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $nation->update(['money' => 9_999]);
        $context = $this->context($world, hash('sha256', 'ring full overflow'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $metrics = app(DomesticCommandExecutor::class)->execute($context);
        $metadata = $this->event($context, 'command.automatic_finance');

        $this->assertSame(9_999, $nation->fresh()->money);
        $this->assertSame(4, $metrics['secretary_ring_bonus_requested']);
        $this->assertSame(0, $metrics['secretary_ring_bonus_applied']);
        $this->assertSame(4, $metrics['secretary_ring_bonus_overflow']);
        $this->assertSame(0, $metadata['base_applied']);
        $this->assertSame(10, $metadata['base_overflow']);
        $this->assertSame(0, $metadata['ring_bonus_applied']);
        $this->assertSame(4, $metadata['ring_bonus_overflow']);
        $this->assertSame(1, $metrics['idle_counter_increments']);
    }

    public function test_ring_finance_rollback_and_same_seed_retry_do_not_duplicate_across_map_spaces(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '指輪retry国');
        $this->ring($user, 4, 2, 'retry-ring');
        $surface = $this->surfaceMapSpace($world);
        foreach (range(1, 3) as $number) {
            $extra = $surface->replicate();
            $extra->key = "ring-retry-space-{$number}";
            $extra->name = "ring retry space {$number}";
            $extra->save();
        }
        $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $seed = hash('sha256', 'ring rollback retry');
        $firstContext = $this->context($world, $seed, [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $firstContext);
        $rolledBack = [];

        try {
            DB::transaction(function () use ($firstContext, $nation, &$rolledBack): void {
                $metrics = app(DomesticCommandExecutor::class)->execute($firstContext);
                $metadata = $this->event($firstContext, 'command.automatic_finance');
                unset($metadata['turn_run_id']);
                $rolledBack = [
                    'money' => $nation->fresh()->money,
                    'metrics' => $metrics,
                    'metadata' => $metadata,
                ];
                throw new RuntimeException('ring rollback probe');
            });
            $this->fail('Expected Ring rollback probe failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ring rollback probe', $exception->getMessage());
        }
        $this->assertSame(100, $nation->fresh()->money);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.automatic_finance')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $firstContext->run->id])->count());

        $retry = $this->context($world, $seed, [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $retry);
        $retryMetrics = app(DomesticCommandExecutor::class)->execute($retry);
        $retryMetadata = $this->event($retry, 'command.automatic_finance');
        unset($retryMetadata['turn_run_id']);
        $this->assertSame($rolledBack['money'], $nation->fresh()->money);
        $this->assertSame($rolledBack['metrics'], $retryMetrics);
        $this->assertSame($rolledBack['metadata'], $retryMetadata);
        $this->assertSame(1, $retryMetrics['automatic_finance']);
        $this->assertSame(4, $retryMetrics['secretary_ring_bonus_applied']);
    }

    public function test_ring_bonus_applies_to_explicit_finance_without_automatic_finance(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '明示資金指輪国');
        $finance = $this->queueFinance($user, $nation, $world);
        $this->ring($user, 4, 2, 'explicit-ring');
        $ruleset = $this->switchToItemRuleset($world);
        $finance->update(['command_definition_id' => CommandDefinition::query()
            ->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'finance')
            ->value('id')]);
        $world = $world->fresh();
        $nation->update(['money' => 100]);
        $context = $this->context($world, hash('sha256', 'explicit ring finance'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $metrics = app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame(114, $nation->fresh()->money);
        $this->assertSame(1, $metrics['finance_commands']);
        $this->assertSame(0, $metrics['automatic_finance']);
        $this->assertSame(4, $metrics['secretary_ring_bonus_applied']);
        $this->assertSame('explicit', $this->event($context, 'command.finance')['source']);
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'command.automatic_finance')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $context->run->id])
            ->count());
    }

    public function test_ring_does_not_bonus_other_money_sources(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '指輪伐採非対象国');
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->with(['terrain', 'facility'])->firstOrFail();
        $queueItem = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'logging',
            targetX: $forest->x,
            targetY: $forest->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: (int) ($nation->commandQueue()->value('version') ?? 1),
            quantity: 1,
            quantityProvided: true,
        )['item'];
        $this->ring($user, 10, 2, 'logging-ring');
        $ruleset = $this->switchToItemRuleset($world);
        $queueItem->update(['command_definition_id' => CommandDefinition::query()
            ->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'logging')
            ->value('id')]);
        $world = $world->fresh();
        $this->assertSame($ruleset->id, $queueItem->definition()->value('ruleset_version_id'));
        $before = $nation->money;
        $context = $this->context($world, hash('sha256', 'ring logging exclusion'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $metrics = app(DomesticCommandExecutor::class)->execute($context);
        $logging = $this->event($context, 'command.logging_private');

        $this->assertSame(1, $metrics['successes']);
        $this->assertSame(0, $metrics['automatic_finance']);
        $this->assertSame(0, $metrics['secretary_ring_bonus_requested']);
        $this->assertSame($before + $logging['applied_money'], $nation->fresh()->money);
        $this->assertSame(0, DB::table('audit_events')->whereIn(
            'event_type',
            ['command.finance', 'command.automatic_finance'],
        )->whereRaw("metadata->>'turn_run_id' = ?", [(string) $context->run->id])->count());
    }

    public function test_no_equipped_ring_preserves_the_exact_legacy_finance_metadata_shape(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '指輪なし資金国');
        $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $context = $this->context($world, hash('sha256', 'no ring finance'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        app(DomesticCommandExecutor::class)->execute($context);

        $expectedKeys = [
            'before', 'requested', 'applied', 'overflow', 'after', 'capacity',
            'world_id', 'turn_run_id', 'target_turn',
        ];
        $actualKeys = array_keys($this->event($context, 'command.automatic_finance'));
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
    }

    public function test_old_bow_draws_only_for_safe_candidates_and_keeps_streams_isolated(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '弓抽選境界国');
        $ruleset = $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $surface = $this->surfaceMapSpace($world);
        $service = app(SecretaryOldBowService::class);

        $noTarget = $this->context($world, hash('sha256', 'old bow no target'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $noTarget);
        try {
            $service->execute($noTarget, $surface, false);
            $this->fail('Expected Old Bow to fail closed without the separated normal-monster stage.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('requires the separated normal-monster pass', $exception->getMessage());
        }
        $metrics = $service->execute($noTarget, $surface, true);
        $this->assertSame(1, $metrics['secretary_old_bow_no_safe_target']);
        $this->assertSame(0, $metrics['secretary_old_bow_attempts']);
        $this->assertStreamDrawCount($noTarget, $nation->id, 'trigger', 0, 9_999);
        $this->assertStreamDrawCount($noTarget, $nation->id, 'target', 0, 0);

        $cells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $nation->capital()->select('map_cell_id'))
            ->orderBy('id')->limit(3)->get();
        $this->assertCount(3, $cells);
        $first = $this->monster($world, $ruleset, $cells[0], 3, 'red_inora');
        $second = $this->monster($world, $ruleset, $cells[1], 3, 'red_inora');

        $miss = $this->context($world, $this->oldBowMissSeed([$nation->id]), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $miss);
        $metrics = $service->execute($miss, $surface, true);
        $this->assertSame(1, $metrics['secretary_old_bow_misses']);
        $this->assertSame(0, $metrics['secretary_old_bow_hits']);
        $this->assertStreamDrawCount($miss, $nation->id, 'trigger', 1, 9_999);
        $this->assertStreamDrawCount($miss, $nation->id, 'target', 0, 1);

        $hit = $this->context($world, $this->oldBowHitSeed($nation->id), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $hit);
        $targetIndex = (new TurnRandomStreamFactory($hit->randomSeed))->stream(
            TurnRandomStreamFactory::secretaryOldBow($nation->id, 'target', 1),
        )->integer(0, 1);
        $ordered = collect([$first, $second])->sortBy(fn (MonsterInstance $monster): int => (
            (int) $monster->occupancy()->value('id')
        ))->values();
        $metrics = $service->execute($hit, $surface, true);
        $this->assertSame(1, $metrics['secretary_old_bow_hits']);
        foreach ($ordered as $index => $monster) {
            $this->assertSame($index === $targetIndex ? 2 : 3, $monster->fresh()->current_hp);
        }
        $this->assertStreamDrawCount($hit, $nation->id, 'trigger', 1, 9_999);
        $this->assertStreamDrawCount($hit, $nation->id, 'target', 1, 1);
        $unrelatedLabel = TurnRandomStreamFactory::POPULATION_GROWTH;
        $expectedUnrelated = (new TurnRandomStreamFactory($hit->randomSeed))
            ->stream($unrelatedLabel)->integer(0, 9_999);
        $this->assertSame($expectedUnrelated, $hit->random->stream($unrelatedLabel)->integer(0, 9_999));

        $user->secretary->itemInstances()->where('item_key', SecretaryItemCatalog::OLD_BOW)
            ->update(['equipped_slot' => null]);
        $unequipped = $this->context($world, hash('sha256', 'old bow unequipped'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $unequipped);
        $metrics = $service->execute($unequipped, $surface, true);
        $this->assertSame(0, $metrics['secretary_old_bow_eligible_nations']);
        $this->assertStreamDrawCount($unequipped, $nation->id, 'trigger', 0, 9_999);

        $user->secretary->itemInstances()->where('item_key', SecretaryItemCatalog::OLD_BOW)
            ->update(['equipped_slot' => 1]);
        $first->update([
            'state' => 'removed',
            'removal_reason' => 'test_post_missile_state',
            'removed_at' => now(),
        ]);
        $second->update([
            'state' => 'killed',
            'current_hp' => 0,
            'removal_reason' => 'missile',
            'removed_at' => now(),
        ]);
        $this->monster($world, $ruleset, $cells[2], 4, 'whale');
        $unsafe = $this->context($world, hash('sha256', 'old bow no safe target'), [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $unsafe);
        $metrics = $service->execute($unsafe, $surface, true);
        $this->assertSame(1, $metrics['secretary_old_bow_no_safe_target']);
        $this->assertSame(0, $metrics['secretary_old_bow_attempts']);
        $this->assertStreamDrawCount($unsafe, $nation->id, 'trigger', 0, 9_999);
        $this->assertSame(0, DB::table('audit_events')
            ->whereIn('event_type', ['monster.damaged', 'monster.damage_blocked'])
            ->whereRaw("metadata->>'damage_type' = ?", ['secretary_old_bow'])
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $unsafe->run->id])->count());
    }

    public function test_old_bow_filters_authoritative_surface_ownership_and_has_constant_candidate_queries(): void
    {
        $world = $this->lightweightWorld();
        [, $firstNation] = $this->nation($world, '弓対象第一国');
        [$secondUser, $secondNation] = $this->nation($world, '弓対象第二国');
        $secondUser->secretary->itemInstances()->where('item_key', SecretaryItemCatalog::OLD_BOW)
            ->update(['equipped_slot' => null]);
        $ruleset = $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $surface = $this->surfaceMapSpace($world);
        $firstCells = MapCell::query()->where('owner_nation_id', $firstNation->id)
            ->whereNotIn('id', $firstNation->capital()->select('map_cell_id'))
            ->orderBy('id')->limit(18)->get();
        $secondCells = MapCell::query()->where('owner_nation_id', $secondNation->id)
            ->whereNotIn('id', $secondNation->capital()->select('map_cell_id'))
            ->orderBy('id')->limit(6)->get();
        $this->assertCount(18, $firstCells);
        $this->assertCount(6, $secondCells);

        $legal = $this->monster($world, $ruleset, $firstCells[0], 2);
        $neutral = $this->monster($world, $ruleset, $firstCells[1], 2);
        $firstCells[1]->update(['owner_nation_id' => null]);
        $foreign = $this->monster($world, $ruleset, $secondCells[0], 2);
        $dead = $this->monster($world, $ruleset, $firstCells[2], 2);
        $dead->update([
            'state' => 'killed',
            'current_hp' => 0,
            'removal_reason' => 'missile',
            'removed_at' => now(),
        ]);
        $removed = $this->monster($world, $ruleset, $firstCells[3], 2);
        $removed->update([
            'state' => 'removed',
            'removal_reason' => 'terrain',
            'removed_at' => now(),
        ]);
        $hardened = $this->monster($world, $ruleset, $firstCells[4], 4, 'whale');
        $offSurface = $this->monster($world, $ruleset, $firstCells[5], 2);
        $otherSpace = $surface->replicate();
        $otherSpace->key = 'test-disallowed-space';
        $otherSpace->name = 'test disallowed space';
        $otherSpace->save();
        $firstCells[5]->update(['map_space_id' => $otherSpace->id]);

        $hit = $this->context($world, $this->oldBowHitSeed($firstNation->id), [$firstNation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $hit);
        $metrics = app(SecretaryOldBowService::class)->execute($hit, $surface, true);
        $this->assertSame(1, $metrics['secretary_old_bow_hits']);
        $this->assertSame(1, $legal->fresh()->current_hp);
        foreach ([$neutral, $foreign, $dead, $removed, $hardened, $offSurface] as $excluded) {
            $this->assertSame($excluded->current_hp, $excluded->fresh()->current_hp);
        }

        foreach (range(6, 13) as $index) {
            $this->monster($world, $ruleset, $firstCells[$index], 2);
        }
        foreach (range(1, 5) as $index) {
            $this->monster($world, $ruleset, $secondCells[$index], 2);
        }
        $secondUser->secretary->itemInstances()->where('item_key', SecretaryItemCatalog::OLD_BOW)
            ->update(['equipped_slot' => 1]);
        $seed = $this->oldBowMissSeed([$firstNation->id, $secondNation->id]);
        $bounded = $this->context($world, $seed, [$secondNation->id, $firstNation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $bounded);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $metrics = app(SecretaryOldBowService::class)->execute($bounded, $surface, true);

        $this->assertSame(2, $metrics['secretary_old_bow_attempts']);
        $this->assertSame(2, $metrics['secretary_old_bow_misses']);
        $this->assertSame(5, count($queries), implode("\n", $queries));
        foreach ([$firstNation->id, $secondNation->id] as $nationId) {
            $this->assertStreamDrawCount($bounded, $nationId, 'trigger', 1, 9_999);
            $this->assertStreamDrawCount($bounded, $nationId, 'target', 0, 8);
        }
        $this->assertSame($otherSpace->id, $offSurface->occupancy()->firstOrFail()->cell()->value('map_space_id'));
    }

    public function test_old_bow_kills_after_cell_events_and_before_normal_monster_actions(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '弓撃破国');
        $ruleset = $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $monster = $this->monster($world, $ruleset, $this->ownedNonCapitalCell($nation), 1);
        $seed = $this->oldBowHitSeed($nation->id);
        $context = $this->context($world, $seed, [$nation->id]);
        $engine = app(CompleteTurnEngine::class);
        $engine->execute('prepare_turn', $context);
        $engine->execute('calculate_terrain_context', $context);
        $skillState = DB::table('secretary_skills')
            ->where('secretary_id', $context->state->secretarySnapshot($nation->id)['secretary_id'])
            ->orderBy('skill_key')->get(['skill_key', 'level', 'experience'])->toArray();

        $result = $engine->execute('process_cells', $context);

        $this->assertSame(1, $result->metrics['secretary_old_bow_hits']);
        $this->assertSame(1, $result->metrics['secretary_old_bow_kills']);
        $this->assertSame('killed', $monster->fresh()->state);
        $this->assertSame('secretary_old_bow', $monster->fresh()->removal_reason);
        $this->assertDatabaseMissing('monster_occupancies', ['monster_instance_id' => $monster->id]);
        $this->assertSame(0, DB::table('audit_events')
            ->whereIn('event_type', ['monster.moved', 'monster.stayed'])
            ->where(function ($query) use ($monster): void {
                $query->where('subject_id', $monster->id)
                    ->orWhereRaw("metadata->>'monster_id' = ?", [(string) $monster->id]);
            })->count());
        $this->assertDatabaseHas('nation_monster_kill_stats', [
            'nation_id' => $nation->id,
            'monster_definition_id' => $monster->monster_definition_id,
            'kill_count' => 1,
        ]);
        $this->assertSame(1, DB::table('nation_monster_cycle_stats')
            ->where('world_id', $world->id)->where('nation_id', $nation->id)->value('kill_count'));
        foreach (['monster.killed', 'monster.reward_distributed', 'monster.kill_stat_incremented'] as $eventType) {
            $this->assertSame(1, DB::table('audit_events')->where('event_type', $eventType)
                ->whereRaw("metadata->>'monster_instance_id' = ?", [(string) $monster->id])->count());
        }
        $this->assertSame(0, (int) DB::table('audit_events')
            ->where('event_type', 'monster.killed')
            ->where('subject_id', $monster->id)
            ->selectRaw("(metadata->>'firing_base_experience_applied')::integer AS experience")
            ->value('experience'));
        $this->assertEquals(
            $skillState,
            DB::table('secretary_skills')
                ->where('secretary_id', $context->state->secretarySnapshot($nation->id)['secretary_id'])
                ->orderBy('skill_key')->get(['skill_key', 'level', 'experience'])->toArray(),
        );
    }

    public function test_old_bow_nonlethal_hit_updates_the_batch_then_the_monster_acts(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '弓生存国');
        $ruleset = $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $monster = $this->monster($world, $ruleset, $this->ownedNonCapitalCell($nation), 2);
        $context = $this->context($world, $this->oldBowHitSeed($nation->id), [$nation->id]);
        $engine = app(CompleteTurnEngine::class);
        $engine->execute('prepare_turn', $context);
        $engine->execute('calculate_terrain_context', $context);

        $result = $engine->execute('process_cells', $context);

        $this->assertSame(1, $result->metrics['secretary_old_bow_hits']);
        $this->assertSame(0, $result->metrics['secretary_old_bow_kills']);
        $this->assertSame('alive', $monster->fresh()->state);
        $this->assertSame(1, $monster->fresh()->current_hp);
        $damageEventId = DB::table('audit_events')->where('event_type', 'monster.damaged')
            ->where('subject_id', $monster->id)->value('id');
        $actionEventId = DB::table('audit_events')
            ->whereIn('event_type', ['monster.moved', 'monster.stayed'])
            ->where(function ($query) use ($monster): void {
                $query->where('subject_id', $monster->id)
                    ->orWhereRaw("metadata->>'monster_id' = ?", [(string) $monster->id]);
            })->min('id');
        $this->assertNotNull($damageEventId);
        $this->assertNotNull($actionEventId);
        $this->assertGreaterThan($damageEventId, $actionEventId);
        $this->assertGreaterThanOrEqual(2, $monster->fresh()->version);
    }

    public function test_old_bow_uses_authored_zero_hp_safety_without_a_monster_key_branch(): void
    {
        $world = $this->lightweightWorld();
        [, $hpTwoNation] = $this->nation($world, '零式HP2国');
        [, $hpOneNation] = $this->nation($world, '零式HP1国');
        [, $hpThreeNation] = $this->nation($world, '零式HP3国');
        $ruleset = $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $hpTwo = $this->monster(
            $world,
            $ruleset,
            $this->ownedNonCapitalCell($hpTwoNation),
            2,
            'mecha_inora_zero',
        );
        $hpOne = $this->monster(
            $world,
            $ruleset,
            $this->ownedNonCapitalCell($hpOneNation),
            1,
            'mecha_inora_zero',
        );
        $hpThree = $this->monster(
            $world,
            $ruleset,
            $this->ownedNonCapitalCell($hpThreeNation),
            3,
            'mecha_inora_zero',
        );
        $seed = null;
        for ($candidate = 0; $candidate < 10_000; $candidate++) {
            $attempt = hash('sha256', "zero-old-bow-{$candidate}");
            $random = new TurnRandomStreamFactory($attempt);
            if ($random->stream(TurnRandomStreamFactory::secretaryOldBow($hpOneNation->id, 'trigger', 1))
                ->integer(0, 9_999) < 1_000
                && $random->stream(TurnRandomStreamFactory::secretaryOldBow($hpThreeNation->id, 'trigger', 1))
                    ->integer(0, 9_999) < 1_000) {
                $seed = $attempt;
                break;
            }
        }
        if ($seed === null) {
            throw new RuntimeException('No deterministic dual Old Bow trigger seed was found.');
        }
        $nationIds = [$hpTwoNation->id, $hpOneNation->id, $hpThreeNation->id];
        $context = $this->context($world, $seed, $nationIds);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);

        $metrics = app(SecretaryOldBowService::class)->execute(
            $context,
            $this->surfaceMapSpace($world),
            true,
        );

        $this->assertSame(3, $metrics['secretary_old_bow_eligible_nations']);
        $this->assertSame(1, $metrics['secretary_old_bow_no_safe_target']);
        $this->assertSame(2, $metrics['secretary_old_bow_hits']);
        $this->assertSame(1, $metrics['secretary_old_bow_kills']);
        $this->assertSame(2, $hpTwo->fresh()->current_hp);
        $this->assertSame('alive', $hpTwo->fresh()->state);
        $this->assertSame('killed', $hpOne->fresh()->state);
        $this->assertSame(2, $hpThree->fresh()->current_hp);
        $this->assertSame('alive', $hpThree->fresh()->state);
    }

    public function test_old_bow_rollback_and_same_seed_retry_replay_one_kill(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '弓retry国');
        $ruleset = $this->switchToItemRuleset($world);
        $world = $world->fresh();
        $monster = $this->monster($world, $ruleset, $this->ownedNonCapitalCell($nation), 1);
        $seed = $this->oldBowHitSeed($nation->id);
        $firstContext = $this->context($world, $seed, [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $firstContext);
        $rolledBack = [];

        try {
            DB::transaction(function () use ($firstContext, $world, $monster, &$rolledBack): void {
                $metrics = app(SecretaryOldBowService::class)->execute(
                    $firstContext,
                    $this->surfaceMapSpace($world),
                    true,
                );
                $metadata = $this->event($firstContext, 'monster.killed');
                unset($metadata['turn_run_id'], $metadata['kill_stat_id']);
                $rolledBack = ['metrics' => $metrics, 'metadata' => $metadata];
                $this->assertSame('killed', $monster->fresh()->state);
                throw new RuntimeException('old bow rollback probe');
            });
            $this->fail('Expected Old Bow rollback probe failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('old bow rollback probe', $exception->getMessage());
        }
        $this->assertSame('alive', $monster->fresh()->state);
        $this->assertDatabaseHas('monster_occupancies', ['monster_instance_id' => $monster->id]);
        $this->assertSame(0, DB::table('nation_monster_kill_stats')->where('nation_id', $nation->id)->count());

        $retry = $this->context($world, $seed, [$nation->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $retry);
        $retryMetrics = app(SecretaryOldBowService::class)->execute(
            $retry,
            $this->surfaceMapSpace($world),
            true,
        );
        $retryMetadata = $this->event($retry, 'monster.killed');
        unset($retryMetadata['turn_run_id'], $retryMetadata['kill_stat_id']);
        $this->assertSame($rolledBack['metrics'], $retryMetrics);
        $this->assertSame($rolledBack['metadata'], $retryMetadata);
        $this->assertSame('killed', $monster->fresh()->state);
        $this->assertSame(1, DB::table('nation_monster_kill_stats')
            ->where('nation_id', $nation->id)->value('kill_count'));
        $this->assertSame(1, DB::table('nation_monster_cycle_stats')
            ->where('nation_id', $nation->id)->value('kill_count'));
    }

    /** @return array{User, Nation} */
    private function nation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name, '試験島主')];
    }

    private function switchToItemRuleset(World $world): RulesetVersion
    {
        $ruleset = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v11')->sole();
        config(['hakoniwa.ruleset' => $ruleset->settings]);
        if ($world->ruleset_version_id !== $ruleset->id) {
            $world->update(['ruleset_version_id' => $ruleset->id]);
        }

        return $ruleset;
    }

    private function switchToV10Ruleset(World $world): RulesetVersion
    {
        $ruleset = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v10')->sole();
        config(['hakoniwa.ruleset' => $ruleset->settings]);
        $world->update(['ruleset_version_id' => $ruleset->id]);

        return $ruleset;
    }

    private function ring(User $user, int $level, ?int $slot, string $grantKey): SecretaryItemInstance
    {
        return SecretaryItemInstance::query()->create([
            'secretary_id' => $user->secretary()->value('id'),
            'item_key' => SecretaryItemCatalog::RING,
            'level' => $level,
            'equipped_slot' => $slot,
            'grant_key' => $grantKey,
            'obtained_at' => now(),
        ]);
    }

    private function queueFinance(User $user, Nation $nation, World $world): NationCommandQueueItem
    {
        return app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'finance',
            targetX: null,
            targetY: null,
            requestKey: (string) Str::uuid(),
            expectedVersion: (int) ($nation->commandQueue()->value('version') ?? 1),
            quantity: 1,
            quantityProvided: true,
        )['item'];
    }

    /** @param list<int> $nationIds */
    private function context(World $world, string $seed, array $nationIds): TurnContext
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
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
        $state = new TurnState;
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);

        return new TurnContext(
            $world,
            $run,
            $ruleset,
            2,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
    }

    private function retryContext(TurnContext $context): TurnContext
    {
        $state = new TurnState;
        $state->setStableNationIds($context->state->stableNationIds());
        $state->setDevelopmentNationIds($context->state->developmentNationIds());

        return new TurnContext(
            $context->world,
            $context->run,
            $context->ruleset,
            $context->targetTurn,
            $context->randomSeed,
            new TurnRandomStreamFactory($context->randomSeed),
            $state,
        );
    }

    /** @return array<string, mixed> */
    private function event(TurnContext $context, string $type): array
    {
        $metadata = DB::table('audit_events')->where('event_type', $type)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $context->run->id])
            ->value('metadata');
        $this->assertIsString($metadata);

        return json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
    }

    private function monster(
        World $world,
        RulesetVersion $ruleset,
        MapCell $cell,
        int $hp,
        string $definitionKey = 'inora',
    ): MonsterInstance {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', $definitionKey)->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $hp,
            'spawned_max_hp' => max($hp, $definition->base_hp),
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);

        return $monster;
    }

    private function ownedNonCapitalCell(Nation $nation): MapCell
    {
        return MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $nation->capital()->select('map_cell_id'))
            ->with(['terrain', 'facility'])
            ->orderBy('id')
            ->firstOrFail();
    }

    private function oldBowHitSeed(int $nationId): string
    {
        for ($candidate = 0; $candidate < 100; $candidate++) {
            $seed = hash('sha256', "old-bow-hit-{$nationId}-{$candidate}");
            $draw = (new TurnRandomStreamFactory($seed))->stream(
                TurnRandomStreamFactory::secretaryOldBow($nationId, 'trigger', 1),
            )->integer(0, 9_999);
            if ($draw < 1_000) {
                return $seed;
            }
        }

        $this->fail('No deterministic Old Bow hit seed was found in the bounded fixture search.');
    }

    /** @param list<int> $nationIds */
    private function oldBowMissSeed(array $nationIds): string
    {
        for ($candidate = 0; $candidate < 100; $candidate++) {
            $seed = hash('sha256', "old-bow-miss-{$candidate}");
            $allMiss = collect($nationIds)->every(function (int $nationId) use ($seed): bool {
                return (new TurnRandomStreamFactory($seed))->stream(
                    TurnRandomStreamFactory::secretaryOldBow($nationId, 'trigger', 1),
                )->integer(0, 9_999) >= 1_000;
            });
            if ($allMiss) {
                return $seed;
            }
        }

        $this->fail('No deterministic Old Bow miss seed was found in the bounded fixture search.');
    }

    private function assertStreamDrawCount(
        TurnContext $context,
        int $nationId,
        string $purpose,
        int $consumed,
        int $maximum,
    ): void {
        $label = TurnRandomStreamFactory::secretaryOldBow($nationId, $purpose, 1);
        $reference = (new TurnRandomStreamFactory($context->randomSeed))->stream($label);
        for ($index = 0; $index < $consumed; $index++) {
            $reference->integer(0, $maximum);
        }
        $this->assertSame(
            $reference->integer(0, $maximum),
            $context->random->stream($label)->integer(0, $maximum),
            "Unexpected {$purpose} draw population for Nation {$nationId}.",
        );
    }
}
