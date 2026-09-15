<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\DomesticCommandExecutor;
use App\Application\KarmaTurnService;
use App\Application\MissileImpactResolver;
use App\Application\NationCommandTargetService;
use App\Application\NationCreationService;
use App\Application\NationLifecycleService;
use App\Application\SecretaryTurnService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Nation\NationProtectionPolicy;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterOccupancy;
use App\Models\NationCommandQueueItem;
use App\Models\TerrainDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\Support\CommandAndMissileTestCase;

final class MissileKarmaAndRecoveryTest extends CommandAndMissileTestCase
{
    use UsesReusableSurfaceWorld;

    public function test_v13_karma_ledger_uses_turn_start_decay_and_the_exact_settlement_order(): void
    {
        [$world, $_user, $newlyCriminal, $sanctioned] = $this->combatants('karma-ledger');
        $goodUser = User::factory()->create();
        $goodNation = app(NationCreationService::class)->create(
            $goodUser,
            $world,
            '善行島',
            '善行島主',
        );
        $newlyCriminal->update(['karma' => 0]);
        $sanctioned->update(['karma' => 3]);
        $goodNation->update(['karma' => -9]);
        $nationIds = [$newlyCriminal->id, $sanctioned->id, $goodNation->id];
        $context = $this->context($world, 6, hash('sha256', 'v13 karma ledger order'), $nationIds);
        $context->state->setLifecycleNationIds($nationIds);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);

        $context->state->addKarmaCrime($newlyCriminal->id, 5);
        $context->state->recordKarmaSanctions($newlyCriminal->id, 0);

        $context->state->addKarmaCrime($sanctioned->id, 100);
        for ($impact = 0; $impact < 5; $impact++) {
            $context->state->recordHostileImpactReceived($sanctioned->id);
        }
        $context->state->markRecoveryEntry($sanctioned->id);
        $context->state->markForeignMonsterKill($sanctioned->id);
        $context->state->recordKarmaSanctions($sanctioned->id, 3);

        $context->state->recordHostileImpactReceived($goodNation->id);
        $context->state->markRecoveryEntry($goodNation->id);
        $context->state->markForeignMonsterKill($goodNation->id);
        $context->state->recordKarmaSanctions($goodNation->id, 0);

        $metrics = $karma->finalize($context);

