<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\MissileImpactResolver;
use App\Application\MonsterRemovalService;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Application\SecretaryTurnService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Facility\MissileBaseRules;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use App\Services\MapCellPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class CommandAndMissileTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_v6_spp_direct_hit_preserves_only_real_defense_and_other_missiles_keep_existing_damage(): void
    {
        [$world, $firingUser, $firing, $targetNation] = $this->combatants();
        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $target = MapCell::query()->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $target,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $target,
            FacilityDefinition::query()->where('key', 'defense')->firstOrFail(),
        );
        $target->population = 0;
        $target->save();
        $snapshot = $target->fresh()->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version',
        ]);

        $defenseItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $target,
        );
        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'v6 spp defense'), [$firing->id, $targetNation->id]),
            $base,
        );
        $this->assertSame($snapshot, $target->fresh()->only(array_keys($snapshot)));
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $defenseItem->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('defense_resisted', $detail['impacts'][0]['effect']);

        app(MapCellStateService::class)->setFacility(
            $target,
            FacilityDefinition::query()->where('key', 'decoy')->firstOrFail(),
        );
        $target->version++;
        $target->save();
        $decoyItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $target,
        );
        $this->resolveMissile(
            $this->context($world, 3, hash('sha256', 'v6 spp decoy'), [$firing->id, $targetNation->id]),
            $base->fresh(['terrain', 'facility']),
        );
        $this->assertNull($target->fresh()->facility_definition_id);
        $decoyDetail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $decoyItem->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame('defense_resisted', $decoyDetail['impacts'][0]['effect']);

        foreach ([
            ['key' => 'missile', 'radius' => 2],
            ['key' => 'pp_missile', 'radius' => 1],
            ['key' => 'land_destruction_missile', 'radius' => 2],
        ] as $index => $case) {
            $target = $target->fresh(['terrain', 'facility', 'ownerNation']);
            app(MapCellStateService::class)->transitionTerrain(
                $target,
                TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
            );
            app(MapCellStateService::class)->setFacility(
                $target,
                FacilityDefinition::query()->where('key', 'defense')->firstOrFail(),
            );
            $target->owner_nation_id = $targetNation->id;
            $target->population = 0;
            $target->version++;
            $target->save();
            $item = $this->queue(
                app(CommandQueueService::class),
                $firingUser,
                $firing,
                $space,
                $case['key'],
                $target->fresh(['terrain', 'facility', 'ownerNation']),
            );
            $seed = $this->seedForImpactIndex($item, $target, $case['radius'], $target);
            $this->resolveMissile(
                $this->context($world, 4 + $index, $seed, [$firing->id, $targetNation->id]),
                $base->fresh(['terrain', 'facility']),
            );
            $this->assertNull(
                $target->fresh()->facility_definition_id,
                "{$case['key']} must retain its pre-v6 defense damage contract.",
            );
        }
    }

    public function test_secretary_final_defense_budget_is_attempt_scoped_and_arrival_xp_is_independent(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $base->update(['facility_experience' => 60]);
        $firing->update(['money' => 9_999]);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $firstItem = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $capital,
            2,
        );
        $first = $this->context(
            $world,
            2,
            hash('sha256', 'Secretary two-shot budget'),
            [$firing->id, $target->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($first, [$firing->id, $target->id]);

        $this->resolveMissile($first, $base);

        $firstDetail = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $firstItem->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['secretary_intercepted', 'capital_damaged'],
            array_column($firstDetail['impacts'], 'effect'),
        );
        $this->assertSame(1, $first->state->finalDefenseInterceptionsUsed($target->id));
        $this->assertSame([
            $target->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 2],
        ], $first->state->pendingSecretaryExperience());
        $firstInterception = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'secretary.missile_intercepted')->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('秘書', $firstInterception['secretary_label']);

        $targetUserId = (int) DB::table('nation_memberships')->where('nation_id', $target->id)
            ->where('role', 'owner')->value('user_id');
        DB::table('secretaries')->where('user_id', $targetUserId)->update([
            'name' => 'ペリドット',
            'named_at' => now(),
            'updated_at' => now(),
        ]);
        $secondItem = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $capital->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $second = $this->context(
            $world,
            3,
            hash('sha256', 'Secretary next-turn budget'),
            [$firing->id, $target->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($second, [$firing->id, $target->id]);

        $this->resolveMissile($second, $base->fresh(['terrain', 'facility']));

        $secondDetail = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $secondItem->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('secretary_intercepted', $secondDetail['impacts'][0]['effect']);
        $this->assertSame(1, $second->state->finalDefenseInterceptionsUsed($target->id));
        $this->assertSame([
            $target->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 1],
        ], $second->state->pendingSecretaryExperience());
        $labels = DB::table('audit_events')->where('event_type', 'secretary.missile_intercepted')
            ->orderBy('id')->selectRaw("metadata->>'secretary_label' AS secretary_label")
            ->pluck('secretary_label')->all();
        $this->assertSame(['秘書', '秘書のペリドット'], $labels);
        $messages = collect(app(PlayerIslandEventService::class)->ownerPage($target->fresh(), 1, 3)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->where('type', 'secretary.missile_intercepted')->pluck('message');
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '秘書がSPPミサイルを最終防衛ラインで迎撃'),
        ));
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '秘書のペリドットがSPPミサイルを最終防衛ラインで迎撃'),
        ));
    }

    public function test_ordinary_defense_resolves_before_secretary_but_still_awards_arrival_xp(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'defense')->firstOrFail(),
        );
        $cell->population = 0;
        $cell->save();
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $cell->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'ordinary defense before Secretary'),
            [$firing->id, $target->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$firing->id, $target->id]);

        $this->resolveMissile($context, $base);

        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('defense_resisted', $detail['impacts'][0]['effect']);
        $this->assertSame(0, $context->state->finalDefenseInterceptionsUsed($target->id));
        $this->assertSame([
            $target->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 1],
        ], $context->state->pendingSecretaryExperience());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'secretary.missile_intercepted')->count());
    }

    public function test_self_fired_collateral_and_monster_cells_both_award_xp_but_only_eligible_cell_is_intercepted(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $firing->update(['money' => 9_999]);
        $ownCell = MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereKeyNot($base->id)->whereNull('facility_definition_id')
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $ownItem = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $ownCell,
        );
        $selfContext = $this->context(
            $world,
            2,
            hash('sha256', 'Secretary self collateral'),
            [$firing->id, $target->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($selfContext, [$firing->id, $target->id]);

        $this->resolveMissile($selfContext, $base);

        $ownDetail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $ownItem->id])->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('secretary_intercepted', $ownDetail['impacts'][0]['effect']);
        $this->assertSame([
            $firing->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 1],
        ], $selfContext->state->pendingSecretaryExperience());

        $monsterCell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $monster = $this->monster($world, $monsterCell);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $monsterItem = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'spp_missile',
            $monsterCell,
        );
        $monsterContext = $this->context(
            $world,
            3,
            hash('sha256', 'Secretary monster exclusion'),
            [$firing->id, $target->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($monsterContext, [$firing->id, $target->id]);

        $this->resolveMissile($monsterContext, $base->fresh(['terrain', 'facility']));

        $monsterDetail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $monsterItem->id])->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('damaged', $monsterDetail['impacts'][0]['effect']);
        $this->assertSame(1, $monster->fresh()->current_hp);
        $this->assertSame(0, $monsterContext->state->finalDefenseInterceptionsUsed($target->id));
        $this->assertSame([
            $target->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 1],
        ], $monsterContext->state->pendingSecretaryExperience());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'secretary.missile_intercepted')->count());
    }

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
        $this->assertSame(101, $nation->fresh()->idle_counter);
        $this->assertSame('queued', $logging->fresh()->status);

        $second = app(DomesticCommandExecutor::class)->execute($this->context($world, 3, str_repeat('2', 64), [$nation->id]));
        $this->assertSame(1, $second['finance_commands']);
        $this->assertSame(1, $second['idle_counter_increments']);
        $this->assertSame(102, $nation->fresh()->idle_counter);

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

    public function test_destroyed_base_zero_shot_missile_keeps_idle_counter_without_automatic_finance(): void
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
    }

    public function test_insufficient_funds_at_base_processing_keeps_idle_counter_for_zero_shots(): void
    {
        [$world, $user, $firing, $target] = $this->combatants();
        $firing->update(['idle_counter' => 3]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $capital = $target->capital()->firstOrFail()->cell()->firstOrFail();
        $this->queue(app(CommandQueueService::class), $user, $firing, $space, 'spp_missile', $capital);
        $context = $this->context($world, 2, hash('sha256', 'missile funds exhausted'), [$firing->id]);

        app(DomesticCommandExecutor::class)->execute($context);
        $firing->update(['money' => 0]);
        $result = $this->processRegisteredMissiles($context, [$base]);

        $this->assertSame(0, $result['shots_fired']);
        $this->assertSame(3, $firing->fresh()->idle_counter);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.launch_failed')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')
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
        $this->assertSame('hakoniwa-2s-plus-v7', $world->rulesetVersion()->value('key'));
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

    public function test_zero_shot_missile_after_failed_normal_command_does_not_double_update_idle_counter(): void
    {
        [$world, $user, $firing, $target] = $this->combatants();
        $firing->update(['idle_counter' => 5]);
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
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.automatic_finance')->count());
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

    public function test_normal_missile_at_minimum_capital_is_private_no_op_and_publicly_aggregated(): void
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

    public function test_land_destruction_missile_at_minimum_capital_is_a_complete_no_op(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
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
        $aggregate = DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')->firstOrFail();
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

    public function test_multiple_minimum_capital_impacts_are_aggregated_once_per_launch(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
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

        $metrics = $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'three minimum Capital no-op impacts'), [$firing->id, $target->id]),
            $base,
        );

        $this->assertSame(0, $metrics['meaningful_impacts']);
        $this->assertSame(3, $metrics['ineffective_impacts']);
        $this->assertSame([], $metrics['changed_cell_ids']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
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
    public function test_ordinary_missiles_are_ineffective_against_seabed_base(string $missileKey): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = $this->ownedWaterFacility($target, 'seabed_base');
        $cell->update(['facility_experience' => 123]);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        $settings['military']['missiles'][$missileKey]['deviation_radius'] = 0;
        $ruleset->update(['settings' => $settings]);
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, $missileKey, $cell);

        $metrics = $this->resolveMissile(
            $this->context(
                $world,
                2,
                hash('sha256', 'seabed resistance '.$missileKey),
                [$firing->id, $target->id],
            ),
            $base,
        );

        $cell = $cell->fresh(['terrain', 'facility']);
        $this->assertSame('sea', $cell->terrain->key);
        $this->assertSame('seabed_base', $cell->facility?->key);
        $this->assertSame($target->id, $cell->owner_nation_id);
        $this->assertSame(123, $cell->facility_experience);
        $this->assertSame(0, $metrics['meaningful_impacts']);
        $this->assertSame(1, $metrics['ineffective_impacts']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.impact')->count());
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('seabed_base_resisted', $detail['impacts'][0]['effect']);
    }

    public static function ordinaryMissileKeys(): array
    {
        return [
            'normal' => ['missile'],
            'PP' => ['pp_missile'],
            'SPP' => ['spp_missile'],
        ];
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
        $cell = $this->ownedWaterFacility($target, 'seabed_base');
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
        $impact = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.impact')
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($target->id, $impact['nation_id']);
        $this->assertSame($target->name, $impact['target_nation_name']);
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
    }

    public function test_land_missile_base_also_gains_h2_plus_settlement_experience(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $base->update(['facility_experience' => 49]);
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
            $this->context($world, 2, hash('sha256', 'land-base town experience'), [$firing->id, $target->id]),
            $base->fresh(['terrain', 'facility']),
        );

        $this->assertSame(50, $base->fresh()->facility_experience);
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

    public function test_seabed_base_monster_experience_is_final_blow_only(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants();
        $space = $this->surfaceMapSpace($world);
        $base = $this->ownedWaterFacility($firing, 'seabed_base', 0);
        $host = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))->firstOrFail();
        $monster = $this->monster($world, $host);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $host);

        $this->resolveMissile(
            $this->context($world, 2, hash('sha256', 'seabed monster damage'), [$firing->id, $target->id]),
            $base,
        );
        $this->assertSame(0, $base->fresh()->facility_experience);

        $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $host);
        $this->resolveMissile(
            $this->context($world, 3, hash('sha256', 'seabed monster final blow'), [$firing->id, $target->id]),
            $base->fresh(['terrain', 'facility']),
        );
        $this->assertSame(
            $monster->definition()->value('missile_base_experience'),
            $base->fresh()->facility_experience,
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
        $this->assertSame(0, $cell->fresh()->population);
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', ['refugee_generated', 'refugee_received'])->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'monster.reward_distributed')->count());
    }

    public function test_deviation_to_dormant_owned_cell_preserves_every_state_and_is_aggregated_as_ineffective(): void
    {
        [$world, $firingUser, $firing, $activeTarget] = $this->combatants();
        [, $dormant] = $this->nation($world, '休眠標的国');
        $dormant->update(['state' => 'dormant_frozen']);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $aim = $activeTarget->capital()->firstOrFail()->cell()->firstOrFail();
        $coordinate = collect((new GridCoordinate($aim->x, $aim->y))->radius(2))
            ->first(fn (GridCoordinate $candidate): bool => $candidate->x >= $space->min_x
                && $candidate->x <= $space->max_x && $candidate->y >= $space->min_y
                && $candidate->y <= $space->max_y && ($candidate->x !== $aim->x || $candidate->y !== $aim->y));
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
        $dormantCell->owner_nation_id = $dormant->id;
        $dormantCell->population = 777;
        $dormantCell->version++;
        $dormantCell->save();
        $monster = $this->monster($world, $dormantCell);
        $snapshot = $dormantCell->fresh()->only([
            'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version',
        ]);
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $aim);
        $seed = $this->seedForImpactIndex($item, $aim, 2, $dormantCell);

        $this->resolveMissile($this->context($world, 2, $seed, [$firing->id, $activeTarget->id]), $base);

        $this->assertSame($snapshot, $dormantCell->fresh()->only(array_keys($snapshot)));
        $this->assertTrue(MonsterOccupancy::query()->where('monster_instance_id', $monster->id)
            ->where('map_cell_id', $dormantCell->id)->exists());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')->count());
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('dormant_owner_protected', $detail['impacts'][0]['effect']);
    }

    public function test_v2_direct_dormant_and_sunken_targets_are_selectable_but_complete_no_ops(): void
    {
        $world = $this->lightweightWorld();
        [$user, $firing] = $this->nation($world, '休眠直接発射国');
        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);

        foreach (['dormant_frozen', 'dormant_contestable', 'sunken_archived'] as $index => $state) {
            [, $targetNation] = $this->nation($world, "休眠直接標的{$index}");
            $target = MapCell::query()->where('owner_nation_id', $targetNation->id)
                ->whereKeyNot($targetNation->capital()->value('map_cell_id'))->firstOrFail();
            app(MapCellStateService::class)->transitionTerrain(
                $target,
                TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
            );
            app(MapCellStateService::class)->setFacility(
                $target,
                FacilityDefinition::query()->where('key', 'city')->firstOrFail(),
            );
            $target->population = 777;
            $target->version++;
            $target->save();
            $targetNation->update(['state' => $state]);
            $monster = $this->monster($world, $target);
            $snapshot = $target->fresh()->only([
                'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population',
                'facility_scale', 'facility_experience', 'facility_operational_state', 'version',
            ]);
            $item = $this->queue(
                app(CommandQueueService::class),
                $user,
                $firing,
                $space,
                'spp_missile',
                $target,
            );

            $this->resolveMissile($this->context(
                $world,
                $index + 2,
                hash('sha256', "v2-direct-protected:{$state}"),
                [$firing->id, $targetNation->id],
            ), $base);

            $this->assertSame('completed', $item->fresh()->status);
            $this->assertSame($snapshot, $target->fresh()->only(array_keys($snapshot)));
            $this->assertTrue(MonsterOccupancy::query()->where('monster_instance_id', $monster->id)
                ->where('map_cell_id', $target->id)->exists());
            $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
                ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('dormant_owner_protected', $detail['impacts'][0]['effect']);
            $this->assertSame($state, $detail['impacts'][0]['owner_state']);
        }
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

    public function test_remaining_cell_commands_apply_their_audited_effects_and_exact_costs(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '残存開発国');
        $space = $this->surfaceMapSpace($world);
        $capital = $nation->capital()->firstOrFail()->cell()->firstOrFail();
        $owned = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capital->id)->orderBy('id')->limit(6)->get();
        $this->assertCount(6, $owned);
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        foreach ($owned as $cell) {
            app(MapCellStateService::class)->setFacility($cell, null);
            app(MapCellStateService::class)->transitionTerrain($cell, $plain);
            $cell->population = 0;
            $cell->save();
        }
        $cityTarget = $owned[5];
        app(MapCellStateService::class)->setFacility(
            $cityTarget,
            FacilityDefinition::query()->where('key', 'city')->firstOrFail(),
        );
        $cityTarget->population = 12_345;
        $cityTarget->save();
        $oldCapitalPopulation = $capital->population;

        [$territoryTarget, $seabedTarget] = $this->neutralCellsNearTerritory($nation, $space, 2);
        app(MapCellStateService::class)->setFacility($territoryTarget, null);
        app(MapCellStateService::class)->transitionTerrain(
            $territoryTarget,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility($seabedTarget, null);
        app(MapCellStateService::class)->transitionTerrain(
            $seabedTarget,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        foreach ([$territoryTarget, $seabedTarget] as $cell) {
            $cell->owner_nation_id = null;
            $cell->population = 0;
            $cell->save();
        }

        $service = app(CommandQueueService::class);
        $items = [
            $this->queue($service, $user, $nation, $space, 'territory_expand', $territoryTarget),
            $this->queue($service, $user, $nation, $space, 'plant_forest', $owned[0]),
            $this->queue($service, $user, $nation, $space, 'build_missile_base', $owned[1]),
            $this->queue($service, $user, $nation, $space, 'build_defense_facility', $owned[2]),
            $this->queue($service, $user, $nation, $space, 'build_seabed_base', $seabedTarget),
            $this->queue($service, $user, $nation, $space, 'build_monument', $owned[3]),
            $this->queue($service, $user, $nation, $space, 'build_decoy', $owned[4]),
            $this->queue($service, $user, $nation, $space, 'relocate_capital', $cityTarget),
        ];
        $costs = [100, 50, 300, 800, 8_000, 9_999, 1, 1_000];
        foreach ($costs as $index => $cost) {
            Nation::query()->whereKey($nation->id)->update(['money' => 9_999]);
            app(DomesticCommandExecutor::class)->execute($this->context(
                $world,
                $index + 2,
                hash('sha256', "remaining-cell-command:{$index}"),
                [$nation->id],
            ));
            $this->assertSame(9_999 - $cost, $nation->fresh()->money);
            $this->assertSame('completed', $items[$index]->fresh()->status);
        }

        $this->assertSame($nation->id, $territoryTarget->fresh()->owner_nation_id);
        $this->assertSame('forest', $owned[0]->fresh()->terrain()->value('key'));
        $this->assertSame('missile_base', $owned[1]->fresh()->facility()->value('key'));
        $this->assertSame('defense', $owned[2]->fresh()->facility()->value('key'));
        $this->assertSame('seabed_base', $seabedTarget->fresh()->facility()->value('key'));
        $this->assertSame($nation->id, $seabedTarget->fresh()->owner_nation_id);
        $this->assertSame('monument', $owned[3]->fresh()->facility()->value('key'));
        $this->assertSame('peace', $owned[3]->fresh()->monumentDefinition()->value('key'));
        $this->assertSame('decoy', $owned[4]->fresh()->facility()->value('key'));
        $this->assertSame('capital', $cityTarget->fresh()->facility()->value('key'));
        $this->assertSame(12_345, $cityTarget->fresh()->population);
        $this->assertSame('city', $capital->fresh()->facility()->value('key'));
        $this->assertSame($oldCapitalPopulation, $capital->fresh()->population);
        $this->assertSame($cityTarget->id, $nation->capital()->value('map_cell_id'));

        $publicEvents = collect(app(PlayerIslandEventService::class)->publicNationPage($nation, 1, 9)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $forestMessages = $publicEvents->filter(
            fn (array $event): bool => str_contains($event['message'], 'どこかで森が増えた気がします。'),
        );
        $this->assertCount(2, $forestMessages);
        $this->assertTrue($forestMessages->every(
            fn (array $event): bool => $event['type'] === 'command.forest_planted_public'
                && ! str_contains($event['message'], '('),
        ));
        $this->assertFalse($publicEvents->contains(
            fn (array $event): bool => in_array($event['type'], [
                'command.missile_base_built_public',
                'command.decoy_built_public',
            ], true),
        ));
        $this->assertTrue($publicEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.seabed_base_built_public'
                && str_contains($event['message'], '(?,?)'),
        ));
        $this->assertSame(2, $publicEvents->where('type', 'command.facility_built_public')
            ->filter(fn (array $event): bool => str_contains($event['message'], '防衛施設'))->count());
        $this->assertTrue($publicEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.capital_relocated_public'
                && $event['message'] === sprintf(
                    '残存開発国の首都が(%d,%d)から(%d,%d)へ移転しました。',
                    $capital->x,
                    $capital->y,
                    $cityTarget->x,
                    $cityTarget->y,
                ),
        ));
    }

    public function test_aid_attraction_and_monster_dispatch_execute_as_nation_commands(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '支援派遣国');
        [, $receiver] = $this->nation($world, '支援受領国');
        $space = $this->surfaceMapSpace($world);
        $sender->update(['money' => 9_999]);
        $receiver->update(['money' => 100]);
        $wheat = DB::table('resource_definitions')->where('key', 'wheat')->value('id');
        $this->assertIsInt($wheat);
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $sender->id, 'resource_definition_id' => $wheat],
            ['amount' => 5_000, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $receiver->id, 'resource_definition_id' => $wheat],
            ['amount' => 0, 'created_at' => now(), 'updated_at' => now()],
        );
        $parameters = ['target_nation_id' => $receiver->id];
        $service = app(CommandQueueService::class);
        $moneyAid = $this->queue($service, $user, $sender, $space, 'money_aid', null, 2, null, $parameters);
        $foodAid = $this->queue($service, $user, $sender, $space, 'food_aid', null, 2, null, $parameters);
        $dispatch = $this->queue($service, $user, $sender, $space, 'monster_dispatch', null, 1, null, $parameters);

        $context = $this->context($world, 2, hash('sha256', 'nation command transfer and dispatch'), [$sender->id]);
        app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame(6_799, $sender->fresh()->money);
        $this->assertSame(300, $receiver->fresh()->money);
        $this->assertSame(3_000, (int) DB::table('nation_resources')
            ->where('nation_id', $sender->id)->where('resource_definition_id', $wheat)->value('amount'));
        $this->assertSame(2_000, (int) DB::table('nation_resources')
            ->where('nation_id', $receiver->id)->where('resource_definition_id', $wheat)->value('amount'));
        foreach ([$moneyAid, $foodAid, $dispatch] as $item) {
            $this->assertSame('completed', $item->fresh()->status);
        }
        $this->assertSame(1, MonsterInstance::query()->where('world_id', $world->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'mecha_inora'))->count());
        $this->assertSame(1, MonsterOccupancy::query()->whereHas(
            'cell',
            fn ($query) => $query->where('owner_nation_id', $receiver->id),
        )->count());
        Nation::query()->whereKey($sender->id)->update(['name' => '現在送信国']);
        Nation::query()->whereKey($receiver->id)->update(['name' => '現在受信国']);
        $senderMessages = collect(app(PlayerIslandEventService::class)->ownerPage($sender, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $receiverMessages = collect(app(PlayerIslandEventService::class)->ownerPage($receiver, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($senderMessages->contains(
            fn (string $message): bool => str_contains($message, '支援受領国へ資金援助として200億円'),
        ));
        $this->assertTrue($senderMessages->contains(
            fn (string $message): bool => str_contains($message, '支援受領国へ食料援助として2,000トン'),
        ));
        $this->assertTrue($receiverMessages->contains(
            fn (string $message): bool => str_contains($message, '支援派遣国から資金援助として200億円'),
        ));
        $this->assertTrue($receiverMessages->contains(
            fn (string $message): bool => str_contains($message, '支援派遣国から食料援助として2,000トン'),
        ));
        $eventService = app(PlayerIslandEventService::class);
        $worldAidEvents = collect($eventService->publicWorldPage($world, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->whereIn('type', ['command.money_aid_public', 'command.food_aid_public'])->values();
        $senderAidEvents = collect($eventService->publicNationPage($sender->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->whereIn('type', ['command.money_aid_public', 'command.food_aid_public'])->values();
        $receiverAidEvents = collect($eventService->publicNationPage($receiver->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->whereIn('type', ['command.money_aid_public', 'command.food_aid_public'])->values();
        $this->assertCount(2, $worldAidEvents);
        $this->assertSame($worldAidEvents->pluck('id')->all(), $senderAidEvents->pluck('id')->all());
        $this->assertSame($worldAidEvents->pluck('id')->all(), $receiverAidEvents->pluck('id')->all());
        $this->assertEqualsCanonicalizing([
            '支援派遣国から支援受領国へ200億円の資金援助が行われました。',
            '支援派遣国から支援受領国へ食料2,000トンの援助が行われました。',
        ], $worldAidEvents->pluck('message')->all());
        $publicAidJson = json_encode($worldAidEvents->all(), JSON_THROW_ON_ERROR);
        foreach (['requested_money', 'receiver_capacity', 'requested_food_tons', 'sender_money_before'] as $privateKey) {
            $this->assertStringNotContainsString($privateKey, $publicAidJson);
        }

        $sender->refresh();
        $attraction = $this->queue($service, $user, $sender, $space, 'attraction', null);
        $sender->update(['money' => 1_000]);
        $attractionContext = $this->context($world, 3, hash('sha256', 'attraction command'), [$sender->id]);
        app(DomesticCommandExecutor::class)->execute($attractionContext);
        $this->assertTrue($attractionContext->state->hasAttraction($sender->id));
        $this->assertSame(0, $sender->fresh()->money);
        $this->assertSame('completed', $attraction->fresh()->status);
        $attractionEvents = collect($eventService->publicNationPage($sender->fresh(), 1, 3)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertTrue($attractionEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.attraction_started_public'
                && $event['message'] === '現在送信国で誘致活動が行われました。',
        ));
    }

    public function test_zero_effect_money_and_food_aid_preserve_assets_and_increment_idle_through_automatic_finance(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '援助送信国');
        [, $receiver] = $this->nation($world, '援助上限国');
        $space = $this->surfaceMapSpace($world);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $capacity = app(NationCapacityResolver::class)->resolve($receiver, $ruleset);
        $sender->update(['money' => 1_000, 'idle_counter' => 3]);
        $receiver->update(['money' => $capacity->money]);
        $wheatId = DB::table('resource_definitions')->where('key', 'wheat')->value('id');
        $this->assertIsInt($wheatId);
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $sender->id, 'resource_definition_id' => $wheatId],
            ['amount' => 2_000, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $receiver->id, 'resource_definition_id' => $wheatId],
            ['amount' => $capacity->foodTons, 'created_at' => now(), 'updated_at' => now()],
        );
        $parameters = ['target_nation_id' => $receiver->id];
        $service = app(CommandQueueService::class);
        $moneyAid = $this->queue($service, $user, $sender, $space, 'money_aid', null, 1, null, $parameters);
        $foodAid = $this->queue($service, $user, $sender, $space, 'food_aid', null, 1, null, $parameters);

        $result = app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'zero effect aid'),
            [$sender->id],
        ));

        $this->assertSame(2, $result['successes']);
        $this->assertSame(1, $result['automatic_finance']);
        $this->assertSame(1, $result['idle_counter_increments']);
        $this->assertSame(0, $result['idle_counter_resets']);
        $this->assertSame(1_010, $sender->fresh()->money);
        $this->assertSame($capacity->money, $receiver->fresh()->money);
        $this->assertSame(2_000, (int) DB::table('nation_resources')
            ->where('nation_id', $sender->id)->where('resource_definition_id', $wheatId)->value('amount'));
        $this->assertSame($capacity->foodTons, (int) DB::table('nation_resources')
            ->where('nation_id', $receiver->id)->where('resource_definition_id', $wheatId)->value('amount'));
        $this->assertSame(4, $sender->fresh()->idle_counter);
        $this->assertSame('completed', $moneyAid->fresh()->status);
        $this->assertSame('completed', $foodAid->fresh()->status);
        $this->assertSame(0, (int) DB::table('audit_events')->where('event_type', 'command.money_aid_transferred')
            ->value(DB::raw("(metadata->>'transferred_money')::integer")));
        $this->assertSame(0, (int) DB::table('audit_events')->where('event_type', 'command.food_aid_transferred')
            ->value(DB::raw("(metadata->>'transferred_food_tons')::integer")));
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', [
            'command.money_aid_public', 'command.food_aid_public',
        ])->count());
        $events = app(PlayerIslandEventService::class);
        $messages = collect($events->ownerPage($sender, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $receiverMessages = collect($events->ownerPage($receiver, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_contains($message, '資金収容上限')));
        $this->assertTrue($messages->contains(fn (string $message): bool => str_contains($message, '食料収容上限')));
        $this->assertTrue($receiverMessages->contains(fn (string $message): bool => str_contains($message, '資金収容上限')));
        $this->assertTrue($receiverMessages->contains(fn (string $message): bool => str_contains($message, '食料収容上限')));
    }

    public function test_partial_aid_is_meaningful_and_resets_idle_with_exact_transfer_and_overflow(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '部分援助送信国');
        [, $receiver] = $this->nation($world, '部分援助受信国');
        $space = $this->surfaceMapSpace($world);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $capacity = app(NationCapacityResolver::class)->resolve($receiver, $ruleset);
        $sender->update(['money' => 1_000, 'idle_counter' => 5]);
        $receiver->update(['money' => $capacity->money - 50]);
        $wheatId = DB::table('resource_definitions')->where('key', 'wheat')->value('id');
        $this->assertIsInt($wheatId);
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $sender->id, 'resource_definition_id' => $wheatId],
            ['amount' => 2_000, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $receiver->id, 'resource_definition_id' => $wheatId],
            ['amount' => $capacity->foodTons - 500, 'created_at' => now(), 'updated_at' => now()],
        );
        $parameters = ['target_nation_id' => $receiver->id];
        $service = app(CommandQueueService::class);
        $this->queue($service, $user, $sender, $space, 'money_aid', null, 2, null, $parameters);
        $this->queue($service, $user, $sender, $space, 'food_aid', null, 1, null, $parameters);

        $result = app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'partial effect aid'),
            [$sender->id],
        ));

        $this->assertSame(1, $result['automatic_finance']);
        $this->assertSame(0, $result['idle_counter_increments']);
        $this->assertSame(1, $result['idle_counter_resets']);
        $this->assertSame(0, $sender->fresh()->idle_counter);
        $this->assertSame(960, $sender->fresh()->money);
        $this->assertSame($capacity->money, $receiver->fresh()->money);
        $moneyEvent = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.money_aid_transferred')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $foodEvent = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.food_aid_transferred')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(50, $moneyEvent['transferred_money']);
        $this->assertSame(150, $moneyEvent['receiver_capacity_overflow']);
        $this->assertSame(500, $foodEvent['transferred_food_tons']);
        $this->assertSame(500, $foodEvent['receiver_capacity_overflow_tons']);
    }

    public function test_zero_effect_aid_transaction_rollback_does_not_duplicate_idle_or_transfer_events(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '援助再試行国');
        [, $receiver] = $this->nation($world, '援助再試行対象国');
        $space = $this->surfaceMapSpace($world);
        $capacity = app(NationCapacityResolver::class)->resolve($receiver, $world->rulesetVersion()->firstOrFail());
        $sender->update(['money' => 1_000, 'idle_counter' => 2]);
        $receiver->update(['money' => $capacity->money]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $user,
            $sender,
            $space,
            'money_aid',
            null,
            1,
            null,
            ['target_nation_id' => $receiver->id],
        );

        try {
            DB::transaction(function () use ($world, $sender): void {
                app(DomesticCommandExecutor::class)->execute($this->context(
                    $world,
                    2,
                    hash('sha256', 'rolled back zero aid'),
                    [$sender->id],
                ));
                throw new RuntimeException('force rollback');
            });
            $this->fail('The forced rollback did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame(2, $sender->fresh()->idle_counter);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.money_aid_transferred')->count());

        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'retried zero aid'),
            [$sender->id],
        ));

        $this->assertSame(3, $sender->fresh()->idle_counter);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.money_aid_transferred')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')->count());
    }

    /** @return array{World, User, Nation, Nation} */
    private function combatants(): array
    {
        $world = $this->lightweightWorld();
        [$user, $firing] = $this->nation($world, '発射国');
        [, $target] = $this->nation($world, '標的国');
        $firing->update(['money' => 1_000]);

        return [$world, $user, $firing, $target];
    }

    /** @return array{User, Nation} */
    private function nation(World $world, string $name): array
    {
        $user = User::factory()->create();

        return [$user, app(NationCreationService::class)->create($user, $world, $name, '試験島主')];
    }

    private function missileBase(Nation $nation): MapCell
    {
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'missile_base')->firstOrFail(),
        );
        $cell->save();

        return $cell->fresh(['terrain', 'facility']);
    }

    private function ownedWaterFacility(Nation $nation, string $facilityKey, ?int $experience = null): MapCell
    {
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($nation->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
            experience: $experience,
        );
        $cell->owner_nation_id = $nation->id;
        $cell->population = 0;
        $cell->save();

        return $cell->fresh(['terrain', 'facility', 'ownerNation']);
    }

    /** @return list<MapCell> */
    private function neutralCellsNearTerritory(Nation $nation, MapSpace $space, int $count): array
    {
        $owned = MapCell::query()->where('owner_nation_id', $nation->id)->get(['x', 'y']);
        $candidates = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')->orderBy('id')->get();
        $nearby = $candidates->filter(static function (MapCell $candidate) use ($owned): bool {
            $coordinate = new GridCoordinate($candidate->x, $candidate->y);

            return $owned->contains(static fn (MapCell $cell): bool => $coordinate->distanceTo(
                new GridCoordinate($cell->x, $cell->y),
            ) <= 1);
        })->take($count)->values();
        if ($nearby->count() !== $count) {
            $this->fail("Expected {$count} neutral cells next to the Nation territory.");
        }

        return $nearby->all();
    }

    private function monster(World $world, MapCell $cell): MonsterInstance
    {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $definition->base_hp,
            'spawned_max_hp' => $definition->base_hp,
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

    /**
     * @return array{
     *     shots_fired: int,
     *     money_spent: int,
     *     meaningful_impacts: int,
     *     ineffective_impacts: int,
     *     changed_cell_ids: list<int>
     * }
     */
    private function resolveMissile(TurnContext $context, MapCell $base): array
    {
        app(DomesticCommandExecutor::class)->execute($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin();
        $metrics = $resolver->processBase($context, $this->surfaceMapSpace($context->world), $base);
        $resolver->finalize($context);

        return $metrics;
    }

    /**
     * @param  list<MapCell>  $bases
     * @return array{
     *     shots_fired: int,
     *     finalize: array{launches: int, shots_fired: int, ineffective_impacts: int, idle_counter_resets: int}
     * }
     */
    private function processRegisteredMissiles(TurnContext $context, array $bases): array
    {
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin();
        $shotsFired = 0;
        foreach ($bases as $base) {
            $metrics = $resolver->processBase($context, $this->surfaceMapSpace($context->world), $base);
            $shotsFired += $metrics['shots_fired'];
        }

        return [
            'shots_fired' => $shotsFired,
            'finalize' => $resolver->finalize($context),
        ];
    }

    private function queue(
        CommandQueueService $service,
        User $user,
        Nation $nation,
        MapSpace $space,
        string $key,
        ?MapCell $cell,
        int $quantity = 1,
        ?int $position = null,
        array $parameters = [],
    ): NationCommandQueueItem {
        $version = (int) ($nation->commandQueue()->value('version') ?? 1);

        return $service->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: $key,
            targetX: $cell?->x,
            targetY: $cell?->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: $version,
            quantity: $quantity,
            parameters: $parameters,
            position: $position,
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

    private function seedForImpactIndex(
        NationCommandQueueItem $item,
        MapCell $aim,
        int $radius,
        MapCell $desired,
    ): string {
        $candidates = (new GridCoordinate($aim->x, $aim->y))->radius($radius);
        $index = array_search(
            $desired->x.':'.$desired->y,
            array_map(fn (GridCoordinate $candidate): string => $candidate->x.':'.$candidate->y, $candidates),
            true,
        );
        $this->assertIsInt($index);

        return $this->seedForDrawIndex($item, count($candidates), $index);
    }

    private function seedForDrawIndex(NationCommandQueueItem $item, int $count, int $index): string
    {
        $label = TurnRandomStreamFactory::missileImpact($item->id);
        for ($candidate = 0; $candidate < 10_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$candidate}");
            if ((new TurnRandomStreamFactory($seed))->stream($label)->integer(0, $count - 1) === $index) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic missile draw {$index} for {$label}.");
    }
}
