<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\KarmaTurnService;
use App\Application\MissileImpactResolver;
use App\Application\MonsterRemovalService;
use App\Application\NationLifecycleService;
use App\Application\PlayerIslandEventService;
use App\Application\SecretaryTurnService;
use App\Application\Underground\UndergroundProfileService;
use App\Domain\Facility\MissileBaseRules;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterOccupancy;
use App\Models\NationUndergroundFacility;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Services\MapCellPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\Support\CommandAndMissileTestCase;

final class MissileImpactAndSettlementTest extends CommandAndMissileTestCase
{
    use UsesReusableSurfaceWorld;

    public function test_failed_command_continues_to_finance_and_idle_counter_changes_once_per_target_turn(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '資金繰り国');
        $space = $this->surfaceMapSpace($world);
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();
        $ownerNationId = $forest->owner_nation_id;
        $this->assertSame(500, $forest->terrain_quantity);
        $service = app(CommandQueueService::class);

        $failed = $this->queue($service, $user, $nation, $space, 'build_farm', $forest, 1, 1);
        $this->queue($service, $user, $nation, $space, 'finance', null, 1, 2);
        $this->queue($service, $user, $nation, $space, 'finance', null, 1, 3);
        $logging = $this->queue($service, $user, $nation, $space, 'logging', $forest, 1, 4);

        $first = app(DomesticCommandExecutor::class)->execute($this->context($world, 2, str_repeat('1', 64), [$nation->id]));
        $this->assertSame(1, $first['failures']);
        $this->assertSame(1, $first['finance_commands']);
        $this->assertSame(1, $first['idle_counter_increments']);
        $this->assertSame('invalid_terrain', $failed->fresh()->failure_code);
        $this->assertSame(2001, $nation->fresh()->idle_counter);
        $this->assertSame('queued', $logging->fresh()->status);

        $second = app(DomesticCommandExecutor::class)->execute($this->context($world, 3, str_repeat('2', 64), [$nation->id]));
        $this->assertSame(1, $second['finance_commands']);
        $this->assertSame(1, $second['idle_counter_increments']);
        $this->assertSame(2002, $nation->fresh()->idle_counter);

        $third = app(DomesticCommandExecutor::class)->execute($this->context($world, 4, str_repeat('3', 64), [$nation->id]));
        $this->assertSame(1, $third['successes']);
        $this->assertSame(1, $third['idle_counter_resets']);
        $this->assertSame(0, $nation->fresh()->idle_counter);
        $this->assertSame('completed', $logging->fresh()->status);
        $loggedForest = $forest->fresh(['terrain']);
        $this->assertSame('plain', $loggedForest->terrain->key);
        $this->assertNotSame('wasteland', $loggedForest->terrain->key);
        $this->assertSame($ownerNationId, $loggedForest->owner_nation_id);
        $this->assertNull($loggedForest->terrain_quantity);
        $this->assertSame(145, $nation->fresh()->money);

        $publicLogging = DB::table('audit_events')->where('event_type', 'command.logging_public')->sole();
        $this->assertSame('public', $publicLogging->visibility);
        $publicLoggingMetadata = json_decode((string) $publicLogging->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($world->id, $publicLoggingMetadata['world_id']);
        $this->assertSame($nation->id, $publicLoggingMetadata['nation_id']);
        $this->assertSame($nation->name, $publicLoggingMetadata['nation_name']);
        $this->assertSame(4, $publicLoggingMetadata['target_turn']);
        $this->assertArrayNotHasKey('x', $publicLoggingMetadata);
        $this->assertArrayNotHasKey('y', $publicLoggingMetadata);
        $this->assertArrayNotHasKey('applied_money', $publicLoggingMetadata);
        $privateLogging = DB::table('audit_events')->where('event_type', 'command.logging_private')->sole();
        $this->assertSame('private', $privateLogging->visibility);
        $privateLoggingMetadata = json_decode((string) $privateLogging->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(500, $privateLoggingMetadata['tree_units']);
        $this->assertSame(25, $privateLoggingMetadata['requested_money']);
        $this->assertSame(25, $privateLoggingMetadata['applied_money']);
        $this->assertSame(0, $privateLoggingMetadata['overflow_money']);

        $event = DB::table('audit_events')->where('event_type', 'command.failed')
            ->where('subject_id', $failed->id)->firstOrFail();
        $this->assertSame('nation', $event->visibility);
        $metadata = json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_terrain', $metadata['failure_reason']);
        $this->assertArrayHasKey('observed', $metadata);
        $this->assertArrayHasKey('original_parameters', $metadata);

        $page = app(PlayerIslandEventService::class)->ownerPage($nation->fresh(), 1, 4);
        $messages = collect($page['groups'])->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($messages->contains(
            fn (string $message): bool => str_contains($message, '農場建設可能な平地ではありませんでした'),
        ));
    }

    public function test_zero_shot_missile_paths_keep_idle_counter_without_automatic_finance(): void
    {
        [$world, $user, $firing, $target] = $this->combatants();
        $firing->update(['idle_counter' => 4]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $item = $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital);
        $context = $this->context($world, 2, hash('sha256', 'destroyed missile base'), [$firing->id]);

        $development = app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame(0, $development['automatic_finance']);
        $this->assertSame(0, $development['idle_counter_resets']);
        $this->assertSame(4, $firing->fresh()->idle_counter);
        app(MapCellStateService::class)->setFacility($base, null);
        $base->save();

        $result = $this->processRegisteredMissiles($context, [$base]);

        $this->assertSame(0, $result['shots_fired']);
        $this->assertSame(0, $result['finalize']['idle_counter_resets']);
        $this->assertSame(4, $firing->fresh()->idle_counter);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launch_failed')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.automatic_finance')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
            ->where('nation_id', $firing->id)->count());
        $this->assertSame([
            'finance_succeeded' => false,
            'immediate_normal_command_succeeded' => false,
            'missile_intent_pending' => true,
            'missile_shots_fired' => 0,
            'idle_counter_finalized' => true,
        ], $context->state->nationActivity($firing->id));

        // A still-existing base that runs out of money reaches the same
        // zero-shot settlement through a distinct launch-failure branch.
        app(MapCellStateService::class)->setFacility(
            $base,
            FacilityDefinition::query()->where('key', 'missile_base')->firstOrFail(),
        );
        $base->save();
        $firing->update(['money' => 1_000, 'idle_counter' => 3]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $fundsItem = $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital);
        $context = $this->context($world, 2, hash('sha256', 'missile funds exhausted'), [$firing->id]);

        app(DomesticCommandExecutor::class)->execute($context);
        $firing->update(['money' => 0]);
        $result = $this->processRegisteredMissiles($context, [$base]);

