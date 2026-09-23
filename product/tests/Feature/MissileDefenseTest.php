<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\PlayerIslandEventService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryNamingService;
use App\Application\SecretaryTurnService;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\TerrainDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\Support\CommandAndMissileTestCase;

final class MissileDefenseTest extends CommandAndMissileTestCase
{
    use UsesReusableSurfaceWorld;

    public function test_radius_two_defense_intercepts_a_canonical_source_missile(): void
    {
        [$world, $firingUser, $firing, $targetNation] = $this->combatants();
        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $target = MapCell::query()->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $defense = $this->placeFacilityAtDistance($space, $target, $targetNation, 2, 'defense');
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'missile',
            $target,
        );
        $context = $this->context(
            $world,
            2,
            $this->seedForImpactIndex($item, $target, 2, $target),
            [$firing->id, $targetNation->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$firing->id, $targetNation->id]);

        $metrics = $this->resolveMissile($context, $base);

        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('defense_intercepted', $detail['impacts'][0]['effect']);
        $this->assertSame(1, $detail['impacts'][0]['covering_defense_count']);
        $this->assertSame(0, $metrics['ineffective_impacts']);
        $this->assertSame(0, $context->state->finalDefenseInterceptionsUsed($targetNation->id));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.defense_intercepted')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'secretary.missile_intercepted')->count());
        $this->assertNotNull($defense->fresh()->facility_definition_id);
        $this->assertSame([
            $targetNation->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 1],
        ], $context->state->pendingSecretaryExperience());
    }

    public function test_magic_white_flag_on_defense_owner_stops_its_defense_from_protecting_a_monster(): void
    {
        [$world, $firingUser, $firing, $targetNation] = $this->combatants('白旗防御側');
        $targetUserId = (int) DB::table('nation_memberships')
            ->where('nation_id', $targetNation->id)
            ->where('role', 'owner')
            ->value('user_id');
        $this->assertGreaterThan(0, $targetUserId);
        $targetUser = User::query()->findOrFail($targetUserId);
        app(SecretaryItemGrantService::class)->grant(
            $targetUser->secretary()->sole(),
            'magic_white_flag',
            1,
            2,
            'test:white-flag:defense-owner',
        );

        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $target = MapCell::query()->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $defense = $this->placeFacilityAtDistance($space, $target, $targetNation, 1, 'defense');
        $monster = $this->monster($world, $target);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $target);
        $context = $this->context(
            $world,
            2,
            $this->seedForImpactIndex($item, $target, 2, $target),
            [$firing->id, $targetNation->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$firing->id, $targetNation->id]);

        $this->resolveMissile($context, $base);

        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('damaged', $detail['impacts'][0]['effect']);
        $this->assertSame(1, $monster->fresh()->current_hp);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.defense_intercepted')->count());
        $this->assertNotNull($defense->fresh()->facility_definition_id);
    }

    public function test_magic_white_flag_on_firing_nation_does_not_disable_foreign_defense(): void
    {
        [$world, $firingUser, $firing, $targetNation] = $this->combatants('白旗攻撃側');
        app(SecretaryItemGrantService::class)->grant(
            $firingUser->secretary()->sole(),
            'magic_white_flag',
            1,
            2,
            'test:white-flag:firing-owner',
        );

        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $target = MapCell::query()->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $this->placeFacilityAtDistance($space, $target, $targetNation, 1, 'defense');
        $monster = $this->monster($world, $target);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $target);
        $context = $this->context(
            $world,
            2,
            $this->seedForImpactIndex($item, $target, 2, $target),
            [$firing->id, $targetNation->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$firing->id, $targetNation->id]);

        $this->resolveMissile($context, $base);

        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('defense_intercepted', $detail['impacts'][0]['effect']);
        $this->assertSame(2, $monster->fresh()->current_hp);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.defense_intercepted')->count());
    }

    public function test_v8_defense_radius_center_outside_decoy_overlap_self_and_monster_contract(): void
    {
        // radius 1 and overlapping facilities: one impact, one audit event,
        // no Secretary budget, with both facilities preserved.
        [$world, $firingUser, $firing, $targetNation] = $this->combatants();
        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $target = MapCell::query()->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $firstDefense = $this->placeFacilityAtDistance($space, $target, $targetNation, 1, 'defense');
        $secondDefense = $this->placeFacilityAtDistance($space, $target, $targetNation, 2, 'defense');
        $monster = $this->monster($world, $target);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $target);
        $context = $this->context($world, 2, hash('sha256', 'v8 overlap monster'), [$firing->id, $targetNation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$firing->id, $targetNation->id]);
        $this->resolveMissile($context, $base);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('defense_intercepted', $detail['impacts'][0]['effect']);
        $this->assertSame(2, $detail['impacts'][0]['covering_defense_count']);
        $this->assertSame(2, $monster->fresh()->current_hp);
        $this->assertNotNull($firstDefense->fresh()->facility_definition_id);
        $this->assertNotNull($secondDefense->fresh()->facility_definition_id);

        // A center defense is not its own surrounding interceptor. The v6-v8
        // SPP direct-resistance owner decision then resolves before Secretary.
        $center = $target->fresh(['terrain', 'facility', 'ownerNation']);
        app(MapCellStateService::class)->setFacility($center, FacilityDefinition::query()->where('key', 'defense')->firstOrFail());
        $center->save();
        $centerItem = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $center);
        $centerContext = $this->context($world, 3, hash('sha256', 'v8 center'), [$firing->id, $targetNation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($centerContext, [$firing->id, $targetNation->id]);
        $this->resolveMissile($centerContext, $base->fresh(['terrain', 'facility']));
        $centerDetail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $centerItem->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('defense_resisted', $centerDetail['impacts'][0]['effect']);
        $this->assertSame(0, $centerContext->state->finalDefenseInterceptionsUsed($targetNation->id));
        $this->assertSame([
            $targetNation->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 1],
        ], $centerContext->state->pendingSecretaryExperience());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'secretary.missile_intercepted')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.defense_intercepted')->count());

        // radius 3 and a radius-1 decoy do not defend; only then may Secretary run.
        MonsterOccupancy::query()->where('map_cell_id', $target->id)->delete();
        app(MapCellStateService::class)->setFacility($center, null);
        $center->save();
        app(MapCellStateService::class)->setFacility($firstDefense, null);
        $firstDefense->save();
        app(MapCellStateService::class)->setFacility($secondDefense, null);
        $secondDefense->save();
        $outsideTarget = $center->fresh(['terrain', 'facility', 'ownerNation']);
        $this->placeFacilityAtDistance($space, $outsideTarget, $targetNation, 3, 'defense');
        $this->placeFacilityAtDistance($space, $outsideTarget, $targetNation, 1, 'decoy');
        $outsideItem = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $outsideTarget);
        $outsideContext = $this->context($world, 4, hash('sha256', 'v8 outside decoy'), [$firing->id, $targetNation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($outsideContext, [$firing->id, $targetNation->id]);
        $this->resolveMissile($outsideContext, $base->fresh(['terrain', 'facility']));
        $outsideDetail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $outsideItem->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('secretary_intercepted', $outsideDetail['impacts'][0]['effect']);
        $this->assertSame(1, $outsideContext->state->finalDefenseInterceptionsUsed($targetNation->id));

        // A Nation's own missile is covered by the same source contract.
        $selfTarget = MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereKeyNot($base->id)->whereNull('facility_definition_id')
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $this->placeFacilityAtDistance($space, $selfTarget, $firing, 1, 'defense');
        $selfItem = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $selfTarget);
        $selfContext = $this->context($world, 5, hash('sha256', 'v8 self fired'), [$firing->id, $targetNation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($selfContext, [$firing->id, $targetNation->id]);
        $this->resolveMissile($selfContext, $base->fresh(['terrain', 'facility']));
        $selfDetail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $selfItem->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('defense_intercepted', $selfDetail['impacts'][0]['effect']);
    }

    public function test_dormant_owned_defense_covers_an_active_target_outside_the_protected_radius(): void
    {
        [$world, $firingUser, $firing, $activeTarget] = $this->combatants();
        [, $dormantDefenseOwner] = $this->nation($world, '休眠防衛施設国');
        $dormantDefenseOwner->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $target = MapCell::query()->where('owner_nation_id', $activeTarget->id)
            ->whereKeyNot($activeTarget->capital()->valueOrFail('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $defense = $this->placeFacilityAtDistance($space, $target, $dormantDefenseOwner, 1, 'defense');
        $coveredItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $target,
        );
        $coveredContext = $this->context(
            $world, 2, hash('sha256', 'dormant-owned defense covers active target'),
            [$firing->id, $activeTarget->id, $dormantDefenseOwner->id],
        );
        app(SecretaryTurnService::class)->loadAttemptSnapshots(
            $coveredContext,
            [$firing->id, $activeTarget->id, $dormantDefenseOwner->id],
        );

        $this->resolveMissile($coveredContext, $base);

        $coveredDetail = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $coveredItem->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('defense_intercepted', $coveredDetail['impacts'][0]['effect']);
        $this->assertNotNull($defense->fresh()->facility_definition_id);
        $this->assertSame(0, $coveredContext->state->finalDefenseInterceptionsUsed($activeTarget->id));
    }

    public function test_later_shot_observes_defense_destroyed_earlier_in_the_same_base_processing(): void
    {
        [$world, $firingUser, $firing, $targetNation] = $this->combatants();
        $firing->update(['money' => 10_000]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $base->update(['facility_experience' => 20]);
        $laterTarget = MapCell::query()->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->valueOrFail('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $defense = $this->placeFacilityAtDistance($space, $laterTarget, $targetNation, 1, 'defense');
        $item = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'missile', $laterTarget, quantity: 2,
        );
        $context = $this->context(
            $world,
            2,
            $this->seedForImpactSequence($item, $laterTarget, 2, [$defense, $laterTarget]),
            [$firing->id, $targetNation->id],
        );

        $metrics = $this->resolveMissile($context, $base->fresh(['terrain', 'facility']));

        $detail = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $metrics['shots_fired']);
        $this->assertSame('land_scorched', $detail['impacts'][0]['effect']);
        $this->assertNull($defense->fresh()->facility_definition_id);
        $this->assertSame('land_scorched', $detail['impacts'][1]['effect']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.defense_intercepted')->count());
    }

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

        $firstMetrics = $this->resolveMissile($first, $base);

        $firstDetail = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $firstItem->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['secretary_intercepted', 'capital_damaged'],
            array_column($firstDetail['impacts'], 'effect'),
        );
        $this->assertSame(1, $first->state->finalDefenseInterceptionsUsed($target->id));
        $this->assertSame(0, $firstMetrics['ineffective_impacts']);
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
        $targetUser = User::query()->findOrFail($targetUserId);
        app(SecretaryNamingService::class)->name($targetUser, 'ペリドット');
        app(SecretaryNamingService::class)->rename($targetUser, 'エメラルド');
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

        $secondMetrics = $this->resolveMissile($second, $base->fresh(['terrain', 'facility']));

        $secondDetail = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $secondItem->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('secretary_intercepted', $secondDetail['impacts'][0]['effect']);
        $this->assertSame(1, $second->state->finalDefenseInterceptionsUsed($target->id));
        $this->assertSame(0, $secondMetrics['ineffective_impacts']);
        $this->assertSame([
            $target->id => [SecretarySkillCatalog::FINAL_DEFENSE_LINE => 1],
        ], $second->state->pendingSecretaryExperience());
        $labels = DB::table('audit_events')->where('event_type', 'secretary.missile_intercepted')
            ->orderBy('id')->selectRaw("metadata->>'secretary_label' AS secretary_label")
            ->pluck('secretary_label')->all();
        $this->assertSame(['秘書', '秘書のエメラルド'], $labels);
        $messages = collect(app(PlayerIslandEventService::class)->ownerPage($target->fresh(), 1, 3)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->where('type', 'secretary.missile_intercepted')->pluck('message');
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '秘書が1発のミサイルを迎撃'),
        ));
        $this->assertTrue($messages->contains(
            static fn (string $message): bool => str_contains($message, '秘書のエメラルドが1発のミサイルを迎撃'),
        ));
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
}