        $this->assertSame(5, (int) $newlyCriminal->fresh()->karma,
            'Turn-start KARMA 0 must not decay merely because same-Turn crime made it positive.');
        $this->assertSame(92, (int) $sanctioned->fresh()->karma);
        $this->assertSame(-10, (int) $goodNation->fresh()->karma);
        $this->assertSame(3, $context->state->karmaLedgerForNation($sanctioned->id)['sanction_count']);
        $this->assertSame([
            'nations' => 3,
            'changed' => 3,
            'crime_points' => 105,
            'victim_reductions' => 3,
            'decay_reductions' => 1,
            'recovery_reductions' => 3,
            'monster_kill_reductions' => 2,
            'minimum_recoveries' => 0,
        ], $metrics);
    }

    public function test_good_person_treasure_snapshots_the_lower_karma_minimum_and_removal_recovers_gradually(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '秘宝島', '秘宝島主');
        $treasure = $user->secretary()->firstOrFail()->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::GOOD_PERSON_TREASURE,
            'level' => 10,
            'equipped_slot' => 2,
            'grant_key' => 'test:karma:good-person-treasure',
            'obtained_at' => now(),
        ]);
        $nation->update(['karma' => -10]);

        $equipped = $this->context($world, 2, hash('sha256', 'treasure equipped'), [$nation->id]);
        $equipped->state->setLifecycleNationIds([$nation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($equipped, [$nation->id]);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($equipped);
        $equipped->state->markForeignMonsterKill($nation->id);
        $equippedMetrics = $karma->finalize($equipped);

        $this->assertSame(-20, $equipped->state->karmaMinimumSnapshot($nation->id));
        $this->assertSame(-11, (int) $nation->fresh()->karma);
        $this->assertSame(1, $equippedMetrics['monster_kill_reductions']);

        $treasure->update(['equipped_slot' => null]);
        $nation->update(['karma' => -20]);
        $removed = $this->context($world, 3, hash('sha256', 'treasure removed'), [$nation->id]);
        $removed->state->setLifecycleNationIds([$nation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($removed, [$nation->id]);
        $karma->prepare($removed);
        $removed->state->markForeignMonsterKill($nation->id);
        $removedMetrics = $karma->finalize($removed);

        $this->assertSame(-10, $removed->state->karmaMinimumSnapshot($nation->id));
        $this->assertSame(-19, (int) $nation->fresh()->karma);
        $this->assertSame(0, $removedMetrics['monster_kill_reductions']);
        $this->assertSame(1, $removedMetrics['minimum_recoveries']);

        $nation->update(['karma' => -10]);
        $ordinary = $this->context($world, 4, hash('sha256', 'ordinary minimum'), [$nation->id]);
        $ordinary->state->setLifecycleNationIds([$nation->id]);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($ordinary, [$nation->id]);
        $karma->prepare($ordinary);
        $ordinary->state->markForeignMonsterKill($nation->id);
        $karma->finalize($ordinary);
        $this->assertSame(-10, (int) $nation->fresh()->karma);
    }

    public function test_v13_canonical_missile_impacts_apply_the_highest_single_karma_category(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('karma-categories');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 0]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $cells = app(MapCellStateService::class);
        $wasteland = TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail();
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        $town = FacilityDefinition::query()->where('key', 'town')->firstOrFail();
        $farm = FacilityDefinition::query()->where('key', 'farm')->firstOrFail();
        $turn = 2;

        $cells->setFacility($cell, null);
        $cells->transitionTerrain($cell, $wasteland);
        $cell->population = 0;
        $cell->save();
        $this->resolveKarmaMissileTurn(
            $world, $firingUser, $firing, $target, $base, 'spp_missile', $cell, $turn++,
        );
        $this->assertSame(1, (int) $firing->fresh()->karma);

        foreach ([3, 4, 5, 7, 8] as $townTurn) {
            $cell->refresh()->load(['terrain', 'facility', 'ownerNation']);
            $cells->transitionTerrain($cell, $plain);
            $cells->setFacility($cell, $town);
            $cell->owner_nation_id = $target->id;
            $cell->population = 500;
            $cell->save();
            $this->resolveKarmaMissileTurn(
                $world, $firingUser, $firing, $target, $base, 'spp_missile', $cell, $townTurn,
            );
        }
        $turn = 9;
        $this->assertSame(11, (int) $firing->fresh()->karma,
            'Five separate town impacts must add 10, not stack terrain plus settlement categories.');

        $cell->refresh()->load(['terrain', 'facility', 'ownerNation']);
        $cells->transitionTerrain($cell, $plain);
        $cells->setFacility($cell, $farm);
        $cell->owner_nation_id = $target->id;
        $cell->population = 0;
        $cell->save();
        $this->resolveKarmaMissileTurn(
            $world, $firingUser, $firing, $target, $base, 'spp_missile', $cell, $turn++,
        );
        $this->assertSame(13, (int) $firing->fresh()->karma);

        $oil = $this->ownedWaterFacility($target, 'seabed_oil_field');
        $this->resolveKarmaMissileTurn(
            $world, $firingUser, $firing, $target, $base, 'spp_missile', $oil, $turn++,
        );
        $this->assertSame(23, (int) $firing->fresh()->karma);

        $cell->refresh()->load(['terrain', 'facility', 'ownerNation']);
        $cells->transitionTerrain($cell, $plain);
        $cells->setFacility($cell, null);
        $cell->owner_nation_id = $target->id;
        $cell->population = 0;
        $cell->save();
        $land = $cell->fresh(['terrain', 'facility', 'ownerNation']);
        $landItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space,
            'land_destruction_missile', $land,
        );
        $this->resolvePreparedKarmaMissileTurn(
            $world,
            $firing,
            $target,
            $base,
            $landItem,
            $turn++,
            $this->seedForImpactIndex($landItem, $land, 2, $land),
        );
        $this->assertSame(33, (int) $firing->fresh()->karma);

        $turn = 13; // Keep the category contract independent from the every-six-turn decay.
        $seabedBase = $this->ownedWaterFacility($target, 'seabed_base');
        $seabedItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space,
            'land_destruction_missile', $seabedBase,
        );
        $this->resolvePreparedKarmaMissileTurn(
            $world,
            $firing,
            $target,
            $base,
            $seabedItem,
            $turn++,
            $this->seedForImpactIndex($seabedItem, $seabedBase, 2, $seabedBase),
        );
        $this->assertSame(36, (int) $firing->fresh()->karma);

        $underseaCity = $this->ownedWaterFacility($target, 'undersea_city');
        $underseaCity->update(['population' => 3_000]);
        $underseaItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space,
            'land_destruction_missile', $underseaCity,
        );
        $this->resolvePreparedKarmaMissileTurn(
            $world,
            $firing,
            $target,
            $base,
            $underseaItem,
            $turn++,
            $this->seedForImpactIndex($underseaItem, $underseaCity, 2, $underseaCity),
        );
        $this->assertSame(39, (int) $firing->fresh()->karma);
        $this->assertSame('sea', $underseaCity->fresh()->terrain()->value('key'));
        $this->assertNull($underseaCity->fresh()->facility_definition_id);
        $this->assertNull($underseaCity->fresh()->owner_nation_id);
        $this->assertSame(0, $underseaCity->fresh()->population);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'karma.missile_impact')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $underseaItem->id])
            ->whereRaw("metadata->>'crime_points' = '3'")->count());

        MapCell::query()->where('owner_nation_id', $target->id)->update(['population' => 0]);
        $capital = MapCell::query()->whereKey($target->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $capital->update(['population' => 200]);
        $this->resolveKarmaMissileTurn(
            $world, $firingUser, $firing, $target, $base, 'spp_missile', $capital, $turn++,
        );
        $this->assertSame(41, (int) $firing->fresh()->karma);
        $capital->refresh()->update(['population' => 100]);
        $this->resolveKarmaMissileTurn(
            $world, $firingUser, $firing, $target, $base, 'spp_missile', $capital, $turn,
        );
        $this->assertSame(41, (int) $firing->fresh()->karma);

        $karma = app(KarmaTurnService::class);
        $territoryTargets = $this->neutralCellsNearTerritory($firing, $space, 3);
        $expectedKarma = 41;
        foreach ([
            [$territoryTargets[0], 'wasteland', $target->id, 1],
            [$territoryTargets[1], 'wasteland', null, 0],
            [$territoryTargets[2], 'scorched', $target->id, 0],
        ] as $index => [$territoryCell, $terrainKey, $ownerNationId, $expectedCrime]) {
            app(MapCellStateService::class)->setFacility($territoryCell, null);
            app(MapCellStateService::class)->transitionTerrain(
                $territoryCell,
                TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail(),
            );
            $territoryCell->owner_nation_id = $ownerNationId;
            $territoryCell->population = 0;
            $territoryCell->save();
            $territoryCell = $territoryCell->fresh(['terrain', 'facility', 'ownerNation']);
            $territoryItem = $this->queue(
                app(CommandQueueService::class),
                $firingUser,
                $firing->fresh(),
                $space,
                'territory_expand',
                $territoryCell,
            );
            $territoryContext = $this->context(
                $world,
                19 + $index,
                hash('sha256', "foreign wasteland karma {$index}"),
                [$firing->id, $target->id],
            );
            $territoryContext->state->setLifecycleNationIds([$firing->id, $target->id]);
            $karma->prepare($territoryContext);
            app(SecretaryTurnService::class)->loadAttemptSnapshots($territoryContext, [$firing->id, $target->id]);
            app(DomesticCommandExecutor::class)->execute($territoryContext);
            $karma->finalize($territoryContext);

            $this->assertSame('completed', $territoryItem->fresh()->status);
            $this->assertSame($firing->id, $territoryCell->fresh()->owner_nation_id);
            $expectedKarma += $expectedCrime;
            $this->assertSame($expectedKarma, (int) $firing->fresh()->karma);
            if ($expectedCrime === 1) {
                $this->assertSame(1, DB::table('audit_events')
                    ->where('event_type', 'karma.foreign_wasteland_expanded')
                    ->where('subject_id', $territoryItem->id)->count());
            }
        }

        $retryContext = $this->context(
            $world,
            22,
            hash('sha256', 'foreign wasteland completed retry'),
            [$firing->id, $target->id],
        );
        $retryContext->state->setLifecycleNationIds([$firing->id, $target->id]);
        $karma->prepare($retryContext);
        app(DomesticCommandExecutor::class)->execute($retryContext);
        $karma->finalize($retryContext);
        $this->assertSame(42, (int) $firing->fresh()->karma);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'karma.foreign_wasteland_expanded')->count());
    }

    public function test_v13_turn_start_snapshot_freezes_twenty_one_hit_rewards_reductions_and_refugee_bonus(): void
    {
        [$world, $firstUser, $firstAttacker, $target] = $this->combatants('karma-snapshot');
        [$badUser, $badAttacker] = $this->nation($world, 'snapshot-positive-attacker');
        $firstAttacker->update(['money' => 9_999, 'karma' => 0]);
        $badAttacker->update(['money' => 9_999, 'karma' => 1]);
        $target->update(['karma' => 20]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $capital = $target->capital()->firstOrFail()->cell()
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $capital->update(['population' => 100_000]);
        $space = $this->surfaceMapSpace($world);
        $commands = app(CommandQueueService::class);
        $bases = [];
        foreach (range(1, 4) as $_index) {
            $base = $this->missileBase($firstAttacker);
            $base->update(['facility_experience' => 200]);
            $bases[] = $base;
        }
        $goodItem = $this->queue(
            $commands,
            $firstUser,
            $firstAttacker,
            $space,
            'spp_missile',
            $capital,
            19,
        );
        $badBase = $this->missileBase($badAttacker);
        $badBase->update(['facility_experience' => 20]);
        $bases[] = $badBase;
        $badItem = $this->queue(
            $commands,
            $badUser,
            $badAttacker,
            $space,
            'spp_missile',
            $capital,
            2,
        );
        $queueItems = [$goodItem, $badItem];
        $nationIds = [$firstAttacker->id, $badAttacker->id, $target->id];
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'v13 frozen twenty one impact snapshot'),
            $nationIds,
        );
        $lifecycle = app(NationLifecycleService::class);
        $lifecycle->prepare($context);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        app(DomesticCommandExecutor::class)->execute($context);
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $shots = 0;
        foreach ($bases as $base) {
            $shots += $resolver->processBase(
                $context,
                $space,
                $base->fresh(['terrain', 'facility', 'ownerNation']),
            )['shots_fired'];
        }
        $resolver->finalize($context);

        $this->assertSame(21, $shots);
        $this->assertSame(20, (int) $target->fresh()->karma,
            'Persistent KARMA must remain unchanged until the canonical finalization boundary.');
        $this->assertSame(21, $context->state->karmaLedgerForNation($target->id)['hostile_impacts_received']);
        $this->assertSame(380, $context->state->karmaLedgerForNation($firstAttacker->id)['alliance_money']);
        $this->assertSame(0, $context->state->karmaLedgerForNation($firstAttacker->id)['crime_points']);
        $this->assertSame(0, $context->state->karmaLedgerForNation($badAttacker->id)['alliance_money']);
        $this->assertSame(0, $context->state->karmaLedgerForNation($badAttacker->id)['crime_points']);

        $alliance = $karma->settleAllianceMoney($context);
        $resolver->resolveSanctions($context);
        $karma->finalize($context);

        $this->assertSame(['nations' => 1, 'requested' => 380, 'applied' => 380, 'overflow' => 0], $alliance);
        $this->assertSame(0, (int) $target->fresh()->karma);
        $this->assertSame(1, (int) $badAttacker->fresh()->karma);
        $this->assertSame(879, (int) $firstAttacker->fresh()->money);
        $this->assertSame(8_999, (int) $badAttacker->fresh()->money);

        $queueItemIds = array_map(static fn (NationCommandQueueItem $item): int => $item->id, $queueItems);
        $impacts = DB::table('audit_events')->where('event_type', 'karma.missile_impact')
            ->whereIn(DB::raw("(metadata->>'queue_item_id')::bigint"), $queueItemIds)
            ->orderBy('id')->get()->map(static fn (object $event): array => json_decode(
                (string) $event->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ));
        $this->assertCount(21, $impacts);
        $this->assertTrue($impacts->every(static fn (array $impact): bool => $impact['target_start_karma'] === 20));
        $this->assertTrue($impacts->take(19)->every(static fn (array $impact): bool => $impact['alliance_money'] === 20
            && $impact['attacker_start_karma'] === 0 && $impact['crime_points'] === 0));
        $this->assertSame(20, $impacts[19]['target_start_karma'],
            'The impact that exhausts the victim reduction must still use the Turn-start snapshot.');
        $this->assertSame(1, $impacts[19]['attacker_start_karma']);
        $this->assertSame(0, $impacts[19]['alliance_money']);
        $this->assertSame(20, $impacts->last()['target_start_karma']);
        $this->assertSame(1, $impacts->last()['attacker_start_karma']);
        $this->assertSame(0, $impacts->last()['alliance_money']);
        $this->assertSame($badItem->id, $impacts->last()['queue_item_id']);

        $bonuses = DB::table('audit_events')->where('event_type', 'karma.refugee_bonus')
            ->whereIn(DB::raw("(metadata->>'queue_item_id')::bigint"), $queueItemIds)
            ->get()->map(static fn (object $event): array => json_decode(
                (string) $event->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ));
        $this->assertCount(19, $bonuses);
        $this->assertTrue($bonuses->every(static fn (array $bonus): bool => $bonus['target_start_karma'] === 20
            && $bonus['bonus_refugees'] === intdiv($bonus['base_refugees'] * 20, 100)
            && $bonus['total_refugees'] === $bonus['base_refugees'] + $bonus['bonus_refugees']));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'karma.refugee_bonus')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $badItem->id])->count());
    }

    public function test_v13_anti_monster_launch_classification_uses_both_snapshots_and_stays_frozen(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('anti-monster');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 0]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $firstBase = $this->missileBase($firing);
        $secondBase = $this->missileBase($firing);
        $cells = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])
            ->orderBy('id')->limit(3)->get();
        $this->assertCount(3, $cells);
        $wasteland = TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail();
        foreach ($cells as $cell) {
            app(MapCellStateService::class)->setFacility($cell, null);
            app(MapCellStateService::class)->transitionTerrain($cell, $wasteland);
            $cell->owner_nation_id = $target->id;
            $cell->population = 0;
            $cell->save();
        }
        $monster = $this->monster($world, $cells[0], 'mecha_inora_zero');
        $occupancy = $monster->occupancy()->firstOrFail();

        $startOnlyItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space, 'spp_missile', $cells[0],
        );
        $startOnly = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $startOnlyItem,
            [$firstBase],
            2,
            function () use ($occupancy, $cells): void {
                $occupancy->update(['map_cell_id' => $cells[2]->id]);
            },
        );
        $this->assertSame(1, $startOnly['shots_fired']);
        $this->assertTrue($startOnly['classification']['turn_start_monster']);
        $this->assertFalse($startOnly['classification']['missile_boundary_monster']);
        $this->assertTrue($startOnly['classification']['anti_monster_context']);
        $this->assertSame(0, $startOnly['crime_points']);
        $this->assertSame(0, (int) $firing->fresh()->karma);

        $boundaryOnlyItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space, 'spp_missile', $cells[1],
        );
        $boundaryOnly = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $boundaryOnlyItem,
            [$firstBase],
            3,
            function () use ($occupancy, $cells): void {
                $occupancy->update(['map_cell_id' => $cells[1]->id]);
            },
        );
        $this->assertSame(1, $boundaryOnly['shots_fired']);
        $this->assertFalse($boundaryOnly['classification']['turn_start_monster']);
        $this->assertTrue($boundaryOnly['classification']['missile_boundary_monster']);
        $this->assertTrue($boundaryOnly['classification']['anti_monster_context']);
        $this->assertSame(0, $boundaryOnly['crime_points']);
        $this->assertSame(3, (int) $monster->fresh()->current_hp);

        $cells[0]->refresh()->load(['terrain', 'facility', 'ownerNation']);
        app(MapCellStateService::class)->transitionTerrain($cells[0], $wasteland);
        $cells[0]->owner_nation_id = $target->id;
        $cells[0]->population = 0;
        $cells[0]->save();
        $monster->update(['current_hp' => 1]);
        $occupancy->update(['map_cell_id' => $cells[0]->id]);
        $frozenItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space,
            'spp_missile', $cells[0], 2,
        );
        $frozen = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $frozenItem,
            [$firstBase, $secondBase],
            4,
            static function (): void {},
        );
        $this->assertSame(2, $frozen['shots_fired']);
        $this->assertTrue($frozen['classification']['turn_start_monster']);
        $this->assertTrue($frozen['classification']['missile_boundary_monster']);
        $this->assertTrue($frozen['classification']['anti_monster_context']);
        $this->assertSame(0, $frozen['crime_points'], 'The meaningful post-kill shot must retain the frozen exemption.');
        $this->assertSame('killed', $monster->fresh()->state);
        $this->assertSame(-1, (int) $firing->fresh()->karma,
            'Only the once-per-Turn foreign monster kill reduction may cross zero.');

        $oil = $this->ownedWaterFacility($target, 'seabed_oil_field');
        $oilCoordinate = new GridCoordinate($oil->x, $oil->y);
        $oilFootprint = $this->missileCellIndex($world);
        $oilMonsterCell = null;
        foreach ($oilCoordinate->radius(2) as $coordinate) {
            $candidate = $oilFootprint[$coordinate->x.':'.$coordinate->y] ?? null;
            if ($candidate instanceof MapCell && $candidate->id !== $oil->id) {
                $oilMonsterCell = $candidate;
                break;
            }
        }
        $this->assertInstanceOf(MapCell::class, $oilMonsterCell);
        app(MapCellStateService::class)->setFacility($oilMonsterCell, null);
        app(MapCellStateService::class)->transitionTerrain($oilMonsterCell, $wasteland);
        $oilMonsterCell->owner_nation_id = $target->id;
        $oilMonsterCell->population = 0;
        $oilMonsterCell->save();
        $oilMonster = $this->monster($world, $oilMonsterCell);
        $oilItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space, 'missile', $oil,
        );
        $oilCollateral = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $oilItem,
            [$firstBase],
            5,
            static function (): void {},
            $this->seedForImpactIndex($oilItem, $oil, 2, $oil),
        );
        $this->assertTrue($oilCollateral['classification']['anti_monster_context']);
        $this->assertSame(0, $oilCollateral['crime_points']);
        $this->assertNull($oil->fresh()->facility_definition_id,
            'Destroyed oil remains exempt when the LaunchIntent was classified as anti-monster.');
        $this->assertSame(-1, (int) $firing->fresh()->karma);
        MonsterOccupancy::query()->where('monster_instance_id', $oilMonster->id)->delete();

        $cells[1]->refresh()->load(['terrain', 'facility', 'ownerNation']);
        app(MapCellStateService::class)->transitionTerrain($cells[1], $wasteland);
        $cells[1]->owner_nation_id = $target->id;
        $cells[1]->population = 0;
        $cells[1]->save();
        $landMonster = $this->monster($world, $cells[1]);
        $landItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space,
            'land_destruction_missile', $cells[1],
        );
        $land = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $landItem,
            [$firstBase],
            7,
            static function (): void {},
            $this->seedForImpactIndex($landItem, $cells[1], 2, $cells[1]),
        );
        $this->assertFalse($land['classification']['anti_monster_context']);
        $this->assertSame(10, $land['crime_points']);
        $this->assertSame('removed', $landMonster->fresh()->state);
        $this->assertSame(9, (int) $firing->fresh()->karma,
            'Land destruction remains criminal and its terrain removal is not a player monster kill.');
        $this->assertSame(5, DB::table('audit_events')->where('event_type', 'karma.anti_monster_classified')->count());
    }

    public function test_v13_hundred_shot_intent_uses_only_two_full_monster_snapshots_and_one_classification(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('anti-monster-query-bound');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 0]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $targetCell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'missile',
            $targetCell,
        );
        $nationIds = [$firing->id, $target->id];
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'v13 hundred shot anti monster query bound'),
            $nationIds,
        );
        $context->state->setLifecycleNationIds($nationIds);
        $karma = app(KarmaTurnService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        $context->state->registerLaunchIntent(
            $firing->id,
            'missile',
            $targetCell->x,
            $targetCell->y,
            100,
            $item->id,
        );
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $shotsFired = 0;
        foreach (range(1, 100) as $_shot) {
            $shotsFired += $resolver->processBase(
                $context,
                $space,
                $base->fresh(['terrain', 'facility', 'ownerNation']),
            )['shots_fired'];
        }
        $resolver->finalize($context);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $fullMonsterSnapshots = collect($queries)->filter(static function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            return str_contains($sql, 'monster_occupancies')
                && str_contains($sql, 'monster_instances')
                && str_contains($sql, 'map_spaces');
        })->count();
        $this->assertSame(100, $shotsFired);
        $this->assertSame(2, $fullMonsterSnapshots,
            'A 100-shot LaunchIntent must use only the Turn-start and missile-boundary monster snapshots.');
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'karma.anti_monster_classified')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->count());
        $this->assertSame(0, $context->state->launchIntents()[0]->remainingShots());
    }

    public function test_v13_spp_self_destruct_setup_adds_twenty_once_and_rejects_nonqualifying_end_states(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('spp-hidden-crime');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 0]);
        $space = $this->surfaceMapSpace($world);
        $firstBase = $this->missileBase($firing);
        $secondBase = $this->missileBase($firing);
        $foreignCells = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])
            ->orderBy('id')->limit(4)->get();
        $this->assertCount(4, $foreignCells);
        $ownCell = MapCell::query()->where('owner_nation_id', $firing->id)
            ->whereNotIn('id', [$firstBase->id, $secondBase->id])
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])
            ->orderBy('id')->firstOrFail();

        $qualifying = $this->monster($world, $foreignCells[0], 'mecha_inora_zero');
        $qualifying->update(['current_hp' => 3]);
        $qualifiedItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing, $space,
            'spp_missile', $foreignCells[0], 2,
        );
        $qualified = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world, $firing, $target, $qualifiedItem, [$firstBase, $secondBase], 2, static function (): void {},
        );
        $this->assertSame(2, $qualified['shots_fired']);
        $this->assertSame(1, (int) $qualifying->fresh()->current_hp);
        $this->assertSame(20, $qualified['crime_points']);
        $this->assertSame(20, (int) $firing->fresh()->karma);
        $special = DB::table('audit_events')->where('event_type', 'karma.spp_self_destruct_setup')->sole();
        $this->assertSame('private', $special->visibility);
        $this->assertSame(
            '秘書「試験島主様……先ほどのSPPミサイルの本数ですが……」（カルマ +20）',
            $special->message,
        );

        $own = $this->monster($world, $ownCell, 'mecha_inora_zero');
        $own->update(['current_hp' => 2]);
        $ownItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space, 'spp_missile', $ownCell,
        );
        $this->resolveKarmaLaunchWithBoundaryMutation(
            $world, $firing, $target, $ownItem, [$firstBase], 3, static function (): void {},
        );

        $alreadyOne = $this->monster($world, $foreignCells[1], 'mecha_inora_zero');
        $alreadyOne->update(['current_hp' => 1]);
        $alreadyOneItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space, 'spp_missile', $foreignCells[1],
        );
        $this->resolveKarmaLaunchWithBoundaryMutation(
            $world, $firing, $target, $alreadyOneItem, [$firstBase], 4, static function (): void {},
        );

        $killed = $this->monster($world, $foreignCells[2], 'mecha_inora_zero');
        $killed->update(['current_hp' => 2]);
        $killedItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space,
            'spp_missile', $foreignCells[2], 2,
        );
        $this->resolveKarmaLaunchWithBoundaryMutation(
            $world, $firing, $target, $killedItem, [$firstBase, $secondBase], 5, static function (): void {},
        );

        $aboveOne = $this->monster($world, $foreignCells[3], 'mecha_inora_zero');
        $aboveOneItem = $this->queue(
            app(CommandQueueService::class), $firingUser, $firing->fresh(), $space, 'spp_missile', $foreignCells[3],
        );
        $this->resolveKarmaLaunchWithBoundaryMutation(
            $world, $firing, $target, $aboveOneItem, [$firstBase], 7, static function (): void {},
        );

        $this->assertSame(1, (int) $own->fresh()->current_hp);
        $this->assertSame('killed', $alreadyOne->fresh()->state);
        $this->assertSame('killed', $killed->fresh()->state);
        $this->assertSame(3, (int) $aboveOne->fresh()->current_hp);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'karma.spp_self_destruct_setup')->count(),
            'Own territory, start-at-one, killed, and final-above-one commands must not add the hidden crime.');
    }

    public function test_v13_hostile_player_volley_enters_recovery_after_finishing_and_removes_monsters_without_rewards(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('recovery-entry');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 40]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $firstBase = $this->missileBase($firing);
        $secondBase = $this->missileBase($firing);
        MapCell::query()->where('owner_nation_id', $target->id)->update(['population' => 0]);
        $capital = MapCell::query()->whereKey($target->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $capital->update(['population' => 120]);
        $monsterCell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($capital->id)->whereNull('facility_definition_id')
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $monster = $this->monster($world, $monsterCell);
        $dormantOwner = User::factory()->create();
        $dormant = app(NationCreationService::class)->create(
            $dormantOwner,
            $world,
            '休眠壊滅島',
            '休眠壊滅島主',
        );
        $dormant->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
        ]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $this->surfaceMapSpace($world),
            'spp_missile',
            $capital,
            2,
        );
        $context = $this->context(
            $world,
            2,
            hash('sha256', 'v13 exact recovery entry'),
            [$firing->id, $target->id, $dormant->id],
        );
        $lifecycle = app(NationLifecycleService::class);
        $prepare = $lifecycle->prepare($context);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        $context->state->markRecoveryEntry($dormant->id);
        app(SecretaryTurnService::class)->loadAttemptSnapshots(
            $context,
            $context->state->lifecycleNationIds(),
        );
        app(DomesticCommandExecutor::class)->execute($context);
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $cellIndex = $this->missileCellIndex($world);
        $resolver->begin($cellIndex);
        $shots = $resolver->processBase(
            $context,
            $this->surfaceMapSpace($world),
            $firstBase->fresh(['terrain', 'facility', 'ownerNation']),
        )['shots_fired'];
        $this->assertGreaterThan(100, (int) $capital->fresh()->population);
        $capitalInTurn = $cellIndex[$capital->x.':'.$capital->y];
        $capitalInTurn->update(['population' => 105]);
        $shots += $resolver->processBase(
            $context,
            $this->surfaceMapSpace($world),
            $secondBase->fresh(['terrain', 'facility', 'ownerNation']),
        )['shots_fired'];
        $resolver->finalize($context);
        $alliance = $karma->settleAllianceMoney($context);
        $sanctions = $resolver->resolveSanctions($context);
        $finalizedLifecycle = $lifecycle->finalize($context);
        $finalizedKarma = $karma->finalize($context);

        $this->assertSame(0, $prepare['recovery']);
        $this->assertSame(2, $shots, 'The current hostile volley must finish after recovery entry qualifies.');
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(100, (int) $capital->fresh()->population);
        $this->assertTrue($context->state->karmaLedgerForNation($target->id)['recovery_entry']);
        $this->assertSame(2, $finalizedLifecycle['entered_recovery']);
        $this->assertSame(1, $finalizedLifecycle['recovery_monsters_removed']);
        $this->assertSame('recovery', $target->fresh()->state);
        $this->assertSame(2, (int) $target->fresh()->state_started_turn);
        $this->assertSame(87, (int) $target->fresh()->resume_at_turn);
        $this->assertSame('recovery', $dormant->fresh()->state);
        $this->assertSame(87, (int) $dormant->fresh()->resume_at_turn);
        $this->assertSame(35, (int) $target->fresh()->karma);
        $this->assertSame(2, $finalizedKarma['victim_reductions']);
        $this->assertSame(3, $finalizedKarma['recovery_reductions']);
        $this->assertSame(80, $alliance['requested']);
        $this->assertSame(0, $sanctions['karma_sanction_shots']);
        $this->assertSame('removed', $monster->fresh()->state);
        $this->assertSame('recovery_alliance_removal', $monster->fresh()->removal_reason);
        $this->assertSame(0, DB::table('nation_monster_kill_stats')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'monster.reward_distributed')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'monster.killed')->count());
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'nation.recovery_started',
            'nation_id' => $target->id,
            'visibility' => 'public',
        ]);
        $dormantRecoveryEvent = DB::table('audit_events')
            ->where('event_type', 'nation.recovery_started')
            ->where('nation_id', $dormant->id)
            ->sole();
        $dormantRecoveryMetadata = json_decode(
            (string) $dormantRecoveryEvent->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('dormant', $dormantRecoveryMetadata['before_state']);

        [$latchedOwner, $latchedCandidate] = $this->nation($world, '休戦資格維持島');
        $latchedSecretaryId = DB::table('secretaries')->where('user_id', $latchedOwner->id)->value('id');
        DB::table('secretary_skills')->where('secretary_id', $latchedSecretaryId)
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $firing->update(['money' => 9_999]);
        $firstBase->update(['facility_experience' => 0]);
        MapCell::query()->where('owner_nation_id', $latchedCandidate->id)->update(['population' => 0]);
        $latchedCapital = MapCell::query()->whereKey($latchedCandidate->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $latchedCapital->update(['population' => 105]);
        $latchedCellIndex = $this->missileCellIndex($world);
        $latchedItem = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing->fresh(),
            $this->surfaceMapSpace($world),
            'spp_missile',
            $latchedCapital,
        );
        $latchedContext = $this->context(
            $world,
            3,
            hash('sha256', 'v13 latched recovery qualification'),
            [$firing->id, $latchedCandidate->id],
        );
        $lifecycle->prepare($latchedContext);
        $latchedKarma = app(KarmaTurnService::class);
        $latchedKarma->prepare($latchedContext);
        app(SecretaryTurnService::class)->loadAttemptSnapshots(
            $latchedContext,
            $latchedContext->state->lifecycleNationIds(),
        );
        app(DomesticCommandExecutor::class)->execute($latchedContext);
        $latchedKarma->snapshotMissileBoundary($latchedContext);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($latchedCellIndex);
        $latchedShots = $resolver->processBase(
            $latchedContext,
            $this->surfaceMapSpace($world),
            $firstBase->fresh(['terrain', 'facility', 'ownerNation']),
        )['shots_fired'];
        $this->assertSame(100, (int) $latchedCapital->fresh()->population);
        $this->assertTrue($latchedContext->state->karmaLedgerForNation($latchedCandidate->id)['recovery_entry'],
            'Recovery qualification must latch on the first exact-100 impact.');
        $latchedCapitalInTurn = $latchedCellIndex[$latchedCapital->x.':'.$latchedCapital->y];
        $latchedCapitalInTurn->update(['population' => 105]);
        $resolver->finalize($latchedContext);

        $this->assertSame(1, $latchedShots);
        $this->assertSame(105, (int) $latchedCapital->fresh()->population);
        $this->assertTrue($latchedContext->state->karmaLedgerForNation($latchedCandidate->id)['recovery_entry']);
        $this->assertSame('active', $latchedCandidate->fresh()->state,
            'The lifecycle transition remains deferred until lifecycle finalization.');
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'recovery.entry_qualified')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $latchedItem->id])->count());
        $this->assertSame(1, $lifecycle->finalize($latchedContext)['entered_recovery']);
        $this->assertSame('recovery', $latchedCandidate->fresh()->state);
    }

    public function test_v13_recovery_blocks_hostile_registration_and_revalidates_execution_without_blocking_aid_or_domestic_work(): void
    {
        [$world, $actorUser, $actor, $target] = $this->combatants('recovery-actions');
        $actor->update(['money' => 9_999]);
        $target->update(['money' => 0]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $commands = app(CommandQueueService::class);
        $targets = app(NationCommandTargetService::class);
        $missileDefinition = CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'missile')
            ->firstOrFail();
        $monsterDispatchDefinition = CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'monster_dispatch')
            ->firstOrFail();
        $territoryDefinition = CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'territory_expand')
            ->firstOrFail();
        $targetCell = $target->capital()->firstOrFail()->cell()
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $actorBase = $this->missileBase($actor);
        $preRecoveryMissile = $this->queue(
            $commands,
            $actorUser,
            $actor,
            $space,
            'missile',
            $targetCell,
        );
        $target->update([
            'state' => 'recovery',
            'state_reason' => null,
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
        ]);

        $incoming = $this->context(
            $world,
            2,
            hash('sha256', 'recovery incoming execution revalidation'),
            [$actor->id, $target->id],
        );
        app(NationLifecycleService::class)->prepare($incoming);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($incoming, [$actor->id, $target->id]);
        $moneyBeforeBlockedMissile = (int) $actor->fresh()->money;
        $blocked = app(DomesticCommandExecutor::class)->execute($incoming);

        $this->assertSame(1, $blocked['failures']);
        $this->assertSame('failed', $preRecoveryMissile->fresh()->status);
        $this->assertSame('ceasefire_prohibited', $preRecoveryMissile->fresh()->failure_code);
        $this->assertSame([], $incoming->state->launchIntents());
        $this->assertGreaterThanOrEqual($moneyBeforeBlockedMissile, (int) $actor->fresh()->money);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'command.ceasefire_blocked',
            'nation_id' => $actor->id,
            'turn' => 2,
        ]);

        $this->assertPlayerFacing(
            fn () => $commands->validateTarget(
                $actor->fresh(),
                $space,
                $missileDefinition,
                $targetCell->fresh(['terrain', 'facility', 'ownerNation']),
            ),
            "{$target->name}へのミサイル攻撃は箱庭協定によって禁じられているため、登録できません。",
        );
        $this->assertPlayerFacing(
            fn () => $targets->validateRegistration(
                $actor->fresh(),
                $monsterDispatchDefinition,
                ['target_nation_id' => $target->id],
            ),
            '休戦中の島から、または休戦中の島へ怪獣を派遣できません。',
        );
        $this->assertPlayerFacing(
            fn () => $targets->validateMonumentFlightRegistration($actor->fresh(), $target->id),
            '休戦中の島から、または休戦中の島へ記念碑を発射できません。',
        );

        $target->update([
            'state' => 'active',
            'state_reason' => null,
            'state_started_turn' => null,
            'resume_at_turn' => null,
        ]);
        $actor->update([
            'state' => 'recovery',
            'state_reason' => null,
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
        ]);
        $this->assertPlayerFacing(
            fn () => $commands->validateTarget(
                $actor->fresh(),
                $space,
                $missileDefinition,
                $targetCell->fresh(['terrain', 'facility', 'ownerNation']),
            ),
            "{$target->name}へのミサイル攻撃は箱庭協定によって禁じられているため、登録できません。",
        );
        $this->assertPlayerFacing(
            fn () => $targets->validateRegistration(
                $actor->fresh(),
                $monsterDispatchDefinition,
                ['target_nation_id' => $target->id],
            ),
            '休戦中の島から、または休戦中の島へ怪獣を派遣できません。',
        );
        $this->assertPlayerFacing(
            fn () => $targets->validateMonumentFlightRegistration($actor->fresh(), $target->id),
            '休戦中の島から、または休戦中の島へ記念碑を発射できません。',
        );

        $anchor = $actor->capital()->firstOrFail()->cell()->firstOrFail();
        $neutralCoordinate = (new GridCoordinate($anchor->x, $anchor->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        )[0];
        $neutral = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $neutralCoordinate->x)->where('y', $neutralCoordinate->y)
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->setFacility($neutral, null);
        app(MapCellStateService::class)->transitionTerrain(
            $neutral,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $neutral->owner_nation_id = null;
        $neutral->population = 0;
        $neutral->save();
        $commands->validateTarget(
            $actor->fresh(),
            $space,
            $territoryDefinition,
            $neutral->fresh(['terrain', 'facility']),
        );
        $neutral->update(['owner_nation_id' => $target->id]);
        $this->assertPlayerFacing(
            fn () => $commands->validateTarget(
                $actor->fresh(),
                $space,
                $territoryDefinition,
                $neutral->fresh(['terrain', 'facility', 'ownerNation']),
            ),
            '休戦中の島から、または休戦中の島の領土へ hostile な領土拡張はできません。',
        );
        $neutral->update(['owner_nation_id' => null]);

        $forest = MapCell::query()->where('owner_nation_id', $actor->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $aid = $this->queue(
            $commands,
            $actorUser,
            $actor->fresh(),
            $space,
            'money_aid',
            null,
            parameters: ['target_nation_id' => $target->id],
        );
        $development = $this->queue($commands, $actorUser, $actor->fresh(), $space, 'land_clear', $forest);
        $expansion = $this->queue($commands, $actorUser, $actor->fresh(), $space, 'territory_expand', $neutral);
        $targetMoneyBeforeAid = (int) $target->fresh()->money;
        $allowed = $this->context(
            $world,
            3,
            hash('sha256', 'recovery allowed work'),
            [$actor->id, $target->id],
        );
        app(NationLifecycleService::class)->prepare($allowed);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($allowed, [$actor->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($allowed);
        $nextAllowed = $this->context(
            $world,
            4,
            hash('sha256', 'recovery allowed neutral expansion'),
            [$actor->id, $target->id],
        );
        app(NationLifecycleService::class)->prepare($nextAllowed);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($nextAllowed, [$actor->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($nextAllowed);

        $this->assertSame('completed', $aid->fresh()->status);
        $this->assertGreaterThanOrEqual($targetMoneyBeforeAid + 100, (int) $target->fresh()->money);
        $this->assertSame('completed', $development->fresh()->status);
        $this->assertSame('plain', $forest->fresh()->terrain()->value('key'));
        $this->assertSame('completed', $expansion->fresh()->status);
        $this->assertSame($actor->id, $neutral->fresh()->owner_nation_id);
        $this->assertSame(
            $actor->id,
            $nextAllowed->state->recoveryTerritoryNationId($neutral->x, $neutral->y),
            'Neutral territory acquired during recovery must be protected in the same Turn.',
        );
        $this->assertTrue(app(NationProtectionPolicy::class)->protects(
            $nextAllowed,
            $neutral->x,
            $neutral->y,
        ));
        $this->assertSame('recovery', $actor->fresh()->state);

        [$reclaimTarget, $seabedTarget, $oilTarget] = $this->neutralCellsNearTerritory($actor, $space, 3);
        foreach ([
            [$reclaimTarget, 'shallow'],
            [$seabedTarget, 'sea'],
            [$oilTarget, 'sea'],
        ] as [$acquisitionTarget, $terrainKey]) {
            app(MapCellStateService::class)->setFacility($acquisitionTarget, null);
            app(MapCellStateService::class)->transitionTerrain(
                $acquisitionTarget,
                TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail(),
            );
            $acquisitionTarget->owner_nation_id = null;
            $acquisitionTarget->population = 0;
            $acquisitionTarget->save();
        }
        $reclaim = $this->queue($commands, $actorUser, $actor->fresh(), $space, 'reclaim', $reclaimTarget);
        $actor->update(['money' => 9_999]);
        $reclaimContext = $this->context(
            $world,
            5,
            hash('sha256', 'recovery shallow reclaim acquisition'),
            [$actor->id, $target->id],
        );
        app(NationLifecycleService::class)->prepare($reclaimContext);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($reclaimContext, [$actor->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($reclaimContext);
        $seabedTarget->refresh();
        $oilTarget->refresh();
        foreach ([$seabedTarget, $oilTarget] as $waterTarget) {
            app(MapCellStateService::class)->setFacility($waterTarget, null);
            app(MapCellStateService::class)->transitionTerrain(
                $waterTarget,
                TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
            );
            $waterTarget->owner_nation_id = null;
            $waterTarget->population = 0;
            $waterTarget->save();
        }

        $seabed = $this->queue(
            $commands,
            $actorUser,
            $actor->fresh(),
            $space,
            'build_seabed_base',
            $seabedTarget,
        );
        $actor->update(['money' => 9_999]);
        $seabedContext = $this->context(
            $world,
            6,
            hash('sha256', 'recovery seabed base acquisition'),
            [$actor->id, $target->id],
        );
        app(NationLifecycleService::class)->prepare($seabedContext);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($seabedContext, [$actor->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($seabedContext);

        $oilSearch = $this->queue(
            $commands,
            $actorUser,
            $actor->fresh(),
            $space,
            'excavate',
            $oilTarget,
            5,
        );
        $actor->update(['money' => 9_999]);
        $oilContext = $this->context(
            $world,
            7,
            $this->seedForFirstDraw(TurnRandomStreamFactory::SEABED_OIL_SEARCH, 100, 0),
            [$actor->id, $target->id],
        );
        app(NationLifecycleService::class)->prepare($oilContext);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($oilContext, [$actor->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($oilContext);

        $this->assertSame('completed', $reclaim->fresh()->status);
        $this->assertSame('wasteland', $reclaimTarget->fresh()->terrain()->value('key'));
        $this->assertSame('completed', $seabed->fresh()->status);
        $this->assertSame('seabed_base', $seabedTarget->fresh()->facility()->value('key'));
        $this->assertSame('completed', $oilSearch->fresh()->status);
        $this->assertSame('seabed_oil_field', $oilTarget->fresh()->facility()->value('key'));
        foreach ([
            [$reclaimContext, $reclaimTarget],
            [$seabedContext, $seabedTarget],
            [$oilContext, $oilTarget],
        ] as [$acquisitionContext, $acquisitionTarget]) {
            $this->assertSame($actor->id, $acquisitionTarget->fresh()->owner_nation_id);
            $this->assertSame(
                $actor->id,
                $acquisitionContext->state->recoveryTerritoryNationId(
                    $acquisitionTarget->x,
                    $acquisitionTarget->y,
                ),
            );
            $this->assertTrue(app(NationProtectionPolicy::class)->protects(
                $acquisitionContext,
                $acquisitionTarget->x,
                $acquisitionTarget->y,
            ));
        }

        $selfTarget = MapCell::query()->where('owner_nation_id', $actor->id)
            ->whereKeyNot($actorBase->id)->whereNull('facility_definition_id')
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $selfTarget,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $selfTarget->population = 0;
        $selfTarget->save();
        $excludedImpactCellIds = [
            $selfTarget->id,
            $actorBase->id,
            (int) $actor->capital()->value('map_cell_id'),
            (int) $target->capital()->value('map_cell_id'),
        ];
        $foreignImpactCells = [];
        foreach ((new GridCoordinate($selfTarget->x, $selfTarget->y))->radius(2) as $coordinate) {
            $candidate = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)
                ->with(['terrain', 'facility', 'ownerNation'])->first();
            if ($candidate === null || in_array($candidate->id, $excludedImpactCellIds, true)
                || MonsterOccupancy::query()->where('map_cell_id', $candidate->id)->exists()) {
                continue;
            }
            app(MapCellStateService::class)->setFacility($candidate, null);
            app(MapCellStateService::class)->transitionTerrain(
                $candidate,
                TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
            );
            $candidate->owner_nation_id = $target->id;
            $candidate->population = 0;
            $candidate->save();
            $foreignImpactCells[] = $candidate->fresh(['terrain', 'facility', 'ownerNation']);
            if (count($foreignImpactCells) === 3) {
                break;
            }
        }
        $this->assertCount(3, $foreignImpactCells);
        $antiMonsterTarget = $foreignImpactCells[0];
        $crimeTargets = [$foreignImpactCells[1], $foreignImpactCells[2]];
        $monster = $this->monster($world, $antiMonsterTarget);
        $actorBase->update(['facility_experience' => 200]);
        $actor->update(['money' => 9_999]);

        $antiMonsterMissile = $this->queue(
            $commands,
            $actorUser,
            $actor->fresh(),
            $space,
            'missile',
            $selfTarget,
            2,
        );
        $antiMonsterContext = $this->context(
            $world,
            8,
            $this->seedForImpactSequence(
                $antiMonsterMissile,
                $selfTarget,
                2,
                [$selfTarget, $antiMonsterTarget],
            ),
            [$actor->id, $target->id],
        );
        $lifecycle = app(NationLifecycleService::class);
        $lifecycle->prepare($antiMonsterContext);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($antiMonsterContext);
        app(SecretaryTurnService::class)->loadAttemptSnapshots(
            $antiMonsterContext,
            [$actor->id, $target->id],
        );
        app(DomesticCommandExecutor::class)->execute($antiMonsterContext);
        $karma->snapshotMissileBoundary($antiMonsterContext);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $antiMonsterLaunch = $resolver->processBase(
            $antiMonsterContext,
            $space,
            $actorBase->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $resolver->finalize($antiMonsterContext);

        $this->assertSame('completed', $antiMonsterMissile->fresh()->status);
        $this->assertSame(2, $antiMonsterLaunch['shots_fired']);
        $this->assertSame(0, $antiMonsterContext->state->karmaLedgerForNation($actor->id)['crime_points']);
        $this->assertSame('recovery', $actor->fresh()->state);
        $this->assertSame('scorched', $selfTarget->fresh()->terrain()->value('key'),
            'A recovery Nation must not protect its own legal missile impact from itself.');
        $this->assertNotSame('removed', $monster->fresh()->state);
        $antiMonsterImpact = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'karma.missile_impact')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $antiMonsterMissile->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($antiMonsterImpact['anti_monster_exempt']);
        $this->assertSame(0, $antiMonsterImpact['crime_points']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.recovery_ended')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $antiMonsterMissile->id])->count());

        $actor->update(['money' => 9_999]);
        $target->update(['money' => 9_999]);
        $targetBase = $this->missileBase($target);
        $actorCapital = $actor->capital()->firstOrFail()->cell()
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        MapCell::query()->where('owner_nation_id', $actor->id)->update(['population' => 0]);
        $actorCapital->update(['population' => 105]);
        $followupAim = null;
        foreach ((new GridCoordinate($actorCapital->x, $actorCapital->y))->radius(2) as $coordinate) {
            $candidate = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)
                ->with(['terrain', 'facility', 'ownerNation'])->first();
            if ($candidate === null || in_array($candidate->id, [
                $actorCapital->id,
                $actorBase->id,
                $targetBase->id,
                $selfTarget->id,
            ], true)) {
                continue;
            }
            app(MapCellStateService::class)->setFacility($candidate, null);
            app(MapCellStateService::class)->transitionTerrain(
                $candidate,
                TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
            );
            $candidate->owner_nation_id = $target->id;
            $candidate->population = 0;
            $candidate->save();
            $followupAim = $candidate->fresh(['terrain', 'facility', 'ownerNation']);
            break;
        }
        $this->assertInstanceOf(MapCell::class, $followupAim);
        $crimeMissile = $this->queue(
            $commands,
            $actorUser,
            $actor->fresh(),
            $space,
            'land_destruction_missile',
            $selfTarget,
            2,
        );
        $targetUser = User::query()->findOrFail((int) DB::table('nation_memberships')
            ->where('nation_id', $target->id)->value('user_id'));
        $followupMissile = $this->queue(
            $commands,
            $targetUser,
            $target->fresh(),
            $space,
            'missile',
            $followupAim,
        );
        $crimeContext = $this->context(
            $world,
            9,
            $this->seedForImpactSequences([
                [
                    'item' => $crimeMissile,
                    'aim' => $selfTarget,
                    'radius' => 2,
                    'desired' => $crimeTargets,
                ],
                [
                    'item' => $followupMissile,
                    'aim' => $followupAim,
                    'radius' => 2,
                    'desired' => [$actorCapital],
                ],
            ]),
            [$actor->id, $target->id],
        );
        $lifecycle->prepare($crimeContext);
        $crimeKarma = app(KarmaTurnService::class);
        $crimeKarma->prepare($crimeContext);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($crimeContext, [$actor->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($crimeContext);
        $crimeKarma->snapshotMissileBoundary($crimeContext);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $crimeLaunch = $resolver->processBase(
            $crimeContext,
            $space,
            $actorBase->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $followupLaunch = $resolver->processBase(
            $crimeContext,
            $space,
            $targetBase->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $resolver->finalize($crimeContext);
        $lifecycleMetrics = $lifecycle->finalize($crimeContext);
        $crimeKarma->finalize($crimeContext);

        $this->assertSame('completed', $crimeMissile->fresh()->status);
        $this->assertSame(2, $crimeLaunch['shots_fired'],
            'The remaining LaunchIntent shot must continue after the first criminal impact ends recovery.');
        $this->assertSame('completed', $followupMissile->fresh()->status);
        $this->assertSame(1, $followupLaunch['shots_fired']);
        $this->assertTrue($crimeContext->state->karmaLedgerForNation($actor->id)['recovery_entry']);
        $this->assertSame(1, $lifecycleMetrics['entered_recovery']);
        $this->assertSame('recovery', $actor->fresh()->state);
        $this->assertSame(94, $actor->fresh()->resume_at_turn);
        $this->assertSame(20, $crimeContext->state->karmaLedgerForNation($actor->id)['crime_points']);
        $this->assertSame(17, (int) $actor->fresh()->karma);
        $crimeImpacts = DB::table('audit_events')->where('event_type', 'karma.missile_impact')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $crimeMissile->id])
            ->orderBy('id')->get()->map(static fn (object $event): array => json_decode(
                (string) $event->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            ));
        $this->assertCount(2, $crimeImpacts);
        $this->assertSame([10, 10], $crimeImpacts->pluck('crime_points')->all());
        $recoveryEnded = DB::table('audit_events')->where('event_type', 'nation.recovery_ended')
            ->where('nation_id', $actor->id)
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $crimeMissile->id])->sole();
        $recoveryEndedMetadata = json_decode(
            (string) $recoveryEnded->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('public', $recoveryEnded->visibility);
        $this->assertSame('karma_crime', $recoveryEndedMetadata['exit_trigger']);
        $this->assertSame(10, $recoveryEndedMetadata['crime_points']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.recovery_started')
            ->where('nation_id', $actor->id)->where('turn', 9)->count());
        $this->assertNull($crimeContext->state->recoveryTerritoryNationId($selfTarget->x, $selfTarget->y));
        $this->assertFalse(app(NationProtectionPolicy::class)->protects(
            $crimeContext,
            $selfTarget->x,
            $selfTarget->y,
        ));

        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.launch_failed')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $antiMonsterMissile->id])->count());
        $aidMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.money_aid_transferred')
            ->whereRaw("metadata->>'sender_nation_id' = ?", [(string) $actor->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(100, $aidMetadata['transferred_money']);
    }

    public function test_v13_sanction_overflow_reuses_defense_secretary_and_canonical_impact_without_feedback(): void
    {
        [$world, $_firstUser, $first, $second] = $this->combatants('sanctions');
        [, $third] = $this->nation($world, '制裁第三国');
        foreach ([$first, $second, $third] as $nation) {
            $nation->update(['karma' => 100]);
        }
        $space = $this->surfaceMapSpace($world);
        $firstCapital = MapCell::query()->whereKey($first->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $secondCapital = MapCell::query()->whereKey($second->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $thirdImpact = MapCell::query()->where('owner_nation_id', $third->id)
            ->whereKeyNot($third->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])
            ->firstOrFail();
        MapCell::query()->whereIn('owner_nation_id', [$first->id, $second->id, $third->id])
            ->update(['owner_nation_id' => null]);
        app(MapCellStateService::class)->setFacility(
            $thirdImpact,
            FacilityDefinition::query()->where('key', 'defense')->firstOrFail(),
        );
        $thirdImpact->save();
        MapCell::query()->whereKey($firstCapital->id)->update(['owner_nation_id' => $first->id]);
        MapCell::query()->whereKey($secondCapital->id)->update(['owner_nation_id' => $second->id]);
        MapCell::query()->whereKey($thirdImpact->id)->update([
            'owner_nation_id' => $third->id,
            'population' => 0,
        ]);
        $externalDefense = $this->placeFacilityAtDistance($space, $firstCapital, $second, 1, 'defense');
        $externalDefense->update(['owner_nation_id' => null]);

        DB::table('secretary_skills')->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $secondSecretaryId = DB::table('secretaries')->where('user_id', DB::table('nation_memberships')
            ->where('nation_id', $second->id)->where('role', 'owner')->value('user_id'))->value('id');
        DB::table('secretary_skills')->where('secretary_id', $secondSecretaryId)
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)->update(['level' => 1]);

        $context = $this->context(
            $world,
            2,
            hash('sha256', 'v13 sanction canonical reuse'),
            [$first->id, $second->id, $third->id],
        );
        app(NationLifecycleService::class)->prepare($context);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots(
            $context,
            $context->state->lifecycleNationIds(),
        );
        foreach ([$first, $second, $third] as $nation) {
            $context->state->addKarmaCrime($nation->id, 1);
        }
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $metrics = $resolver->resolveSanctions($context);
        $karma->finalize($context);

        $this->assertSame([
            'karma_sanction_nations' => 3,
            'karma_sanction_shots' => 3,
            'karma_sanction_intercepted' => 2,
            'karma_sanction_impacts' => 1,
        ], $metrics);
        $this->assertNotNull($externalDefense->fresh()->facility_definition_id);
        $this->assertSame(1000, (int) $firstCapital->fresh()->population);
        $this->assertSame(1000, (int) $secondCapital->fresh()->population);
        $this->assertNull($thirdImpact->fresh()->facility_definition_id,
            'A defense facility on the impact cell cannot protect itself from a sanction.');
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.defense_intercepted')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'secretary.missile_intercepted')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'refugee_generated')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'karma.alliance_money')->count());
        foreach ([$first, $second, $third] as $nation) {
            $this->assertSame(
                1,
                $context->state->pendingSecretaryExperience()[$nation->id][SecretarySkillCatalog::FINAL_DEFENSE_LINE],
            );
            $this->assertSame(1, $context->state->karmaLedgerForNation($nation->id)['sanction_count']);
            $this->assertSame(0, $context->state->karmaLedgerForNation($nation->id)['hostile_impacts_received']);
            $this->assertFalse($context->state->karmaLedgerForNation($nation->id)['recovery_entry']);
            $this->assertSame(100, (int) $nation->fresh()->karma);
        }
    }
}