        $this->assertSame(0, $result['shots_fired']);
        $this->assertSame(3, $firing->fresh()->idle_counter);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launch_failed')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $fundsItem->id])->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
            ->where('nation_id', $firing->id)->count());

        // A failed normal command before a destroyed-base intent must not
        // finalize idle activity twice.
        $firing->update(['money' => 1_000, 'idle_counter' => 5]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $forest = MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $failed = $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'build_farm', $forest, 1, 1);
        $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital, 1, 2);
        $context = $this->context($world, 2, hash('sha256', 'failed normal and zero shot missile'), [$firing->id]);

        $development = app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame(1, $development['failures']);
        $this->assertSame('failed', $failed->fresh()->status);
        app(MapCellStateService::class)->setFacility($base, null);
        $base->save();
        $this->processRegisteredMissiles($context, [$base]);

        $this->assertSame(5, $firing->fresh()->idle_counter);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
            ->where('nation_id', $firing->id)->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.automatic_finance')
            ->where('nation_id', $firing->id)->count());
    }

    public function test_actual_missile_shot_resets_idle_counter_only_after_finalize(): void
    {
        [$world, $user, $firing, $target] = $this->combatants();
        $firing->update(['idle_counter' => 6]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital);
        $context = $this->context($world, 2, hash('sha256', 'actual missile shot'), [$firing->id]);

        $development = app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame(0, $development['idle_counter_resets']);
        $this->assertSame(6, $firing->fresh()->idle_counter);
        $result = $this->processRegisteredMissiles($context, [$base]);

        $this->assertSame(1, $result['shots_fired']);
        $this->assertSame(1, $result['finalize']['idle_counter_resets']);
        $this->assertSame(0, $firing->fresh()->idle_counter);
        $event = DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
            ->where('nation_id', $firing->id)->firstOrFail();
        $metadata = json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $metadata['missile_shots_fired']);
        $this->assertTrue($metadata['missile_intent_pending']);
        $this->assertFalse($metadata['immediate_normal_command_succeeded']);
    }

    public function test_normal_missile_can_launch_at_an_active_nation_cell_beyond_legacy_base_range(): void
    {
        [$world, $user, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $baseCoordinate = new GridCoordinate($base->x, $base->y);
        $targetCell = MapCell::query()->where('map_space_id', $space->id)
            ->with(['terrain', 'facility'])->get()
            ->sortByDesc(static fn (MapCell $cell): int => $baseCoordinate->distanceTo(
                new GridCoordinate($cell->x, $cell->y),
            ))->first();
        $this->assertInstanceOf(MapCell::class, $targetCell);
        $this->assertGreaterThan(
            12,
            $baseCoordinate->distanceTo(new GridCoordinate($targetCell->x, $targetCell->y)),
        );
        app(MapCellStateService::class)->setFacility($targetCell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $targetCell,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        $targetCell->owner_nation_id = $target->id;
        $targetCell->population = 0;
        $targetCell->save();
        $moneyBefore = (int) $firing->money;
        $item = $this->queue(
            app(CommandQueueService::class),
            $user,
            $firing,
            $space,
            'missile',
            $targetCell->fresh(['terrain', 'facility']),
        );
        $context = $this->context($world, 2, hash('sha256', 'unlimited missile distance'), [$firing->id]);

        app(DomesticCommandExecutor::class)->execute($context);
        $result = $this->processRegisteredMissiles($context, [$base]);

        $this->assertSame(1, $result['shots_fired']);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame($moneyBefore - 20, $firing->fresh()->money);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launched')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->count());
    }

    public function test_current_explicit_targeting_preserves_v2_own_foreign_neutral_and_unowned_sea_contract(): void
    {
        [$world, $user, $firing, $foreign] = $this->combatants();
        $this->assertSame('hakoniwa-2s-plus-v26', $world->rulesetVersion()->value('key'));
        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $own = MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereKeyNot($base->id)->whereNull('facility_definition_id')->firstOrFail();
        $foreignCell = MapCell::query()->where('owner_nation_id', $foreign->id)->firstOrFail();
        $neutral = MapCell::query()->where('map_space_id', $space->id)->whereNull('owner_nation_id')
            ->whereNull('facility_definition_id')->whereHas('terrain', fn ($query) => $query->where('key', 'wasteland'))
            ->firstOrFail();
        $sea = MapCell::query()->where('map_space_id', $space->id)->whereNull('owner_nation_id')
            ->whereNull('facility_definition_id')->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->firstOrFail();

        $preview = collect($this->actingAs($user)->getJson(
            "/api/v1/nations/{$firing->id}/map-spaces/{$space->id}/command-definitions"
            ."?target_x={$neutral->x}&target_y={$neutral->y}",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'spp_missile');
        $this->assertSame('currently_executable', $preview['execution_preview_status']);
        $this->assertNotContains('自国領のcellだけを対象にできます。', $preview['execution_warnings']);

        foreach ([$own, $foreignCell, $neutral, $sea] as $index => $target) {
            $item = $this->queue(
                app(CommandQueueService::class),
                $user,
                $firing,
                $space,
                'spp_missile',
                $target,
            );
            $context = $this->context(
                $world,
                $index + 2,
                hash('sha256', "v2-explicit-target:{$index}"),
                [$firing->id, $foreign->id],
            );

            $result = app(DomesticCommandExecutor::class)->execute($context);

            $this->assertSame(1, $result['successes']);
            $this->assertSame('completed', $item->fresh()->status);
            $intent = $context->state->launchIntentsForNation($firing->id)[0];
            $this->assertSame([$target->x, $target->y], [$intent->targetX, $intent->targetY]);
        }
    }

    public function test_partial_multi_base_multi_intent_launch_resets_idle_counter_once(): void
    {
        [$world, $user, $firing, $target] = $this->combatants();
        $firing->update(['idle_counter' => 7]);
        $space = $this->surfaceMapSpace($world);
        $firstBase = $this->missileBase($firing);
        $secondBase = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $first = $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital, 1, 1);
        $second = $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital, 1, 2);
        $context = $this->context($world, 2, hash('sha256', 'partial multiple missile intents'), [$firing->id]);

        app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
        $context->state->registerLaunchIntent(
            $firing->id,
            'spp_missile',
            $capital->x,
            $capital->y,
            1,
            $second->id,
        );
        $cost = $context->ruleset->settings['military']['missiles']['spp_missile']['cost_money_per_shot'];
        $this->assertIsInt($cost);
        $firing->update(['money' => $cost]);

        $result = $this->processRegisteredMissiles($context, [$firstBase, $secondBase]);

        $this->assertSame(1, $result['shots_fired']);
        $this->assertSame(1, $result['finalize']['idle_counter_resets']);
        $this->assertSame(0, $firing->fresh()->idle_counter);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launched')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launch_failed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
            ->where('nation_id', $firing->id)->count());
        $this->assertSame(1, $context->state->nationActivity($firing->id)['missile_shots_fired']);
    }

    public function test_empty_queue_automatic_finance_increments_idle_counter_once_per_target_turn(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '自動資金繰り国');
        $nation->update(['idle_counter' => 2]);

        $result = app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'empty queue automatic finance'),
            [$nation->id],
        ));

        $this->assertSame(1, $result['automatic_finance']);
        $this->assertSame(1, $result['idle_counter_increments']);
        $this->assertSame(3, $nation->fresh()->idle_counter);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
            ->where('nation_id', $nation->id)->count());
    }

    public function test_rolled_back_missile_activity_is_applied_once_on_deterministic_retry(): void
    {
        [$world, $user, $firing, $target] = $this->combatants();
        $firing->update(['idle_counter' => 4]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $item = $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital);
        $seed = hash('sha256', 'deterministic missile idle retry');

        try {
            DB::transaction(function () use ($world, $firing, $base, $seed): void {
                $context = $this->context($world, 2, $seed, [$firing->id]);
                app(DomesticCommandExecutor::class)->execute($context);
                $this->processRegisteredMissiles($context, [$base]);

                throw new RuntimeException('force rollback after missile idle finalization');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback after missile idle finalization', $exception->getMessage());
        }

        $this->assertSame(4, $firing->fresh()->idle_counter);
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')->count());

        $retry = $this->context($world, 2, $seed, [$firing->id]);
        app(DomesticCommandExecutor::class)->execute($retry);
        $result = $this->processRegisteredMissiles($retry, [$base]);

        $this->assertSame(1, $result['shots_fired']);
        $this->assertSame(0, $firing->fresh()->idle_counter);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
            ->where('nation_id', $firing->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launched')->count());
    }

    public function test_shallow_reclaim_clear_and_farm_execute_as_a_future_queue_chain(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '未来計画国');
        $nation->update(['money' => 2_000]);
        $space = $this->surfaceMapSpace($world);
        $anchor = MapCell::query()->where('owner_nation_id', $nation->id)->firstOrFail();
        $coordinate = (new GridCoordinate($anchor->x, $anchor->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        )[0];
        $target = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)->firstOrFail();
        app(MapCellStateService::class)->setFacility($target, null);
        app(MapCellStateService::class)->transitionTerrain(
            $target,
            TerrainDefinition::query()->where('key', 'shallow')->firstOrFail(),
        );
        $target->owner_nation_id = null;
        $target->population = 0;
        $target->save();
        $service = app(CommandQueueService::class);

        $this->queue($service, $user, $nation, $space, 'reclaim', $target, 1, 1);
        $this->queue($service, $user, $nation, $space, 'land_clear', $target, 1, 2);
        $farm = $this->queue($service, $user, $nation, $space, 'build_farm', $target, 1, 3);

        app(DomesticCommandExecutor::class)->execute($this->context($world, 2, str_repeat('4', 64), [$nation->id]));
        $this->assertSame('wasteland', $target->fresh()->terrain()->value('key'));
        app(DomesticCommandExecutor::class)->execute($this->context($world, 3, str_repeat('5', 64), [$nation->id]));
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
        app(DomesticCommandExecutor::class)->execute($this->context($world, 4, str_repeat('6', 64), [$nation->id]));

        $this->assertSame('completed', $farm->fresh()->status);
        $this->assertSame('farm', $target->fresh()->facility()->value('key'));
        $this->assertSame($nation->id, $target->fresh()->owner_nation_id);
        $this->assertSame(0, $nation->fresh()->idle_counter);
    }

    public function test_reclaim_without_adjacent_territory_projects_the_actionable_failure_reason(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '埋立失敗国');
        $space = $this->surfaceMapSpace($world);
        $target = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $space->min_x)->where('y', $space->min_y)->firstOrFail();
        app(MapCellStateService::class)->setFacility($target, null);
        app(MapCellStateService::class)->transitionTerrain(
            $target,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        $target->owner_nation_id = null;
        $target->population = 0;
        $target->save();
        $moneyBefore = (int) $nation->money;
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $space, 'reclaim', $target);
        $finance = $this->queue(app(CommandQueueService::class), $user, $nation, $space, 'finance', null, 1, 2);

        $result = app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'reclaim missing adjacent territory'),
            [$nation->id],
        ));

        $this->assertSame(1, $result['failures']);
        $this->assertSame(1, $result['successes']);
        $this->assertSame('no_adjacent_owned_land', $item->fresh()->failure_code);
        $this->assertSame('completed', $finance->fresh()->status);
        $this->assertSame($moneyBefore + 10, (int) $nation->fresh()->money);
        $this->assertSame('sea', $target->fresh()->terrain()->value('key'));
        $page = app(PlayerIslandEventService::class)->ownerPage($nation, 1, 2);
        $messages = collect($page['groups'])->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertContains(sprintf(
            '%s(%d,%d)で行われようとしていた埋め立ては、隣接する自国領地がないため実行できませんでした。',
            $nation->name,
            $target->x,
            $target->y,
        ), $messages->all());
    }

    public function test_spp_exact_capital_hit_preserves_identity_generates_refugees_and_private_detail(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $before = $capital->population;
        $firingPopulation = (int) MapCell::query()->where('owner_nation_id', $firing->id)->sum('population');
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $capital);
        $context = $this->context($world, 2, str_repeat('7', 64), [$firing->id, $target->id]);

        $metrics = $this->resolveMissile($context, $base);

        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));
        $this->assertSame(intdiv($before * 90, 100), $capital->fresh()->population);
        $this->assertSame($capital->id, $target->capital()->value('map_cell_id'));
        $generated = intdiv($before - $capital->fresh()->population, 2);
        $this->assertSame($generated, (int) DB::table('audit_events')->where('event_type', 'refugee_generated')
            ->whereRaw("metadata->>'missile_key' = ?", ['spp_missile'])->value(DB::raw("(metadata->>'generated_population')::integer")));
        $this->assertSame($firingPopulation + $generated, (int) MapCell::query()
            ->where('owner_nation_id', $firing->id)->sum('population'));
        $this->assertSame(500, 1_000 - $firing->fresh()->money);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launched')
            ->where('visibility', 'public')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.impact')
            ->where('visibility', 'public')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')->count());
        $this->assertSame(1, $metrics['meaningful_impacts']);
        $this->assertSame(0, $metrics['ineffective_impacts']);
        $detail = DB::table('audit_events')->where('event_type', 'missile.launch_detail')->firstOrFail();
        $this->assertSame('private', $detail->visibility);
        $detailMetadata = json_decode((string) $detail->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($capital->x, $detailMetadata['target_x']);
        $this->assertSame($capital->y, $detailMetadata['target_y']);
        $this->assertSame(500, $detailMetadata['cost_money']);
        $this->assertCount(1, $detailMetadata['impacts']);
        $this->assertSame('capital_damaged', $detailMetadata['impacts'][0]['effect']);
        $this->assertCount(1, $detailMetadata['firing_bases']);
        $this->assertSame($base->x, $detailMetadata['firing_bases'][0]['x']);
        $this->assertSame($base->y, $detailMetadata['firing_bases'][0]['y']);
        $this->assertSame(1, $detailMetadata['firing_bases'][0]['fired_shots']);
        $this->assertSame('completed', $item->fresh()->status);

        $targetMessages = collect(app(PlayerIslandEventService::class)->publicNationPage($target, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($targetMessages->contains(
            fn (string $message): bool => str_contains($message, '標的国')
                && str_contains($message, '発射国のSPPミサイルが着弾'),
        ));
        $this->assertFalse($targetMessages->contains(
            fn (string $message): bool => str_contains($message, '狙点'),
        ));
        $firingMessages = collect(app(PlayerIslandEventService::class)->ownerPage($firing, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($firingMessages->contains(
            fn (string $message): bool => str_contains($message, 'SPPミサイルを狙点')
                && str_contains($message, '費用500億円')
                && str_contains($message, sprintf('発射基地: (%d,%d)から1発', $base->x, $base->y))
                && str_contains($message, '着弾結果:'),
        ));
    }

    public function test_collar_level_eleven_adds_fifteen_percent_after_karma_refugee_generation(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('collar-refugee');
        $firing->update(['karma' => 1]);
        $target->update(['karma' => 0]);
        $this->equipCollar($firingUser, 11);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $before = $capital->population;
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $capital);

        $this->resolvePreparedKarmaMissileTurn(
            $world,
            $firing,
            $target,
            $base,
            $item,
            2,
            str_repeat('7', 64),
        );

        $baseGenerated = intdiv($before - $capital->fresh()->population, 2);
        $expected = $baseGenerated + intdiv($baseGenerated * 15, 100);
        $generated = (int) DB::table('audit_events')->where('event_type', 'refugee_generated')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])
            ->value(DB::raw("(metadata->>'generated_population')::integer"));
        $this->assertSame($expected, $generated);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'karma.refugee_bonus')->count());
    }

    public function test_refugees_use_the_turn_start_birthrate_skill_attraction_capacity_without_raising_capital_capacity(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('birthrate-refugee');
        $firing->update(['karma' => 0]);
        $target->update(['karma' => 0]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $firingUser->secretary()->sole()->skills()
            ->where('skill_key', SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY)
            ->update(['level' => 10, 'experience' => 0]);
        $rules = $world->rulesetVersion()->sole()->settings['turn_processing']['settlement'];
        $baseMaximum = $rules['attraction_maximum_population'];
        $effectiveMaximum = $baseMaximum + 1_000;
        $firingCapitalId = $firing->capital()->value('map_cell_id');
        $receivingCell = MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereKeyNot($firingCapitalId)->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $receivingCell,
            FacilityDefinition::query()->where('key', 'city')->firstOrFail(),
        );
        $receivingCell->population = $baseMaximum;
        $receivingCell->version++;
        $receivingCell->save();
        $underseaCity = MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereNotIn('id', [$firingCapitalId, $receivingCell->id])
            ->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $underseaCity,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $underseaCity,
            FacilityDefinition::query()->where('key', 'undersea_city')->firstOrFail(),
        );
        $underseaCity->population = 3_000;
        $underseaCity->version++;
        $underseaCity->save();
        $settlementKeys = $world->rulesetVersion()->sole()->settings['military']['refugees']['settlement_facility_keys'];
        MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereKeyNot($receivingCell->id)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $settlementKeys))
            ->get()->each(function (MapCell $cell) use ($firingCapitalId, $effectiveMaximum, $world): void {
                $cell->population = $cell->id === $firingCapitalId
                    ? $world->rulesetVersion()->sole()->settings['capital_growth_maximum_population']
                    : $effectiveMaximum;
                $cell->version++;
                $cell->save();
            });
        $targetCapital = $target->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $targetCapital->update(['population' => 25_000]);
        $base = $this->missileBase($firing);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing->fresh(),
            $this->surfaceMapSpace($world),
            'spp_missile',
            $targetCapital->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $nationIds = [$firing->id, $target->id];
        $context = $this->context($world, 2, str_repeat('7', 64), $nationIds);
        $context->state->setLifecycleNationIds($nationIds);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        $firingUser->secretary()->sole()->skills()
            ->where('skill_key', SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY)
            ->update(['level' => 0]);
        app(DomesticCommandExecutor::class)->execute($context);
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));

        $metrics = $resolver->processBase(
            $context,
            $this->surfaceMapSpace($world),
            $base->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $resolver->finalize($context);

        $this->assertSame(1, $metrics['shots_fired']);
        $received = DB::table('audit_events')->where('event_type', 'refugee_received')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->sole();
        $metadata = json_decode((string) $received->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1_250, $metadata['generated_population']);
        $this->assertSame(1_000, $metadata['received_population']);
        $this->assertSame(250, $metadata['unreceived_population']);
        $this->assertSame($effectiveMaximum, $receivingCell->fresh()->population);
        $this->assertSame(3_000, $underseaCity->fresh()->population);
        $this->assertSame('undersea_city', $underseaCity->fresh()->facility()->value('key'));
        $this->assertSame(
            $world->rulesetVersion()->sole()->settings['capital_growth_maximum_population'],
            (int) MapCell::query()->whereKey($firingCapitalId)->value('population'),
        );
    }

    public function test_collar_doubles_only_positive_settlement_crime_on_one_versioned_impact_draw_without_public_leak(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('crime-double');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 0]);
        $this->equipCollar($firingUser, 11);
        DB::table('secretary_skills')->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $settlement = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', ['village', 'town', 'city']))
            ->with(['terrain', 'facility', 'ownerNation'])->orderBy('id')->firstOrFail();
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $settlement);
        $seed = $this->seedForImpactAndCollarTrigger($item, $settlement, 2, $settlement, $firing->id, 1_500);

        $this->resolvePreparedKarmaMissileTurn($world, $firing, $target, $base, $item, 2, $seed);

        $impact = json_decode((string) DB::table('audit_events')->where('event_type', 'karma.missile_impact')
            ->orderByDesc('id')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($item->id, $impact['queue_item_id']);
        $this->assertSame(0, $impact['attacker_start_karma']);
        $this->assertGreaterThan(0, $impact['base_crime_points']);
        $this->assertTrue($impact['collar_triggered']);
        $this->assertSame($impact['base_crime_points'] * 2, $impact['final_crime_points']);
        $this->assertSame($impact['final_crime_points'], $impact['crime_points']);
        $publicJson = json_encode(app(PlayerIslandEventService::class)->publicWorldPage($world, 1, 2), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('首輪', $publicJson);
        $this->assertStringNotContainsString('collar', $publicJson);

        $target->update(['karma' => 0]);
        $farm = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->with(['terrain', 'facility', 'ownerNation'])
            ->orderByDesc('id')->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $farm,
            FacilityDefinition::query()->where('key', 'farm')->firstOrFail(),
        );
        $farm->population = 0;
        $farm->save();
        $second = $this->queue(app(CommandQueueService::class), $firingUser, $firing->fresh(), $space, 'missile', $farm);
        $secondSeed = $this->seedForImpactAndCollarTrigger($second, $farm, 2, $farm, $firing->id, 1_500);
        $this->resolvePreparedKarmaMissileTurn($world, $firing->fresh(), $target->fresh(), $base, $second, 3, $secondSeed);
        $farmImpact = json_decode((string) DB::table('audit_events')->where('event_type', 'karma.missile_impact')
            ->orderByDesc('id')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($second->id, $farmImpact['queue_item_id']);
        $this->assertGreaterThan(0, $farmImpact['base_crime_points']);
        $this->assertFalse($farmImpact['collar_triggered']);
        $this->assertSame($farmImpact['base_crime_points'], $farmImpact['final_crime_points']);
    }

    public function test_normal_and_land_destruction_missiles_keep_a_minimum_capital_as_a_complete_no_op(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $minimum = $world->rulesetVersion()->firstOrFail()->settings['capital_minimum_population'];
        $this->assertIsInt($minimum);
        $capital->update(['population' => $minimum]);
        $capital = $capital->fresh(['terrain', 'facility']);
        $cellVersion = $capital->version;
        $chunkVersion = (int) DB::table('map_chunks')->where('id', $capital->map_chunk_id)->value('version');
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $capital);
        $seed = $this->seedForImpactIndex($item, $capital, 2, $capital);
        $context = $this->context($world, 2, $seed, [$firing->id, $target->id]);

        $metrics = $this->resolveMissile($context, $base);

        $capital->refresh();
        $this->assertSame($minimum, $capital->population);
        $this->assertSame($cellVersion, $capital->version);
        $this->assertSame($chunkVersion, (int) DB::table('map_chunks')->where('id', $capital->map_chunk_id)->value('version'));
        $this->assertSame([], $metrics['changed_cell_ids']);
        $this->assertSame([], $context->state->changedMapChunkIds());
        $this->assertSame(0, $metrics['meaningful_impacts']);
        $this->assertSame(1, $metrics['ineffective_impacts']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $aggregate = DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')->firstOrFail();
        $aggregateMetadata = json_decode((string) $aggregate->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $aggregateMetadata['ineffective_impacts']);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($capital->x, $detail['impacts'][0]['x']);
        $this->assertSame($capital->y, $detail['impacts'][0]['y']);
        $this->assertSame('capital_at_minimum', $detail['impacts'][0]['effect']);

        // Land destruction keeps the same no-op persistence contract while
        // retaining its own refugee and Capital identity guarantees.
        [$world, $firingUser, $firing, $target] = $this->combatants('・陸破壊');
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $minimum = $world->rulesetVersion()->firstOrFail()->settings['capital_minimum_population'];
        $this->assertIsInt($minimum);
        $capital->update(['population' => $minimum]);
        $capital = $capital->fresh(['terrain', 'facility']);
        $snapshot = $capital->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version',
        ]);
        $capitalIdentity = $target->capital()->value('map_cell_id');
        $chunkVersion = (int) DB::table('map_chunks')->where('id', $capital->map_chunk_id)->value('version');
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'land_destruction_missile',
            $capital,
        );
        $seed = $this->seedForImpactIndex($item, $capital, 2, $capital);
        $context = $this->context($world, 2, $seed, [$firing->id, $target->id]);

        $metrics = $this->resolveMissile($context, $base);

        $this->assertSame($snapshot, $capital->fresh()->only(array_keys($snapshot)));
        $this->assertSame($capitalIdentity, $target->capital()->value('map_cell_id'));
        $this->assertSame($chunkVersion, (int) DB::table('map_chunks')->where('id', $capital->map_chunk_id)->value('version'));
        $this->assertSame([], $metrics['changed_cell_ids']);
        $this->assertSame([], $context->state->changedMapChunkIds());
        $this->assertSame(0, $metrics['meaningful_impacts']);
        $this->assertSame(1, $metrics['ineffective_impacts']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $aggregate = DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')
            ->orderByDesc('id')->firstOrFail();
        $aggregateMetadata = json_decode((string) $aggregate->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $aggregateMetadata['ineffective_impacts']);
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', ['refugee_generated', 'refugee_received'])->count());
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($capital->x, $detail['impacts'][0]['x']);
        $this->assertSame($capital->y, $detail['impacts'][0]['y']);
        $this->assertSame('capital_at_minimum', $detail['impacts'][0]['effect']);
        $this->assertSame(0, $detail['impacts'][0]['refugees']);
    }

    public function test_actual_land_impact_is_returned_by_map_api_as_the_scorched_tile(): void
    {
        $assetDirectory = storage_path('framework/testing/scorched-asset-'.Str::uuid());
        mkdir($assetDirectory, 0777, true);
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        $this->assertIsString($gif);
        file_put_contents($assetDirectory.DIRECTORY_SEPARATOR.'land13.gif', $gif);
        config([
            'hakoniwa.assets.path' => $assetDirectory,
            'hakoniwa.assets.base_url' => '/assets/hakoniwa-tiles',
        ]);
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility($cell, null);
        $cell->update(['population' => 1_000]);
        $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cell,
        );

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'scorched map api'), [$firing->id, $target->id]),
            $base,
        );

        $this->assertSame('scorched', $cell->fresh()->terrain()->value('key'));
        $response = $this->actingAs($firingUser)->getJson(
            "/api/v1/map-spaces/{$space->id}/chunks/{$cell->chunk_x}/{$cell->chunk_y}",
        )->assertOk();
        $presented = collect($response->json('data.cells'))->first(
            fn (array $entry): bool => $entry['x'] === $cell->x && $entry['y'] === $cell->y,
        );
        $this->assertIsArray($presented);
        $this->assertSame('scorched', $presented['terrain']);
        $this->assertSame('tile.scorched', $presented['asset']['key']);
        $this->assertTrue($presented['asset']['available']);
        $this->assertStringContainsString('/land13.gif?v=', $presented['asset']['url']);
        unlink($assetDirectory.DIRECTORY_SEPARATOR.'land13.gif');
        rmdir($assetDirectory);
    }

    public function test_effective_wasteland_impact_scorches_only_terrain_and_updates_the_map_chunk(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility'])->firstOrFail();
        $cells = app(MapCellStateService::class);
        $cells->transitionTerrain($cell, TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail());
        $cells->setFacility($cell, FacilityDefinition::query()->where('key', 'factory')->firstOrFail());
        $cell->population = 4_321;
        $cell->version++;
        $cell->save();
        $cell = $cell->fresh(['terrain', 'facility', 'ownerNation']);
        $identity = $cell->only([
            'id', 'map_space_id', 'map_chunk_id', 'x', 'y', 'chunk_x', 'chunk_y', 'local_x', 'local_y',
            'facility_definition_id', 'facility_scale', 'facility_experience',
            'facility_operational_state', 'owner_nation_id', 'population', 'state',
        ]);
        $cellVersion = $cell->version;
        $chunkVersion = (int) DB::table('map_chunks')->where('id', $cell->map_chunk_id)->value('version');
        $item = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cell,
        );
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'effective wasteland scorch'),
            [$firing->id, $target->id],
        );

        $metrics = $this->resolveMissile($context, $base);

        $cell = $cell->fresh(['terrain', 'facility', 'ownerNation']);
        $this->assertSame('scorched', $cell->terrain->key);
        $this->assertSame($identity, $cell->only(array_keys($identity)));
        $this->assertSame($cellVersion + 1, $cell->version);
        $this->assertSame(1, $metrics['meaningful_impacts']);
        $this->assertSame(0, $metrics['ineffective_impacts']);
        $this->assertSame([$cell->id], $metrics['changed_cell_ids']);
        $this->assertSame([$cell->map_chunk_id], $context->state->changedMapChunkIds());
        $impact = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.impact')
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('land_scorched', $impact['effect']);
        $this->assertSame('wasteland', $impact['from_terrain_key']);
        $this->assertSame('scorched', $impact['to_terrain_key']);
        $this->assertTrue($impact['terrain_only']);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('factory', $detail['impacts'][0]['preserved_facility_key']);
        $this->assertSame(4_321, $detail['impacts'][0]['before_population']);
        $this->assertSame(4_321, $detail['impacts'][0]['after_population']);

        app(CompleteTurnEngine::class)->execute('aggregate_nations', $context);
        $this->assertSame(
            $chunkVersion + 1,
            (int) DB::table('map_chunks')->where('id', $cell->map_chunk_id)->value('version'),
        );
    }

    public function test_wasteland_scorch_transition_rejects_other_terrains_and_existing_scorched(): void
    {
        $world = $this->lightweightWorld();
        $space = $this->surfaceMapSpace($world);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->limit(4)->get();
        $this->assertCount(4, $cells);
        $state = app(MapCellStateService::class);
        $scorched = TerrainDefinition::query()->where('key', 'scorched')->firstOrFail();

        foreach (['plain', 'forest', 'sea', 'scorched'] as $index => $terrainKey) {
            $cell = $cells[$index];
            $state->transitionTerrain(
                $cell,
                TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail(),
            );
            $snapshot = $cell->only([
                'terrain_definition_id', 'terrain_quantity', 'facility_definition_id',
                'owner_nation_id', 'population', 'version',
            ]);

            $this->assertFalse($state->scorchWasteland($cell, $scorched), $terrainKey);
            $this->assertSame($snapshot, $cell->only(array_keys($snapshot)), $terrainKey);
            $this->assertSame($terrainKey, $cell->terrain->key);
        }

        $wasteland = $cells->firstOrFail();
        $state->transitionTerrain(
            $wasteland,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $snapshot = $wasteland->only([
            'terrain_definition_id', 'terrain_quantity', 'facility_definition_id',
            'owner_nation_id', 'population', 'version',
        ]);
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();

        $this->assertFalse($state->scorchWasteland($wasteland, $plain));
        $this->assertSame($snapshot, $wasteland->only(array_keys($snapshot)));
        $this->assertSame('wasteland', $wasteland->terrain->key);
    }

    public function test_existing_scorched_barren_land_is_an_ineffective_no_op(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->setFacility($cell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'scorched')->firstOrFail(),
        );
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $snapshot = $cell->fresh()->only([
            'terrain_definition_id', 'terrain_quantity', 'facility_definition_id',
            'owner_nation_id', 'population', 'version',
        ]);
        $chunkVersion = (int) DB::table('map_chunks')->where('id', $cell->map_chunk_id)->value('version');
        $item = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cell,
        );
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'existing scorched barren land'),
            [$firing->id, $target->id],
        );

        $metrics = $this->resolveMissile($context, $base);

        $this->assertSame($snapshot, $cell->fresh()->only(array_keys($snapshot)));
        $this->assertSame(0, $metrics['meaningful_impacts']);
        $this->assertSame(1, $metrics['ineffective_impacts']);
        $this->assertSame([], $metrics['changed_cell_ids']);
        $this->assertSame([], $context->state->changedMapChunkIds());
        $this->assertSame($chunkVersion, (int) DB::table('map_chunks')->where('id', $cell->map_chunk_id)->value('version'));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ineffective_barren_land', $detail['impacts'][0]['effect']);
    }

    public function test_monster_hit_scorches_wasteland_only_when_that_impact_kills_the_monster_once(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->setFacility($cell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $cell->population = 0;
        $cell->save();
        $monster = $this->monster($world, $cell);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);

        $first = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cell,
        );
        $firstMetrics = $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'nonlethal monster wasteland hit'), [$firing->id, $target->id]),
            $base,
        );

        $this->assertSame('alive', $monster->fresh()->state);
        $this->assertSame(1, $monster->fresh()->current_hp);
        $this->assertSame('wasteland', $cell->fresh()->terrain()->value('key'));
        $this->assertSame(1, $firstMetrics['meaningful_impacts']);
        $firstImpact = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.impact')
            ->orderBy('id')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($firstImpact['terrain_scorched']);

        $second = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cell,
        );
        $secondMetrics = $this->resolveMissile(
            $this->context($world, 3, hash('sha256', 'lethal monster wasteland hit'), [$firing->id, $target->id]),
            $base,
        );

        $this->assertSame('killed', $monster->fresh()->state);
        $this->assertFalse(MonsterOccupancy::query()->where('monster_instance_id', $monster->id)->exists());
        $this->assertSame('scorched', $cell->fresh()->terrain()->value('key'));
        $this->assertSame(1, $secondMetrics['meaningful_impacts']);
        $this->assertSame(0, $secondMetrics['ineffective_impacts']);
        $this->assertSame([$cell->id], $secondMetrics['changed_cell_ids']);
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.killed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.reward_distributed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.kill_stat_incremented')->count());
        $this->assertSame(1, DB::table('nation_monster_kill_stats')->where('nation_id', $firing->id)->value('kill_count'));
        $secondImpact = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.impact')
            ->orderByDesc('id')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($secondImpact['terrain_scorched']);
        $this->assertSame('monster_hit', $secondImpact['effect']);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $second->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('killed', $detail['impacts'][0]['effect']);
        $this->assertTrue($detail['impacts'][0]['terrain_scorched']);
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('completed', $second->fresh()->status);
    }

    public function test_monster_kill_on_non_wasteland_and_non_missile_removal_do_not_scorch(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $plain = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->setFacility($plain, null);
        app(MapCellStateService::class)->transitionTerrain(
            $plain,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        $plain->population = 0;
        $plain->save();
        $missileMonster = $this->monster($world, $plain);
        $missileMonster->update(['current_hp' => 1, 'spawned_max_hp' => 1]);
        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $plain);

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'monster killed on plain'), [$firing->id, $target->id]),
            $base,
        );

        $this->assertSame('killed', $missileMonster->fresh()->state);
        $this->assertSame('plain', $plain->fresh()->terrain()->value('key'));
        $impact = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.impact')
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($impact['terrain_scorched']);

        $wasteland = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereKeyNot($plain->id)->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->setFacility($wasteland, null);
        app(MapCellStateService::class)->transitionTerrain(
            $wasteland,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $wasteland->population = 0;
        $wasteland->save();
        $removed = $this->monster($world, $wasteland);
        $removedByTerrain = app(MonsterRemovalService::class)->removeAtCell(
            $this->context($world, 3, hash('sha256', 'non missile monster removal'), [$firing->id, $target->id]),
            $wasteland,
            'test_non_missile_removal',
        );

        $this->assertTrue($removedByTerrain);
        $this->assertSame('removed', $removed->fresh()->state);
        $this->assertSame('wasteland', $wasteland->fresh()->terrain()->value('key'));
    }

    public function test_wasteland_scorch_rolls_back_and_same_seed_retry_repeats_the_terrain_result(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->with(['terrain', 'facility'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'factory')->firstOrFail(),
        );
        $cell->population = 321;
        $cell->version++;
        $cell->save();
        $snapshot = $cell->fresh()->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version',
        ]);
        $chunkVersion = (int) DB::table('map_chunks')->where('id', $cell->map_chunk_id)->value('version');
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $cell);
        $seed = $this->seedForImpactIndex($item, $cell, 2, $cell);
        $rolledBackMetrics = null;

        try {
            DB::transaction(function () use (
                $world,
                $firing,
                $target,
                $base,
                $cell,
                $seed,
                &$rolledBackMetrics,
            ): void {
                $rolledBackMetrics = $this->resolveMissile(
                    $this->context($world, 2, $seed, [$firing->id, $target->id]),
                    $base,
                );
                $this->assertSame('scorched', $cell->fresh()->terrain()->value('key'));
                throw new RuntimeException('force wasteland scorch rollback');
            });
            $this->fail('The forced wasteland scorch rollback did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force wasteland scorch rollback', $exception->getMessage());
        }

        $this->assertIsArray($rolledBackMetrics);
        $this->assertSame($snapshot, $cell->fresh()->only(array_keys($snapshot)));
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $this->assertSame($chunkVersion, (int) DB::table('map_chunks')->where('id', $cell->map_chunk_id)->value('version'));

        $retryContext = $this->context($world, 2, $seed, [$firing->id, $target->id]);
        $retryMetrics = $this->resolveMissile($retryContext, $base);

        $this->assertSame($rolledBackMetrics, $retryMetrics);
        $this->assertSame('scorched', $cell->fresh()->terrain()->value('key'));
        $this->assertSame('factory', $cell->fresh()->facility()->value('key'));
        $this->assertSame($target->id, $cell->fresh()->owner_nation_id);
        $this->assertSame(321, $cell->fresh()->population);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        app(CompleteTurnEngine::class)->execute('aggregate_nations', $retryContext);
        $this->assertSame(
            $chunkVersion + 1,
            (int) DB::table('map_chunks')->where('id', $cell->map_chunk_id)->value('version'),
        );
    }

    public function test_multiple_minimum_capital_impacts_are_aggregated_once_per_launch(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $firing->update(['karma' => 0]);
        $target->update(['karma' => 20]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $base->update(['facility_experience' => 60]);
        $firing->update(['money' => 9_999]);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $minimum = $world->rulesetVersion()->firstOrFail()->settings['capital_minimum_population'];
        $this->assertIsInt($minimum);
        $capital->update(['population' => $minimum]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $capital,
            3,
        );

        $context = $this->context(
            $world,
            2,
            hash('sha256', 'three minimum Capital no-op impacts'),
            [$firing->id, $target->id],
        );
        $context->state->setKarmaStartSnapshot($firing->id, 0);
        $context->state->setKarmaStartSnapshot($target->id, 20);
        $metrics = $this->resolveMissile($context, $base);

        $this->assertSame(0, $metrics['meaningful_impacts']);
        $this->assertSame(3, $metrics['ineffective_impacts']);
        $this->assertSame([], $metrics['changed_cell_ids']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'karma.refugee_bonus')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')->count());
        $aggregate = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $aggregate['ineffective_impacts']);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(3, $detail['impacts']);
        foreach ($detail['impacts'] as $impact) {
            $this->assertSame($capital->x, $impact['x']);
            $this->assertSame($capital->y, $impact['y']);
            $this->assertSame('capital_at_minimum', $impact['effect']);
        }
    }

    #[DataProvider('ordinaryMissileKeys')]
    public function test_ordinary_missiles_hit_ship_before_seabed_base_and_follow_latest_layer(string $missileKey): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $target->update(['karma' => 20]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $base->update(['facility_experience' => 200]);
        $firing->update(['money' => 9_999]);
        $cell = $this->ownedWaterFacility($target, 'seabed_base');
        $cell->update(['facility_experience' => 123]);
        MapCell::query()->where('map_space_id', $space->id)
            ->whereHas('facility', fn ($query) => $query->where('key', 'defense'))
            ->with('facility')->get()->each(function (MapCell $defense): void {
                app(MapCellStateService::class)->setFacility($defense, null);
                $defense->save();
            });
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $target->id,
            'map_cell_id' => $cell->id,
            'ship_type_key' => 'tourist',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        $settings['military']['missiles'][$missileKey]['deviation_radius'] = 0;
        $ruleset->update(['settings' => $settings]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            $missileKey,
            $cell,
            3,
        );
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'Ship before seabed resistance '.$missileKey),
            [$firing->id, $target->id],
        );
        $context->state->setLifecycleNationIds([$firing->id, $target->id]);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(DomesticCommandExecutor::class)->execute($context);
        $karma->snapshotMissileBoundary($context);

        $metrics = $this->resolveMissile($context, $base);

        $cell = $cell->fresh(['terrain', 'facility']);
        $this->assertSame('sea', $cell->terrain->key);
        $this->assertSame('seabed_base', $cell->facility?->key);
        $this->assertSame($target->id, $cell->owner_nation_id);
        $this->assertSame(123, $cell->facility_experience);
        $this->assertSame(2, $metrics['meaningful_impacts']);
        $this->assertSame(1, $metrics['ineffective_impacts']);
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $this->assertSame([
            'state' => Ship::STATE_REMOVED,
            'current_hp' => 0,
            'map_cell_id' => null,
            'removal_reason' => 'missile',
        ], $ship->fresh()->only(['state', 'current_hp', 'map_cell_id', 'removal_reason']));
        $this->assertSame(1, $context->state->karmaLedgerForNation($firing->id)['crime_points']);
        $this->assertSame(2, $context->state->karmaLedgerForNation($target->id)['hostile_impacts_received']);
        $this->assertSame(40, $context->state->karmaLedgerForNation($firing->id)['alliance_money']);
        $allianceMetrics = $karma->settleAllianceMoney($context);
        $this->assertSame(40, $allianceMetrics['requested']);
        $this->assertSame(40, $allianceMetrics['applied']);
        $karmaMetrics = $karma->finalize($context);
        $this->assertSame(2, $karmaMetrics['victim_reductions']);
        $this->assertSame(18, $target->fresh()->karma);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['ship_damaged', 'ship_sunk', 'seabed_base_resisted'],
            array_column($detail['impacts'], 'effect'),
        );
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ship.missile_damaged')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ship.sunk')->count());
        $messages = collect(app(PlayerIslandEventService::class)->ownerPage($target->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '観光船がミサイル攻撃により')
                && str_contains($message, '損傷しました'),
        ));
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '観光船がミサイル攻撃により')
                && str_contains($message, '沈没しました'),
        ));
    }

    public function test_ordinary_missiles_still_destroy_other_owned_water_facilities(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = $this->ownedWaterFacility($target, 'seabed_oil_field');
        $cell->update(['population' => 321]);
        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cell);

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'ordinary water oil field'), [$firing->id, $target->id]),
            $base,
        );

        $cell = $cell->fresh(['terrain', 'facility']);
        $this->assertSame('sea', $cell->terrain->key);
        $this->assertNull($cell->facility_definition_id);
        $this->assertNull($cell->owner_nation_id);
        $this->assertSame(0, $cell->population);
    }

    public function test_land_destruction_neutralizes_a_destroyed_water_facility_but_preserves_its_terrain_contract(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $base->update(['facility_experience' => 50]);
        $firing->update(['money' => 9_999]);
        $cell = $this->ownedWaterFacility($target, 'seabed_base');
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $target->id,
            'map_cell_id' => $cell->id,
            'ship_type_key' => 'tourist',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        $settings['military']['missiles']['land_destruction_missile']['deviation_radius'] = 0;
        $ruleset->update(['settings' => $settings]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'land_destruction_missile',
            $cell,
            2,
        );
        $seed = hash('sha256', 'Ship before land destruction '.$item->id);

        $this->resolveMissile($this->context($world, 2, $seed, [$firing->id, $target->id]), $base);

        $this->assertSame([
            'state' => Ship::STATE_REMOVED,
            'current_hp' => 0,
            'map_cell_id' => null,
            'removal_reason' => 'missile',
        ], $ship->fresh()->only(['state', 'current_hp', 'map_cell_id', 'removal_reason']));
        $cell = $cell->fresh(['terrain', 'facility']);
        $this->assertSame('sea', $cell->terrain->key);
        $this->assertNull($cell->facility_definition_id);
        $this->assertNull($cell->owner_nation_id);
        $impact = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.impact')
            ->whereRaw("metadata->>'effect' = ?", ['terrain_destroyed'])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($target->id, $impact['nation_id']);
        $this->assertSame($target->name, $impact['target_nation_name']);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['ship_sunk', 'terrain_destroyed'], array_column($detail['impacts'], 'effect'));
    }

    public function test_land_destruction_neutralizes_facilityless_owned_shallow_water(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = $this->ownedWaterFacility($target, 'seabed_base');
        app(MapCellStateService::class)->setFacility($cell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'shallow')->firstOrFail(),
        );
        $cell->save();
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'land_destruction_missile',
            $cell,
        );
        $seed = $this->seedForImpactIndex($item, $cell, 2, $cell);

        $this->resolveMissile($this->context($world, 2, $seed, [$firing->id, $target->id]), $base);

        $cell = $cell->fresh(['terrain', 'facility']);
        $this->assertSame('sea', $cell->terrain->key);
        $this->assertNull($cell->facility_definition_id);
        $this->assertNull($cell->owner_nation_id);
    }

    public function test_seabed_base_levels_provide_one_two_and_three_launches(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $firing->update(['money' => 9_999]);
        $bases = [
            $this->ownedWaterFacility($firing, 'seabed_base', 0),
            $this->ownedWaterFacility($firing, 'seabed_base', 50),
            $this->ownedWaterFacility($firing, 'seabed_base', 200),
        ];
        $rules = app(MissileBaseRules::class);
        $definition = FacilityDefinition::query()->where('key', 'seabed_base')->firstOrFail();
        $this->assertSame([1, 2, 2, 3], array_map(
            fn (int $experience): int => $rules->launchCapacity($definition, $experience),
            [49, 50, 199, 200],
        ));
        $targetCell = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))->firstOrFail();
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $targetCell,
            6,
        );
        $context = $this->context($world, 2, hash('sha256', 'seabed capacities'), [$firing->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($context);

        $result = $this->processRegisteredMissiles($context, $bases);

        $this->assertSame(6, $result['shots_fired']);
        $this->assertSame(6, $result['finalize']['shots_fired']);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([1, 2, 3], array_column($detail['firing_bases'], 'fired_shots'));
    }

    public function test_seabed_base_gains_h2_plus_settlement_experience_and_owner_only_level_details(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->ownedWaterFacility($firing, 'seabed_base', 49);
        $settlement = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $settlement,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $settlement,
            FacilityDefinition::query()->where('key', 'town')->firstOrFail(),
        );
        $settlement->population = 2_000;
        $settlement->save();
        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $settlement);

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'seabed town experience'), [$firing->id, $target->id]),
            $base,
        );

        $base = $base->fresh(['terrain', 'facility', 'ownerNation']);
        $this->assertSame(50, $base->facility_experience);
        $owner = app(MapCellPresenter::class)->present($base, $firing->id, 2);
        $this->assertSame(
            [50, 2, 2],
            collect($owner['details'])->whereIn('key', [
                'facility_experience', 'facility_level', 'launch_capacity',
            ])->pluck('value')->all(),
        );
        $public = app(MapCellPresenter::class)->present($base, null, 2);
        $this->assertNull($public['facility']);
        $this->assertCount(1, $public['details']);
        $this->assertSame(
            'ペリドット海域',
            collect($public['details'])->firstWhere('key', 'sea_area')['value'] ?? null,
        );

        $landBase = $this->missileBase($firing);
        $landBase->update(['facility_experience' => 49]);
        $landSettlement = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereKeyNot($settlement->id)
            ->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $landSettlement,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $landSettlement,
            FacilityDefinition::query()->where('key', 'town')->firstOrFail(),
        );
        $landSettlement->population = 2_000;
        $landSettlement->save();
        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $landSettlement);

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'land-base town experience'), [$firing->id, $target->id]),
            $landBase->fresh(['terrain', 'facility']),
        );

        $this->assertSame(50, $landBase->fresh()->facility_experience);
    }

    public function test_seabed_settlement_experience_rolls_back_and_same_seed_retry_applies_once(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->ownedWaterFacility($firing, 'seabed_base', 49);
        $beforeVersion = $base->version;
        $settlement = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $settlement,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $settlement,
            FacilityDefinition::query()->where('key', 'town')->firstOrFail(),
        );
        $settlement->population = 2_000;
        $settlement->save();
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $settlement);
        $seed = hash('sha256', 'seabed experience deterministic retry');

        try {
            DB::transaction(function () use ($world, $firing, $target, $base, $seed): void {
                $this->resolveMissile(
                    $this->context($world, 2, $seed, [$firing->id, $target->id]),
                    $base,
                );
                throw new RuntimeException('force rollback after launch-base experience');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback after launch-base experience', $exception->getMessage());
        }

        $this->assertSame(49, $base->fresh()->facility_experience);
        $this->assertSame($beforeVersion, $base->fresh()->version);
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());

        $this->resolveMissile(
            $this->context($world, 2, $seed, [$firing->id, $target->id]),
            $base->fresh(['terrain', 'facility']),
        );
        $this->assertSame(50, $base->fresh()->facility_experience);
        $this->assertSame($beforeVersion + 1, $base->fresh()->version);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
    }

    public function test_capital_experience_uses_actual_loss_times_two_and_land_destruction_adds_none(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->ownedWaterFacility($firing, 'seabed_base', 0);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $capital->update(['population' => 10_000]);
        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $capital);

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'capital experience'), [$firing->id, $target->id]),
            $base,
        );

        $this->assertSame(9_000, $capital->fresh()->population);
        $this->assertSame(1, $base->fresh()->facility_experience);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'land_destruction_missile',
            $capital,
        );
        $seed = $this->seedForImpactIndex($item, $capital, 2, $capital);
        $this->resolveMissile($this->context($world, 3, $seed, [$firing->id, $target->id]), $base->fresh(['terrain', 'facility']));
        $this->assertSame(1, $base->fresh()->facility_experience);
    }

    public function test_two_seabed_bases_receive_only_their_actual_monster_damage_experience(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $baseA = $this->ownedWaterFacility($firing, 'seabed_base', 0);
        $baseB = $this->ownedWaterFacility($firing, 'seabed_base', 0);
        $host = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->firstOrFail();
        $monster = $this->monster($world, $host);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $host);

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'seabed monster damage'), [$firing->id, $target->id]),
            $baseA,
        );
        $experiencePerDamage = (int) $monster->definition()->value('experience_per_damage');
        $this->assertSame($experiencePerDamage, $baseA->fresh()->facility_experience);
        $this->assertSame(0, $baseB->fresh()->facility_experience);
        $this->assertSame('alive', $monster->fresh()->state);

        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $host);
        $this->resolveMissile(
            $this->context($world, 3, hash('sha256', 'seabed monster final blow'), [$firing->id, $target->id]),
            $baseB->fresh(['terrain', 'facility']),
        );
        $this->assertSame($experiencePerDamage, $baseA->fresh()->facility_experience);
        $this->assertSame($experiencePerDamage, $baseB->fresh()->facility_experience);
        $this->assertSame('killed', $monster->fresh()->state);
        $kill = json_decode((string) DB::table('audit_events')->where('event_type', 'monster.killed')
            ->where('subject_id', $monster->id)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $kill['actual_damage']);
        $this->assertSame($experiencePerDamage, $kill['firing_base_experience_requested']);
        $this->assertSame($experiencePerDamage, $kill['firing_base_experience_applied']);
    }

    public function test_underground_missile_capacity_uses_per_shot_source_attribution_and_canonical_secretary_monster_experience(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('underground-source');
        $space = $this->surfaceMapSpace($world);
        $undergroundBase = NationUndergroundFacility::query()->create([
            'nation_id' => $firing->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 1,
            'slot_index' => 0,
            'facility_key' => 'underground_missile_base',
        ]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($firingUser->secretary()->sole());
        $profile->refresh();
        $combatLevelBefore = $profile->combat_level;
        $combatXpBefore = $profile->combat_xp;
        $secretary = $firingUser->secretary()->sole();
        $secretaryExperienceBefore = $secretary->monster_experience;
        $host = $this->monsterArena($world, $target);
        $firstMonster = $this->monster($world, $host);
        $firstMonster->update(['current_hp' => 1, 'spawned_max_hp' => 1]);
        $experiencePerDamage = (int) $firstMonster->definition()->value('experience_per_damage');
        $firstItem = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $host,
        );
        $firstContext = $this->context(
            $world,
            2,
            hash('sha256', 'underground-only missile source'),
            [$firing->id, $target->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($firstContext, [$firing->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($firstContext);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $firstMetrics = $resolver->processUndergroundBasesForNation($firstContext, $space, $firing->id);
        $duplicateMetrics = $resolver->processUndergroundBasesForNation($firstContext, $space, $firing->id);
        $resolver->finalize($firstContext);
        app(SecretaryTurnService::class)->flushExperience($firstContext);

        $this->assertSame(1, $firstMetrics['shots_fired']);
        $this->assertSame(0, $duplicateMetrics['shots_fired']);
        $this->assertSame('completed', $firstItem->fresh()->status);
        $this->assertSame('killed', $firstMonster->fresh()->state);
        $this->assertSame(
            $secretaryExperienceBefore + $experiencePerDamage,
            $secretary->fresh()->monster_experience,
        );
        $firstKill = json_decode((string) DB::table('audit_events')->where('event_type', 'monster.killed')
            ->where('subject_id', $firstMonster->id)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('underground_missile_base', $firstKill['missile_source_kind']);
        $this->assertSame($undergroundBase->id, $firstKill['missile_source_id']);
        $this->assertSame(0, $firstKill['firing_base_experience_applied']);
        $this->assertSame($experiencePerDamage, $firstKill['secretary_monster_experience_awarded']);

        $surfaceBase = $this->missileBase($firing);
        $secondMonster = $this->monster($world, $host->fresh(['terrain', 'facility', 'ownerNation']));
        $secondMonster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $firing->update(['money' => 2_000]);
        $secondItem = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing->fresh(),
            $space,
            'spp_missile',
            $host->fresh(['terrain', 'facility', 'ownerNation']),
            2,
        );
        $secondContext = $this->context(
            $world,
            3,
            hash('sha256', 'mixed surface underground missile sources'),
            [$firing->id, $target->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($secondContext, [$firing->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($secondContext);
        $resolver->begin($this->missileCellIndex($world));
        $surfaceMetrics = $resolver->processBase($secondContext, $space, $surfaceBase);
        $undergroundMetrics = $resolver->processUndergroundBasesForNation($secondContext, $space, $firing->id);
        $resolver->finalize($secondContext);
        app(SecretaryTurnService::class)->flushExperience($secondContext);

        $this->assertSame([1, 1], [$surfaceMetrics['shots_fired'], $undergroundMetrics['shots_fired']]);
        $this->assertSame('completed', $secondItem->fresh()->status);
        $this->assertSame($experiencePerDamage, $surfaceBase->fresh()->facility_experience);
        $this->assertSame(
            $secretaryExperienceBefore + 2 * $experiencePerDamage,
            $secretary->fresh()->monster_experience,
        );
        $events = DB::table('audit_events')->whereIn('event_type', ['monster.damaged', 'monster.killed'])
            ->where('subject_id', $secondMonster->id)->orderBy('id')->get()
            ->map(static fn (object $event): array => json_decode(
                (string) $event->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ));
        $this->assertSame(['surface_missile_base', 'underground_missile_base'], $events->pluck('missile_source_kind')->all());
        $this->assertSame([$surfaceBase->id, $undergroundBase->id], $events->pluck('missile_source_id')->all());
        $this->assertSame([$experiencePerDamage, 0], $events->pluck('firing_base_experience_applied')->all());
        $this->assertSame([0, $experiencePerDamage], $events->pluck('secretary_monster_experience_awarded')->all());
        $this->assertSame($combatLevelBefore, $profile->fresh()->combat_level);
        $this->assertSame($combatXpBefore, $profile->fresh()->combat_xp);
    }

    public function test_underground_missile_bases_follow_their_capital_cell_anchor_and_stable_slot_order(): void
    {
        $world = $this->lightweightWorld();
        [$undergroundUser, $undergroundNation] = $this->nation($world, '地下発射国');
        [$surfaceUser, $surfaceNation] = $this->nation($world, '地上発射国');
        [, $targetNation] = $this->nation($world, '共同標的国');
        $undergroundNation->update(['money' => 2_000]);
        $surfaceNation->update(['money' => 1_000]);
        $space = $this->surfaceMapSpace($world);
        $surfaceBase = $this->missileBase($surfaceNation);
        $laterUndergroundBase = NationUndergroundFacility::query()->create([
            'nation_id' => $undergroundNation->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 2,
            'slot_index' => 0,
            'facility_key' => 'underground_missile_base',
        ]);
        $earlierUndergroundBase = NationUndergroundFacility::query()->create([
            'nation_id' => $undergroundNation->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 1,
            'slot_index' => 3,
            'facility_key' => 'underground_missile_base',
        ]);
        $host = $this->monsterArena($world, $targetNation);
        $monster = $this->monster($world, $host, 'red_inora');
        $undergroundItem = $this->queue(
            app(CommandQueueService::class),
            $undergroundUser,
            $undergroundNation,
            $space,
            'spp_missile',
            $host,
            2,
        );
        $surfaceItem = $this->queue(
            app(CommandQueueService::class),
            $surfaceUser,
            $surfaceNation,
            $space,
            'spp_missile',
            $host,
        );
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'capital anchored underground missile order'),
            [$undergroundNation->id, $surfaceNation->id, $targetNation->id],
        );
        $undergroundCapital = MapCell::query()
            ->whereKey($undergroundNation->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])
            ->firstOrFail();

        $this->executeCellResolution($context, $undergroundCapital, $surfaceBase, $host);

        $this->assertSame('completed', $undergroundItem->fresh()->status);
        $this->assertSame('completed', $surfaceItem->fresh()->status);
        $this->assertSame('killed', $monster->fresh()->state);
        $events = DB::table('audit_events')->whereIn('event_type', ['monster.damaged', 'monster.killed'])
            ->where('subject_id', $monster->id)->orderBy('id')->get()
            ->map(static fn (object $event): array => json_decode(
                (string) $event->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ));
        $this->assertSame(
            ['underground_missile_base', 'underground_missile_base', 'surface_missile_base'],
            $events->pluck('missile_source_kind')->all(),
        );
        $this->assertSame(
            [$earlierUndergroundBase->id, $laterUndergroundBase->id, $surfaceBase->id],
            $events->pluck('missile_source_id')->all(),
        );
    }

    public function test_water_ownership_cleanup_does_not_affect_land_facilities_or_empty_owned_water(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $land = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $land,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $land,
            FacilityDefinition::query()->where('key', 'farm')->firstOrFail(),
            FacilityDefinition::query()->where('key', 'farm')->value('initial_scale'),
        );
        $land->save();
        $landItem = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $land);
        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'land ownership retained'), [$firing->id, $target->id]),
            $base,
        );
        $this->assertSame($target->id, $land->fresh()->owner_nation_id);
        $this->assertSame('completed', $landItem->fresh()->status);

        $emptyWater = $this->ownedWaterFacility($target, 'seabed_base');
        app(MapCellStateService::class)->setFacility($emptyWater, null);
        $emptyWater->save();
        $emptyItem = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $emptyWater);
        $metrics = $this->resolveMissile(
            $this->context($world, 3, hash('sha256', 'empty owned water ineffective'), [$firing->id, $target->id]),
            $base,
        );
        $this->assertSame($target->id, $emptyWater->fresh()->owner_nation_id);
        $this->assertSame(0, $metrics['meaningful_impacts']);
        $this->assertSame(1, $metrics['ineffective_impacts']);
        $this->assertSame('completed', $emptyItem->fresh()->status);
    }

    public function test_water_facility_ownership_cleanup_rolls_back_atomically_and_retries_once(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = $this->ownedWaterFacility($target, 'seabed_oil_field');
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cell);

        try {
            DB::transaction(function () use ($world, $firing, $target, $base): void {
                $this->resolveMissile(
                    $this->context($world, 2, hash('sha256', 'rolled back water cleanup'), [$firing->id, $target->id]),
                    $base,
                );
                throw new RuntimeException('force rollback');
            });
            $this->fail('The forced rollback did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame($target->id, $cell->fresh()->owner_nation_id);
        $this->assertSame('seabed_oil_field', $cell->fresh()->facility()->value('key'));
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'retried water cleanup'), [$firing->id, $target->id]),
            $base,
        );
        $this->assertNull($cell->fresh()->owner_nation_id);
        $this->assertNull($cell->fresh()->facility_definition_id);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
    }

    public function test_multi_shot_launch_keeps_individual_refugee_events_and_aggregates_player_logs(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $base->update(['facility_experience' => 60]);
        $firing->update(['money' => 9_999]);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $capital,
            3,
        );

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'three exact SPP impacts'), [$firing->id, $target->id]),
            $base,
        );

        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $detail['fired_shots']);
        $this->assertSame(1_500, $detail['cost_money']);
        $this->assertCount(3, $detail['impacts']);
        $this->assertSame(8_499, $firing->fresh()->money);

        $generated = DB::table('audit_events')->where('event_type', 'refugee_generated')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id]);
        $received = DB::table('audit_events')->where('event_type', 'refugee_received')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id]);
        $this->assertSame(3, $generated->count());
        $this->assertSame(3, $received->count());
        $generatedTotal = (int) $generated->sum(DB::raw("(metadata->>'generated_population')::integer"));
        $receivedTotal = (int) $received->sum(DB::raw("(metadata->>'received_population')::integer"));

        $targetEvents = collect(app(PlayerIslandEventService::class)->publicNationPage($target, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $firingEvents = collect(app(PlayerIslandEventService::class)->ownerPage($firing, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertCount(1, $targetEvents->where('type', 'refugee_generated'));
        $this->assertCount(1, $firingEvents->where('type', 'refugee_received'));
        $this->assertFalse($targetEvents->where('type', 'refugee_generated')->contains(
            fn (array $event): bool => str_contains($event['message'], number_format($generatedTotal).'人'),
        ));
        $this->assertTrue($firingEvents->where('type', 'refugee_received')->contains(
            fn (array $event): bool => str_contains($event['message'], number_format($receivedTotal).'人'),
        ));
    }

    public function test_land_destruction_removes_monster_and_land_without_refugees_or_rewards(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->firstOrFail();
        app(MapCellStateService::class)->setFacility($cell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        $cell->population = 700;
        $cell->save();
        $monster = $this->monster($world, $cell);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'land_destruction_missile',
            $cell,
        );
        $seed = $this->seedForImpactIndex($item, $cell, 2, $cell);

        $this->resolveMissile($this->context($world, 2, $seed, [$firing->id, $target->id]), $base);

        $this->assertSame('removed', $monster->fresh()->state);
        $this->assertFalse(MonsterOccupancy::query()->where('monster_instance_id', $monster->id)->exists());
        $this->assertSame('shallow', $cell->fresh()->terrain()->value('key'));
        $this->assertNull($cell->fresh()->owner_nation_id);
        $this->assertSame(0, $cell->fresh()->population);
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', ['refugee_generated', 'refugee_received'])->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'monster.reward_distributed')->count());
    }

    public function test_missile_impact_at_the_dormant_capital_radius_is_a_logged_complete_noop(): void
    {
        [$world, $firingUser, $firing] = $this->combatants();
        [, $dormant] = $this->nation($world, '休眠標的国');
        $dormant->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $dormant->capital()->firstOrFail();
        $aim = $capital->cell()->firstOrFail();
        $capitalCoordinate = new GridCoordinate($aim->x, $aim->y);
        $coordinate = collect((new GridCoordinate($aim->x, $aim->y))->radius(2))
            ->first(fn (GridCoordinate $candidate): bool => $candidate->x >= $space->min_x
                && $candidate->x <= $space->max_x && $candidate->y >= $space->min_y
                && $candidate->y <= $space->max_y && $capitalCoordinate->distanceTo($candidate) === 2);
        if (! $coordinate instanceof GridCoordinate) {
            $this->fail('No in-bounds deviation coordinate was available for the dormant target test.');
        }
        $dormantCell = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $dormantCell,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $dormantCell,
            FacilityDefinition::query()->where('key', 'city')->firstOrFail(),
        );
        $dormantCell->owner_nation_id = $firing->id;
        $dormantCell->population = 777;
        $dormantCell->version++;
        $dormantCell->save();
        $monster = $this->monster($world, $dormantCell);
        $snapshot = $dormantCell->fresh()->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version',
        ]);
        $firing->update([
            'state' => 'recovery',
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
        ]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing->fresh(),
            $space,
            'missile',
            $dormantCell,
        );
        $seed = $this->seedForImpactIndex($item, $dormantCell, 2, $dormantCell);
        $context = $this->context($world, 2, $seed, [$firing->id, $dormant->id]);
        app(NationLifecycleService::class)->prepare($context);

        $this->assertSame(
            $firing->id,
            $context->state->recoveryTerritoryNationId($dormantCell->x, $dormantCell->y),
        );

        $this->resolveMissile($context, $base);

        $this->assertSame($snapshot, $dormantCell->fresh()->only(array_keys($snapshot)));
        $this->assertTrue(MonsterOccupancy::query()->where('monster_instance_id', $monster->id)
            ->where('map_cell_id', $dormantCell->id)->exists());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')->count());
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('dormant_capital_protected', $detail['impacts'][0]['effect']);
        $this->assertSame($dormant->id, $detail['impacts'][0]['protected_nation_id']);
        $this->assertSame(
            "{$dormant->name}({$dormantCell->x},{$dormantCell->y})にミサイルが落下しましたが、まるで時間が止まったかのように動かなくなった後、空中で自爆しました",
            DB::table('audit_events')->where('event_type', 'missile.dormancy_protected')->value('message'),
        );

        MonsterOccupancy::query()->where('monster_instance_id', $monster->id)->delete();
        app(MapCellStateService::class)->setFacility($dormantCell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $dormantCell,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        $dormantCell->owner_nation_id = null;
        $dormantCell->population = 0;
        $dormantCell->version++;
        $dormantCell->save();
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $dormant->id,
            'map_cell_id' => $dormantCell->id,
            'ship_type_key' => 'tourist',
            'current_hp' => 2,
            'max_hp' => 2,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        $settings['military']['missiles']['missile']['deviation_radius'] = 0;
        $ruleset->update(['settings' => $settings]);
        $firing->update([
            'state' => 'active',
            'state_reason' => null,
            'state_started_turn' => null,
            'resume_at_turn' => null,
        ]);
        $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing->fresh(),
            $space,
            'missile',
            $dormantCell->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $shipContext = $this->context($world, 3, hash('sha256', 'dormant Ship remains targetable'), [
            $firing->id,
            $dormant->id,
        ]);
        app(NationLifecycleService::class)->prepare($shipContext);

        $this->resolveMissile($shipContext, $base);

        $this->assertSame(Ship::STATE_ACTIVE, $ship->fresh()->state);
        $this->assertSame(1, $ship->fresh()->current_hp);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ship.missile_damaged')->count());
    }

    public function test_pp_deviation_stays_within_one_hex_and_out_of_bounds_is_treated_as_sea(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $aim = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $pp = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'pp_missile', $aim);
        $ppSeed = $this->seedForDrawIndex($pp, count((new GridCoordinate($aim->x, $aim->y))->radius(1)), 0);
        $this->resolveMissile($this->context($world, 2, $ppSeed, [$firing->id, $target->id]), $base);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $pp->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $impact = $detail['impacts'][0];
        $this->assertLessThanOrEqual(1, (new GridCoordinate($aim->x, $aim->y))->distanceTo(
            new GridCoordinate($impact['x'], $impact['y']),
        ));

        $edge = MapCell::query()->where('map_space_id', $space->id)->where('x', 0)->where('y', 0)->firstOrFail();
        $edge->update(['owner_nation_id' => $target->id]);
        $normal = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $edge);
        $candidates = (new GridCoordinate(0, 0))->radius(2);
        $outIndex = array_key_first(array_filter(
            $candidates,
            fn (GridCoordinate $candidate): bool => $candidate->x < $space->min_x || $candidate->y < $space->min_y,
        ));
        if (! is_int($outIndex)) {
            $this->fail('No out-of-bounds missile candidate was available at the map corner.');
        }
        $seed = $this->seedForDrawIndex($normal, count($candidates), $outIndex);
        $this->resolveMissile($this->context($world, 3, $seed, [$firing->id, $target->id]), $base);
        $normalDetail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $normal->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('out_of_bounds_sea', $normalDetail['impacts'][0]['effect']);
    }
}
