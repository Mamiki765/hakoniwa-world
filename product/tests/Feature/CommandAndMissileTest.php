<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\KarmaTurnService;
use App\Application\MissileImpactResolver;
use App\Application\MonsterRemovalService;
use App\Application\NationCommandTargetService;
use App\Application\NationCreationService;
use App\Application\NationLifecycleService;
use App\Application\PlayerIslandEventService;
use App\Application\SecretaryNamingService;
use App\Application\SecretaryTurnService;
use App\Domain\Command\PlayerFacingCommandException;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Facility\MissileBaseRules;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Nation\NationProtectionPolicy;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
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
        ], $metrics);
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

        MapCell::query()->where('owner_nation_id', $target->id)->update(['population' => 0]);
        $capital = MapCell::query()->whereKey($target->capital()->value('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $capital->update(['population' => 200]);
        $this->resolveKarmaMissileTurn(
            $world, $firingUser, $firing, $target, $base, 'spp_missile', $capital, $turn++,
        );
        $this->assertSame(38, (int) $firing->fresh()->karma);
        $capital->refresh()->update(['population' => 100]);
        $this->resolveKarmaMissileTurn(
            $world, $firingUser, $firing, $target, $base, 'spp_missile', $capital, $turn,
        );
        $this->assertSame(38, (int) $firing->fresh()->karma);
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
        $capital->update(['population' => 110]);
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
        $resolver->begin($this->missileCellIndex($world));
        $shots = $resolver->processBase(
            $context,
            $this->surfaceMapSpace($world),
            $firstBase->fresh(['terrain', 'facility', 'ownerNation']),
        )['shots_fired'];
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
        $this->assertSame(36, (int) $target->fresh()->karma);
        $this->assertSame(1, $finalizedKarma['victim_reductions']);
        $this->assertSame(3, $finalizedKarma['recovery_reductions']);
        $this->assertSame(40, $alliance['requested']);
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
    }

    public function test_v13_recovery_blocks_hostile_registration_and_revalidates_execution_without_blocking_aid_or_domestic_work(): void
    {
        [$world, $actorUser, $actor, $target] = $this->combatants('recovery-actions');
        $actor->update(['money' => 9_999]);
        $target->update(['money' => 0]);
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

        $selfTarget = MapCell::query()->where('owner_nation_id', $actor->id)
            ->whereKeyNot($actorBase->id)->whereNull('facility_definition_id')
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $selfTarget,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $selfTarget->population = 0;
        $selfTarget->save();
        $selfMissile = $this->queue(
            $commands,
            $actorUser,
            $actor->fresh(),
            $space,
            'missile',
            $selfTarget,
        );
        $selfContext = $this->context(
            $world,
            5,
            $this->seedForImpactIndex($selfMissile, $selfTarget, 2, $selfTarget),
            [$actor->id, $target->id],
        );
        app(NationLifecycleService::class)->prepare($selfContext);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($selfContext);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($selfContext, [$actor->id, $target->id]);
        app(DomesticCommandExecutor::class)->execute($selfContext);
        $karma->snapshotMissileBoundary($selfContext);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $selfLaunch = $resolver->processBase(
            $selfContext,
            $space,
            $actorBase->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $resolver->finalize($selfContext);

        $this->assertSame('completed', $selfMissile->fresh()->status);
        $this->assertSame(1, $selfLaunch['shots_fired']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'missile.launch_failed')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $selfMissile->id])->count());
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
            $this->assertSame(1, $context->state->karmaLedgerForNation($nation->id)['sanction_count']);
            $this->assertSame(0, $context->state->karmaLedgerForNation($nation->id)['hostile_impacts_received']);
            $this->assertFalse($context->state->karmaLedgerForNation($nation->id)['recovery_entry']);
            $this->assertSame(100, (int) $nation->fresh()->karma);
        }
    }

    #[DataProvider('v8DefenseMissileProvider')]
    public function test_v8_radius_two_defense_intercepts_every_source_missile_kind(
        string $missileKey,
        int $deviationRadius,
    ): void {
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
            $missileKey,
            $target,
        );
        $context = $this->context(
            $world,
            2,
            $this->seedForImpactIndex($item, $target, $deviationRadius, $target),
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

    /** @return array<string, array{string, int}> */
    public static function v8DefenseMissileProvider(): array
    {
        return [
            'normal missile' => ['missile', 2],
            'PP missile' => ['pp_missile', 1],
            'land destruction missile' => ['land_destruction_missile', 2],
            'SPP missile' => ['spp_missile', 0],
        ];
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
        $this->assertSame('hakoniwa-2s-plus-v13', $world->rulesetVersion()->value('key'));
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
        $context = $this->context($world, 2, $seed, [$firing->id, $dormant->id]);
        $context->state->setNationLifecycleSnapshot($dormant->id, [
            'state' => 'dormant',
            'reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);

        $this->resolveMissile($context, $base);

        $this->assertSame($snapshot, $dormantCell->fresh()->only(array_keys($snapshot)));
        $this->assertTrue(MonsterOccupancy::query()->where('monster_instance_id', $monster->id)
            ->where('map_cell_id', $dormantCell->id)->exists());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'missile.ineffective_aggregated')->count());
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('dormant_capital_protected', $detail['impacts'][0]['effect']);
        $this->assertSame(
            "{$dormant->name}({$dormantCell->x},{$dormantCell->y})にミサイルが落下しましたが、まるで時間が止まったかのように動かなくなった後、空中で自爆しました",
            DB::table('audit_events')->where('event_type', 'missile.dormancy_protected')->value('message'),
        );
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

    public function test_v13_zero_point_monster_impact_still_reduces_victim_and_credits_alliance_money(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('karma-monster-impact');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 20]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])
            ->firstOrFail();
        app(MapCellStateService::class)->setFacility($cell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $cell->population = 0;
        $cell->save();
        $monster = $this->monster($world, $cell);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'missile',
            $cell,
        );
        $cost = (int) $item->definition()->value('cost_money');

        $result = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $item,
            [$base],
            2,
            static function (): void {},
            $this->seedForImpactIndex($item, $cell, 2, $cell),
        );

        $this->assertSame(1, $result['shots_fired']);
        $this->assertTrue($result['classification']['anti_monster_context']);
        $this->assertSame(0, $result['crime_points']);
        $this->assertSame(1, (int) $monster->fresh()->current_hp);
        $this->assertSame(19, (int) $target->fresh()->karma);
        $this->assertSame(9_999 - $cost + 20, (int) $firing->fresh()->money);
        $impact = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'karma.missile_impact')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $impact['impact_category_points']);
        $this->assertSame(0, $impact['crime_points']);
        $this->assertSame(20, $impact['alliance_money']);
    }

    /** @return array{World, User, Nation, Nation} */
    private function combatants(string $suffix = ''): array
    {
        $world = $this->lightweightWorld();
        [$user, $firing] = $this->nation($world, '発射国'.$suffix);
        [, $target] = $this->nation($world, '標的国'.$suffix);
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

    private function placeFacilityAtDistance(
        MapSpace $space,
        MapCell $center,
        Nation $owner,
        int $distance,
        string $facilityKey,
    ): MapCell {
        $coordinate = collect((new GridCoordinate($center->x, $center->y))->ring($distance))
            ->first(static fn (GridCoordinate $candidate): bool => $candidate->x >= $space->min_x
                && $candidate->x <= $space->max_x
                && $candidate->y >= $space->min_y
                && $candidate->y <= $space->max_y);
        $this->assertInstanceOf(GridCoordinate::class, $coordinate);
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
        );
        $cell->owner_nation_id = $owner->id;
        $cell->population = 0;
        $cell->version++;
        $cell->save();

        return $cell->fresh(['terrain', 'facility', 'ownerNation']);
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

    private function monster(World $world, MapCell $cell, string $monsterKey = 'inora'): MonsterInstance
    {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', $monsterKey)->firstOrFail();
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

    private function monsterArena(World $world, Nation $owner): MapCell
    {
        $space = $this->surfaceMapSpace($world);
        $origin = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')
            ->whereBetween('x', [$space->min_x + 3, $space->max_x - 3])
            ->whereBetween('y', [$space->min_y + 3, $space->max_y - 3])
            ->whereDoesntHave('monsterOccupancy')
            ->with(['terrain', 'facility'])
            ->orderBy('id')
            ->firstOrFail();
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        foreach ((new GridCoordinate($origin->x, $origin->y))->radius(2) as $coordinate) {
            $cell = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)
                ->with(['terrain', 'facility'])->firstOrFail();
            app(MapCellStateService::class)->setFacility($cell, null);
            app(MapCellStateService::class)->transitionTerrain($cell, $plain);
            $cell->owner_nation_id = $owner->id;
            $cell->population = 0;
            $cell->save();
        }
        app(MapCellStateService::class)->transitionTerrain(
            $origin,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $origin->save();

        return $origin->fresh(['terrain', 'facility', 'ownerNation']);
    }

    private function executeCellResolution(TurnContext $context, MapCell ...$centers): void
    {
        $space = $this->surfaceMapSpace($context->world);
        $coordinates = [];
        foreach ($centers as $center) {
            foreach ((new GridCoordinate($center->x, $center->y))->radius(2) as $coordinate) {
                if ($coordinate->x >= $space->min_x && $coordinate->x <= $space->max_x
                    && $coordinate->y >= $space->min_y && $coordinate->y <= $space->max_y) {
                    $coordinates[$coordinate->x.':'.$coordinate->y] = $coordinate;
                }
            }
        }
        $cellIds = MapCell::query()->where('map_space_id', $space->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where('x', $coordinate->x)
                        ->where('y', $coordinate->y));
                }
            })->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $centerIds = array_map(static fn (MapCell $cell): int => $cell->id, $centers);
        $context->state->setSurfaceCellIds(array_values(array_unique([
            ...$centerIds,
            ...$cellIds,
        ])));
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $context->state->stableNationIds());
        app(DomesticCommandExecutor::class)->execute($context);
        app(CompleteTurnEngine::class)->execute('process_cells', $context);
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
        $resolver->begin($this->missileCellIndex($context->world));
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
        $resolver->begin($this->missileCellIndex($context->world));
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

    private function resolveKarmaMissileTurn(
        World $world,
        User $user,
        Nation $firing,
        Nation $target,
        MapCell $base,
        string $missileKey,
        MapCell $targetCell,
        int $targetTurn,
    ): void {
        $item = $this->queue(
            app(CommandQueueService::class),
            $user,
            $firing->fresh(),
            $this->surfaceMapSpace($world),
            $missileKey,
            $targetCell->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $this->resolvePreparedKarmaMissileTurn(
            $world,
            $firing,
            $target,
            $base,
            $item,
            $targetTurn,
            hash('sha256', "v13 karma category {$targetTurn} {$missileKey}"),
        );
    }

    private function resolvePreparedKarmaMissileTurn(
        World $world,
        Nation $firing,
        Nation $target,
        MapCell $base,
        NationCommandQueueItem $item,
        int $targetTurn,
        string $seed,
    ): void {
        $nationIds = [$firing->id, $target->id];
        $context = $this->context($world, $targetTurn, $seed, $nationIds);
        $context->state->setLifecycleNationIds($nationIds);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        app(DomesticCommandExecutor::class)->execute($context);
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $metrics = $resolver->processBase(
            $context,
            $this->surfaceMapSpace($world),
            $base->fresh(['terrain', 'facility', 'ownerNation']),
        );
        $itemState = $item->fresh();
        $this->assertSame(1, $metrics['shots_fired'], sprintf(
            'Queue item %d must fire exactly once; status=%s failure=%s.',
            $item->id,
            $itemState->status,
            json_encode($itemState->failure_metadata, JSON_THROW_ON_ERROR),
        ));
        $resolver->finalize($context);
        $karma->settleAllianceMoney($context);
        $resolver->resolveSanctions($context);
        $karma->finalize($context);
    }

    /**
     * @param  list<MapCell>  $bases
     * @return array{
     *     shots_fired: int,
     *     crime_points: int,
     *     classification: array{
     *         turn_start_monster: bool,
     *         missile_boundary_monster: bool,
     *         anti_monster_context: bool
     *     }
     * }
     */
    private function resolveKarmaLaunchWithBoundaryMutation(
        World $world,
        Nation $firing,
        Nation $target,
        NationCommandQueueItem $item,
        array $bases,
        int $targetTurn,
        callable $boundaryMutation,
        ?string $seed = null,
    ): array {
        $nationIds = [$firing->id, $target->id];
        $context = $this->context(
            $world,
            $targetTurn,
            $seed ?? hash('sha256', "v13 anti monster {$targetTurn} {$item->id}"),
            $nationIds,
        );
        $context->state->setLifecycleNationIds($nationIds);
        $karma = app(KarmaTurnService::class);
        $karma->prepare($context);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        app(DomesticCommandExecutor::class)->execute($context);
        $boundaryMutation();
        $karma->snapshotMissileBoundary($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($world));
        $shotsFired = 0;
        foreach ($bases as $base) {
            $shotsFired += $resolver->processBase(
                $context,
                $this->surfaceMapSpace($world),
                $base->fresh(['terrain', 'facility', 'ownerNation']),
            )['shots_fired'];
        }
        $resolver->finalize($context);
        $karma->settleAllianceMoney($context);
        $resolver->resolveSanctions($context);
        $karma->finalize($context);
        $classification = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'karma.anti_monster_classified')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);

        return [
            'shots_fired' => $shotsFired,
            'crime_points' => $context->state->karmaLedgerForNation($firing->id)['crime_points'],
            'classification' => [
                'turn_start_monster' => $classification['turn_start_monster'],
                'missile_boundary_monster' => $classification['missile_boundary_monster'],
                'anti_monster_context' => $classification['anti_monster_context'],
            ],
        ];
    }

    /** @return array<string, MapCell> */
    private function missileCellIndex(World $world): array
    {
        return MapCell::query()
            ->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->with(['terrain', 'facility', 'ownerNation'])
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn (MapCell $cell): array => [$cell->x.':'.$cell->y => $cell])
            ->all();
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

    /** @param callable(): mixed $action */
    private function assertPlayerFacing(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected a player-facing command rejection.');
        } catch (PlayerFacingCommandException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
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

    /** @param list<MapCell> $desired */
    private function seedForImpactSequence(
        NationCommandQueueItem $item,
        MapCell $aim,
        int $radius,
        array $desired,
    ): string {
        $candidates = (new GridCoordinate($aim->x, $aim->y))->radius($radius);
        $coordinates = array_map(
            static fn (GridCoordinate $candidate): string => $candidate->x.':'.$candidate->y,
            $candidates,
        );
        $indices = array_map(function (MapCell $cell) use ($coordinates): int {
            $index = array_search($cell->x.':'.$cell->y, $coordinates, true);
            $this->assertIsInt($index);

            return $index;
        }, $desired);
        $label = TurnRandomStreamFactory::missileImpact($item->id);

        for ($candidate = 0; $candidate < 10_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream($label);
            $draws = array_map(
                static fn (): int => $stream->integer(0, count($candidates) - 1),
                $indices,
            );
            if ($draws === $indices) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic missile sequence for {$label}.");
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
