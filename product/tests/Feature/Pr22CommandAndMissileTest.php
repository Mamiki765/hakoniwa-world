<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\DomesticCommandExecutor;
use App\Application\MissileImpactResolver;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class Pr22CommandAndMissileTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_failed_command_continues_to_finance_and_idle_counter_changes_once_per_target_turn(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '資金繰り国');
        $space = $this->surfaceMapSpace($world);
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();
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
        $this->assertSame(1, $nation->fresh()->idle_counter);
        $this->assertSame('queued', $logging->fresh()->status);

        $second = app(DomesticCommandExecutor::class)->execute($this->context($world, 3, str_repeat('2', 64), [$nation->id]));
        $this->assertSame(1, $second['finance_commands']);
        $this->assertSame(1, $second['idle_counter_increments']);
        $this->assertSame(2, $nation->fresh()->idle_counter);

        $third = app(DomesticCommandExecutor::class)->execute($this->context($world, 4, str_repeat('3', 64), [$nation->id]));
        $this->assertSame(1, $third['successes']);
        $this->assertSame(1, $third['idle_counter_resets']);
        $this->assertSame(0, $nation->fresh()->idle_counter);
        $this->assertSame('completed', $logging->fresh()->status);
        $this->assertSame('wasteland', $forest->fresh()->terrain()->value('key'));

        $event = DB::table('audit_events')->where('event_type', 'command.failed')
            ->where('subject_id', $failed->id)->firstOrFail();
        $this->assertSame('nation', $event->visibility);
        $metadata = json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_terrain', $metadata['failure_reason']);
        $this->assertArrayHasKey('observed', $metadata);
        $this->assertArrayHasKey('original_parameters', $metadata);

        $page = app(PlayerIslandEventService::class)->page($nation->fresh(), 1, 4);
        $messages = collect($page['groups'])->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($messages->contains(
            fn (string $message): bool => str_contains($message, '農場建設可能な平地ではありませんでした'),
        ));
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
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $space, 'reclaim', $target);

        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'reclaim missing adjacent territory'),
            [$nation->id],
        ));

        $this->assertSame('missing_adjacent_territory', $item->fresh()->failure_code);
        $page = app(PlayerIslandEventService::class)->page($nation, 1, 2);
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

        $this->resolveMissile($context, $base);

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
        $detail = DB::table('audit_events')->where('event_type', 'missile.launch_detail')->firstOrFail();
        $this->assertSame('private', $detail->visibility);
        $detailMetadata = json_decode((string) $detail->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($capital->x, $detailMetadata['target_x']);
        $this->assertSame($capital->y, $detailMetadata['target_y']);
        $this->assertSame(500, $detailMetadata['cost_money']);
        $this->assertCount(1, $detailMetadata['impacts']);
        $this->assertSame('completed', $item->fresh()->status);

        $targetMessages = collect(app(PlayerIslandEventService::class)->page($target, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($targetMessages->contains(
            fn (string $message): bool => str_contains($message, '発射国がSPPミサイルを1発を発射しました。'),
        ));
        $this->assertFalse($targetMessages->contains(
            fn (string $message): bool => str_contains($message, '狙点'),
        ));
        $firingMessages = collect(app(PlayerIslandEventService::class)->page($firing, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($firingMessages->contains(
            fn (string $message): bool => str_contains($message, '（秘密）SPPミサイルを狙点')
                && str_contains($message, '費用500億円')
                && str_contains($message, '着弾結果:'),
        ));
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

        $targetEvents = collect(app(PlayerIslandEventService::class)->page($target, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $firingEvents = collect(app(PlayerIslandEventService::class)->page($firing, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertCount(1, $targetEvents->where('type', 'refugee_generated'));
        $this->assertCount(1, $firingEvents->where('type', 'refugee_received'));
        $this->assertTrue($targetEvents->where('type', 'refugee_generated')->contains(
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
            TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
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

        [, $spectator] = $this->nation($world, '公開ログ確認国');
        $publicEvents = collect(app(PlayerIslandEventService::class)->page($spectator, 1, 9)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $forestMessages = $publicEvents->filter(
            fn (array $event): bool => $event['message'] === 'こころなしか、どこかで森が増えた気がします。',
        );
        $this->assertCount(2, $forestMessages);
        $this->assertTrue($forestMessages->every(
            fn (array $event): bool => $event['type'] === 'command.forest_planted_public'
                && $event['coordinate'] === null,
        ));
        $this->assertFalse($publicEvents->contains(
            fn (array $event): bool => in_array($event['type'], [
                'command.missile_base_built_public',
                'command.decoy_built_public',
            ], true),
        ));
        $this->assertTrue($publicEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.seabed_base_built_public'
                && $event['coordinate'] === null
                && str_contains($event['message'], '(?,?)'),
        ));
        $this->assertSame(2, $publicEvents->where('type', 'command.facility_built_public')
            ->filter(fn (array $event): bool => str_contains($event['message'], '防衛施設'))->count());
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
        $senderMessages = collect(app(PlayerIslandEventService::class)->page($sender, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $receiverMessages = collect(app(PlayerIslandEventService::class)->page($receiver, 1, 2)['groups'])
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

        $attraction = $this->queue($service, $user, $sender, $space, 'attraction', null);
        $sender->update(['money' => 1_000]);
        $attractionContext = $this->context($world, 3, hash('sha256', 'attraction command'), [$sender->id]);
        app(DomesticCommandExecutor::class)->execute($attractionContext);
        $this->assertTrue($attractionContext->state->hasAttraction($sender->id));
        $this->assertSame(0, $sender->fresh()->money);
        $this->assertSame('completed', $attraction->fresh()->status);
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

    private function resolveMissile(TurnContext $context, MapCell $base): void
    {
        app(DomesticCommandExecutor::class)->execute($context);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin();
        $resolver->processBase($context, $this->surfaceMapSpace($context->world), $base);
        $resolver->finalize($context);
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
