<?php

namespace Tests\Feature;

use App\Application\CapitalPlacementService;
use App\Application\CommandQueueService;
use App\Application\DisasterMutableCellIndex;
use App\Application\DomesticCommandExecutor;
use App\Application\InitialIslandGenerator;
use App\Application\MonsterDamageService;
use App\Application\MonsterRemovalService;
use App\Application\MonsterTurnService;
use App\Application\MonsterWorldSpawnService;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Application\RulesetPublisher;
use App\Domain\Command\CommandRequestConflictException;
use App\Domain\Command\HistoricalMonsterDispatchRequestInspector;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\NationMonsterKillStat;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\V11SecretaryItemRulesetFixture;
use Tests\TestCase;

final class C4NewMonstersTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_zero_dispatch_uses_the_selector_for_price_definition_provenance_and_fingerprint(): void
    {
        [$world, $ruleset] = $this->v11World();
        $user = User::factory()->create();
        $sender = app(NationCreationService::class)->create($user, $world, '派遣元', '派遣主');
        $target = app(NationCreationService::class)->create(User::factory()->create(), $world, '派遣先', '対象主');
        $sender->update(['money' => 9_999]);
        $space = $this->surfaceMapSpace($world);
        $requestKey = (string) Str::uuid();
        $result = app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 1,
            quantity: 2,
            parameters: ['target_nation_id' => $target->id],
            quantityProvided: true,
        );
        $this->assertSame($ruleset->id, $result['item']->request_ruleset_version_id);
        try {
            app(CommandQueueService::class)->add(
                user: $user,
                nation: $sender,
                mapSpace: $space,
                commandKey: 'monster_dispatch',
                targetX: null,
                targetY: null,
                requestKey: $requestKey,
                expectedVersion: 2,
                quantity: 1,
                parameters: ['target_nation_id' => $target->id],
                quantityProvided: true,
            );
            $this->fail('Changing only the dispatch selector must conflict with the original request fingerprint.');
        } catch (CommandRequestConflictException) {
            $this->addToAssertionCount(1);
        }

        $context = $this->context($world, $ruleset, 2, 'zero-dispatch', [$sender->id]);
        app(DomesticCommandExecutor::class)->execute($context);

        $monster = MonsterInstance::query()->with('definition')->sole();
        $this->assertSame('mecha_inora_zero', $monster->definition->key);
        $this->assertSame(4, $monster->current_hp);
        $this->assertSame(0, $sender->fresh()->money);
        $metadata = $this->eventMetadata('command.monster_dispatched');
        $this->assertSame(2, $metadata['dispatch_selector']);
        $this->assertSame(9_999, $metadata['cost_money']);
        $this->assertSame('mecha_inora_zero', $metadata['monster_key']);
        $this->assertSame(0, app(MonsterTurnService::class)->load($context)->metrics()['monsters_loaded']);
        $this->assertContains($monster->id, $context->state->monsterIdsDeferredFromSpawnTurnMovement());
    }

    public function test_v11_dispatch_rejects_untrusted_selector_inputs_and_reports_the_dynamic_failure_cost(): void
    {
        [$world, $ruleset] = $this->v11World();
        $user = User::factory()->create();
        $sender = app(NationCreationService::class)->create($user, $world, '選択元', '選択主');
        $target = app(NationCreationService::class)->create(User::factory()->create(), $world, '選択先', '対象主');
        $space = $this->surfaceMapSpace($world);
        $path = "/api/v1/nations/{$sender->id}/map-spaces/{$space->id}/command-queue";
        $base = [
            'command_key' => 'monster_dispatch',
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
            'parameters' => ['target_nation_id' => $target->id],
        ];
        foreach ([
            $base,
            [...$base, 'request_key' => (string) Str::uuid(), 'quantity' => 0],
            [...$base, 'request_key' => (string) Str::uuid(), 'quantity' => 3],
            [...$base, 'request_key' => (string) Str::uuid(), 'quantity' => 2, 'monster_key' => 'inora'],
            [...$base, 'request_key' => (string) Str::uuid(), 'quantity' => 2, 'cost_money' => 1],
        ] as $payload) {
            $this->actingAs($user)->postJson($path, $payload)->assertUnprocessable();
            $this->assertDatabaseCount('nation_command_queue_items', 0);
        }

        $sender->update(['money' => 9_998]);
        $this->actingAs($user)->postJson($path, [
            ...$base,
            'request_key' => (string) Str::uuid(),
            'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.0.quantity_label', 'メカいのら零式')
            ->assertJsonPath('data.queue.items.0.effective_cost_money', 9_999);
        $context = $this->context($world, $ruleset, 2, 'zero-insufficient-funds', [$sender->id]);

        app(DomesticCommandExecutor::class)->execute($context);

        $item = NationCommandQueueItem::query()->sole();
        $this->assertSame('failed', $item->status);
        $this->assertSame('insufficient_funds', $item->failure_code);
        $this->assertSame(2, $item->failure_metadata['dispatch_selector']);
        $this->assertSame('mecha_inora_zero', $item->failure_metadata['monster_key']);
        $this->assertSame(9_999, $item->failure_metadata['cost_money']);
        $this->assertSame(9_999, $sender->fresh()->money);
        $this->assertDatabaseCount('monster_instances', 0);
    }

    public function test_historical_v10_dispatch_inspection_is_state_safe_and_duplicate_comparison_preserves_the_hash(): void
    {
        [$world] = $this->v10World();
        $user = User::factory()->create();
        $sender = app(NationCreationService::class)->create($user, $world, '履歴元', '履歴主');
        $target = app(NationCreationService::class)->create(User::factory()->create(), $world, '履歴先', '対象主');
        $space = $this->surfaceMapSpace($world);
        $requestKey = (string) Str::uuid();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 1,
            parameters: ['target_nation_id' => $target->id],
        )['item'];
        $fingerprint = $item->request_fingerprint;
        $item->update(['request_ruleset_version_id' => null]);
        $item->load('definition.rulesetVersion');
        $inspector = app(HistoricalMonsterDispatchRequestInspector::class);

        foreach (['queued', 'completed', 'failed', 'cancelled'] as $status) {
            $item->status = $status;
            $inspection = $inspector->inspect($item);
            $this->assertTrue($inspection->proven, $status);
            $this->assertSame(1, $inspection->selector);
        }
        $item->status = 'queued';
        $item->request_fingerprint = null;
        $this->assertTrue($inspector->inspect($item)->proven);
        $item->request_fingerprint = $fingerprint;
        $item->syncOriginal();

        $duplicate = app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 999,
            parameters: ['target_nation_id' => $target->id],
        );
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($fingerprint, $duplicate['item']->request_fingerprint);

        $this->expectException(CommandRequestConflictException::class);
        app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 999,
            quantity: 2,
            parameters: ['target_nation_id' => $target->id],
            quantityProvided: true,
        );
    }

    public function test_proven_v10_selector_less_retry_survives_request_provenance_backfill_and_execution_definition_rebind(): void
    {
        [$world] = $this->v10World();
        $v10 = $world->rulesetVersion;
        $user = User::factory()->create();
        $sender = app(NationCreationService::class)->create($user, $world, '再束縛元', '履歴主');
        $target = app(NationCreationService::class)->create(User::factory()->create(), $world, '再束縛先', '対象主');
        $space = $this->surfaceMapSpace($world);
        $requestKey = (string) Str::uuid();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 1,
            parameters: ['target_nation_id' => $target->id],
        )['item'];
        $fingerprint = $item->request_fingerprint;

        $v11Settings = V11SecretaryItemRulesetFixture::settings();
        $v11 = app(RulesetPublisher::class)->publish($v11Settings);
        $v11Dispatch = CommandDefinition::query()
            ->where('ruleset_version_id', $v11->id)
            ->where('key', 'monster_dispatch')
            ->sole();
        config(['hakoniwa.ruleset' => $v11Settings]);
        $world->update(['ruleset_version_id' => $v11->id]);
        $item->update([
            'request_ruleset_version_id' => $v10->id,
            'command_definition_id' => $v11Dispatch->id,
        ]);

        $duplicate = app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 999,
            parameters: ['target_nation_id' => $target->id],
        );

        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($v10->id, $duplicate['item']->request_ruleset_version_id);
        $this->assertSame($v11Dispatch->id, $duplicate['item']->command_definition_id);
        $this->assertSame($fingerprint, $duplicate['item']->request_fingerprint);
        $this->assertSame($fingerprint, $item->fresh()->request_fingerprint);

        $this->expectException(CommandRequestConflictException::class);
        app(CommandQueueService::class)->add(
            user: $user,
            nation: $sender,
            mapSpace: $space,
            commandKey: 'monster_dispatch',
            targetX: null,
            targetY: null,
            requestKey: $requestKey,
            expectedVersion: 999,
            quantity: 2,
            parameters: ['target_nation_id' => $target->id],
            quantityProvided: true,
        );
    }

    public function test_aoi_world_spawn_uses_one_world_draw_stable_water_candidates_and_no_spawn_turn_action(): void
    {
        [$world, $ruleset] = $this->v11World();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '陸地国', '陸地主');
        $space = $this->surfaceMapSpace($world);
        $ownedLand = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->whereNotIn('key', ['sea', 'shallow']))->count();
        $seed = $this->seedMatching(static function (string $seed) use ($ownedLand): bool {
            return (new TurnRandomStreamFactory($seed))->stream(
                TurnRandomStreamFactory::monsterWorldSpawn('trigger', 1),
            )->integer(0, 9_999) < $ownedLand;
        });
        $context = $this->contextFromSeed($world, $ruleset, 2, $seed, [$nation->id]);
        app(MonsterRemovalService::class)->beginWorld($context);

        $metrics = app(MonsterWorldSpawnService::class)->spawn($context, $space);

        $this->assertSame(1, $metrics['world_sea_monster_spawn_draws']);
        $this->assertSame(1, $metrics['world_sea_monsters_spawned']);
        $occupancy = MonsterOccupancy::query()->with(['monster.definition', 'cell.terrain'])->sole();
        $this->assertSame('aoi_inora', $occupancy->monster->definition->key);
        $this->assertContains($occupancy->monster->current_hp, [2, 3]);
        $this->assertSame('sea', $occupancy->cell->terrain->key);
        $this->assertNull($occupancy->cell->owner_nation_id);
        $this->assertContains($occupancy->monster->id, $context->state->monsterIdsDeferredFromSpawnTurnMovement());
        $this->assertSame('world_aoi_disaster', $this->eventMetadata('monster.spawned')['spawn_source']);
        $event = collect(app(PlayerIslandEventService::class)->publicWorldPage($world, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'monster.spawned');
        $this->assertSame(
            '中立海域('.$occupancy->cell->x.','.$occupancy->cell->y.')にあおいのらが出現しました。',
            $event['message'],
        );
        $this->assertSame(0, app(MonsterTurnService::class)->load($context)->metrics()['monsters_loaded']);
    }

    public function test_aoi_water_movement_normalizes_owned_water_and_emits_affected_nation_event(): void
    {
        [$world, $ruleset] = $this->v11World();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '海面国', '海面主');
        $space = $this->surfaceMapSpace($world);
        $origin = $this->neutralInteriorSea($space, $nation);
        $states = app(MapCellStateService::class);
        $sea = TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
        foreach ((new GridCoordinate($origin->x, $origin->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        ) as $coordinate) {
            $cell = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)->firstOrFail();
            $states->setFacility($cell, null);
            $states->transitionTerrain($cell, $sea);
            $cell->owner_nation_id = $nation->id;
            $cell->population = 0;
            $cell->save();
        }
        $monster = $this->monster($world, $ruleset, $origin, 'aoi_inora', 2);
        $context = $this->context($world, $ruleset, 2, 'aoi-movement', [$nation->id]);
        $cells = MapCell::query()->where('map_space_id', $space->id)
            ->with(['terrain', 'facility', 'ownerNation'])->orderBy('id')->get();
        $byCoordinate = $cells->mapWithKeys(static fn (MapCell $cell): array => [
            $cell->x.':'.$cell->y => $cell,
        ])->all();
        $index = DisasterMutableCellIndex::fromCells($cells, [$nation->id], [
            'sea' => $sea,
            'shallow' => TerrainDefinition::query()->where('key', 'shallow')->firstOrFail(),
            'wasteland' => TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        ]);
        $batch = app(MonsterTurnService::class)->load($context);

        $this->assertTrue(app(MonsterTurnService::class)->processCell(
            $context,
            $space,
            $origin->fresh(['terrain', 'facility', 'ownerNation']),
            $byCoordinate,
            $batch,
            $index,
        ));

        $destination = $monster->fresh()->occupancy()->with('cell.terrain')->firstOrFail()->cell;
        $this->assertSame('sea', $destination->terrain->key);
        $this->assertNull($destination->owner_nation_id);
        $this->assertSame(0, $destination->population);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.moved')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.trampled')->count());
        $this->assertSame($nation->id, $this->eventMetadata('monster.trampled')['pre_impact_owner_nation_id']);
    }

    public function test_real_hostless_aoi_kill_credits_the_full_wreckage_value_once(): void
    {
        [$world, $ruleset] = $this->v11World();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '撃破国', '撃破主');
        $cell = $this->neutralInteriorSea($this->surfaceMapSpace($world), $nation);
        $aoi = $this->monster($world, $ruleset, $cell, 'aoi_inora', 1);
        $beforeMoney = $nation->money;
        $context = $this->context($world, $ruleset, 2, 'aoi-hostless-kill', [$nation->id]);

        $result = app(MonsterDamageService::class)->applyDamage(
            $aoi,
            1,
            'test_attributed_attack',
            $nation,
            null,
            $cell,
            $context,
        );

        $this->assertTrue($result->killed);
        $this->assertSame($beforeMoney + 1_200, $nation->fresh()->money);
        $this->assertSame(1, NationMonsterKillStat::query()->where('nation_id', $nation->id)->value('kill_count'));
        $metadata = $this->eventMetadata('monster.reward_distributed');
        $this->assertSame('hostless_full_killer_money', $metadata['monster_reward_policy']);
        $this->assertSame(1_200, $metadata['killer_requested_share_money']);
        $this->assertNull($metadata['host_nation_id']);
        $this->assertNull($metadata['host_meat_food']);
        $this->assertSame(0, $metadata['unclaimed_host_value_money']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.reward_distributed')->count());
    }

    public function test_zero_hp_one_special_action_precedes_movement_and_is_one_rewardless_fixed_blast(): void
    {
        [$world, $ruleset] = $this->v11World();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '核実験', '実験主');
        $space = $this->surfaceMapSpace($world);
        $origin = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $nation->capital()->select('map_cell_id'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        $zero = $this->monster($world, $ruleset, $origin, 'mecha_inora_zero', 4);
        $zero->update(['current_hp' => 1]);
        $context = $this->context($world, $ruleset, 2, 'zero-nuclear', [$nation->id]);
        $cells = MapCell::query()->where('map_space_id', $space->id)
            ->with(['terrain', 'facility', 'ownerNation'])->orderBy('id')->get();
        $byCoordinate = $cells->mapWithKeys(static fn (MapCell $cell): array => [
            $cell->x.':'.$cell->y => $cell,
        ])->all();
        $terrains = TerrainDefinition::query()->whereIn('key', ['sea', 'shallow', 'wasteland'])
            ->get()->keyBy('key');
        $index = DisasterMutableCellIndex::fromCells($cells, [$nation->id], $terrains);
        $batch = app(MonsterTurnService::class)->load($context);

        app(MonsterTurnService::class)->processCell(
            $context,
            $space,
            $origin,
            $byCoordinate,
            $batch,
            $index,
        );

        $this->assertSame('removed', MonsterInstance::query()->sole()->state);
        $this->assertSame('nuclear_self_destruct', MonsterInstance::query()->sole()->removal_reason);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.nuclear_self_destructed')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'disaster.triggered')
            ->whereRaw("metadata->>'disaster_key' = ?", ['nuclear_self_destruct'])->count());
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'monster.reward_distributed')->count());
        $event = collect(app(PlayerIslandEventService::class)->publicWorldPage($world, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'monster.nuclear_self_destructed');
        $this->assertIsArray($event);
        $this->assertSame(
            '核実験('.$origin->x.','.$origin->y.')のメカいのら零式が突然輝きだし、とてつもない爆発を起こしました！',
            $event['message'],
        );
    }

    public function test_zero_blast_removes_a_collateral_zero_without_chaining_and_uses_the_neutral_message(): void
    {
        [$world, $ruleset] = $this->v11World();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '観測国', '観測主');
        $space = $this->surfaceMapSpace($world);
        $origin = $this->neutralInteriorSea($space, $nation);
        $neighbor = collect((new GridCoordinate($origin->x, $origin->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        ))->map(fn (GridCoordinate $coordinate): ?MapCell => MapCell::query()
            ->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->with(['terrain', 'facility', 'ownerNation'])->first())
            ->first(fn (?MapCell $cell): bool => $cell !== null)
            ?? throw new DomainException('No neutral adjacent sea cell was available.');
        $primary = $this->monster($world, $ruleset, $origin, 'mecha_inora_zero', 1);
        $collateral = $this->monster($world, $ruleset, $neighbor, 'mecha_inora_zero', 1);
        $context = $this->context($world, $ruleset, 2, 'zero-no-chain', [$nation->id]);
        $cells = MapCell::query()->where('map_space_id', $space->id)
            ->with(['terrain', 'facility', 'ownerNation'])->orderBy('id')->get();
        $byCoordinate = $cells->mapWithKeys(static fn (MapCell $cell): array => [
            $cell->x.':'.$cell->y => $cell,
        ])->all();
        $terrains = TerrainDefinition::query()->whereIn('key', ['sea', 'shallow', 'wasteland'])
            ->get()->keyBy('key');
        $index = DisasterMutableCellIndex::fromCells($cells, [$nation->id], $terrains);
        $batch = app(MonsterTurnService::class)->load($context);

        app(MonsterTurnService::class)->processCell(
            $context,
            $space,
            $origin,
            $byCoordinate,
            $batch,
            $index,
        );

        $this->assertSame('nuclear_self_destruct', $primary->fresh()->removal_reason);
        $this->assertSame('nuclear_self_destruct_blast', $collateral->fresh()->removal_reason);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.nuclear_self_destructed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.removed_by_terrain_event')
            ->where('subject_id', $collateral->id)->count());
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $event = collect(app(PlayerIslandEventService::class)->publicWorldPage($world, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'monster.nuclear_self_destructed');
        $this->assertSame(
            '中立海域('.$origin->x.','.$origin->y.')のメカいのら零式が突然輝きだし、とてつもない爆発を起こしました！',
            $event['message'],
        );
    }

    public function test_initial_island_displaces_only_authored_aoi_on_changed_cells(): void
    {
        [$world, $ruleset] = $this->v11World();
        $space = $this->surfaceMapSpace($world);
        $center = app(CapitalPlacementService::class)->candidates($space, 1)[0];
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $center->x)->where('y', $center->y)->with(['terrain', 'facility'])->firstOrFail();
        $aoi = $this->monster($world, $ruleset, $cell, 'aoi_inora', 2);

        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '退避国', '退避主');

        $this->assertNotNull($nation->capital);
        $this->assertSame('removed', $aoi->fresh()->state);
        $this->assertSame('island_creation_displacement', $aoi->fresh()->removal_reason);
        $audit = DB::table('audit_events')->where('event_type', 'monster.island_creation_displaced')->sole();
        $this->assertSame('admin', $audit->visibility);
        $this->assertSame(false, $this->eventMetadata('monster.island_creation_displaced')['rewards_granted']);
    }

    public function test_initial_island_fails_closed_for_an_ordinary_monster_on_a_changed_cell(): void
    {
        [$world, $ruleset] = $this->v11World();
        $space = $this->surfaceMapSpace($world);
        $center = app(CapitalPlacementService::class)->candidates($space, 1)[0];
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $center->x)->where('y', $center->y)->with(['terrain', 'facility'])->firstOrFail();
        $ordinary = $this->monster($world, $ruleset, $cell, 'inora', 1);

        try {
            app(NationCreationService::class)->create(User::factory()->create(), $world, '拒否国', '拒否主');
            $this->fail('An ordinary monster on a changed cell must block island creation.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('alive', $ordinary->fresh()->state);
        $this->assertSame(0, Nation::query()->count());
    }

    public function test_initial_island_leaves_aoi_on_a_reserved_but_unchanged_cell_alive(): void
    {
        [$world, $ruleset] = $this->v11World();
        $space = $this->surfaceMapSpace($world);
        $user = User::factory()->create();
        $name = '非変更国';
        $center = app(CapitalPlacementService::class)->candidates($space, 1)[0];
        $seed = hash('sha256', implode(':', [
            $world->id,
            $user->id,
            mb_strtolower($name),
            config('hakoniwa.initial_island.generator_version'),
        ]));
        $provisional = Nation::query()->create([
            'world_id' => $world->id,
            'nation_number' => 1,
            'registered_turn' => $world->current_turn,
            'name' => 'plan-only',
            'owner_name' => 'plan-only',
            'profile_comment' => '',
            'money' => 0,
            'state' => 'active',
            'idle_counter' => 100,
        ]);
        $plan = app(InitialIslandGenerator::class)->plan(
            $space,
            $provisional,
            $center,
            $seed,
        );
        $reservationRadius = (int) $ruleset->settings['initial_island_reservation_radius'];
        $reservationCoordinates = collect($center->radius($reservationRadius))
            ->mapWithKeys(static fn (GridCoordinate $coordinate): array => [
                $coordinate->x.':'.$coordinate->y => true,
            ])->all();
        $unchanged = MapCell::query()->where('map_space_id', $space->id)
            ->whereNotIn('id', $plan->changedCellIds)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->with(['terrain', 'facility'])->orderBy('id')->get()
            ->first(fn (MapCell $cell): bool => isset($reservationCoordinates[$cell->x.':'.$cell->y]))
            ?? throw new DomainException('No reserved unchanged cell was available.');
        $provisional->delete();
        $aoi = $this->monster($world, $ruleset, $unchanged, 'aoi_inora', 2);

        app(NationCreationService::class)->create($user, $world, $name, '非変更主');

        $this->assertSame('alive', $aoi->fresh()->state);
        $this->assertSame($unchanged->id, $aoi->fresh()->occupancy()->value('map_cell_id'));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'monster.island_creation_displaced')->count());
    }

    /** @return array{World, RulesetVersion} */
    private function v11World(): array
    {
        $world = $this->lightweightWorld();
        $settings = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v11');
        $ruleset = app(RulesetPublisher::class)->publish($settings);
        config(['hakoniwa.ruleset' => $settings]);
        $world->update(['ruleset_version_id' => $ruleset->id]);

        return [$world->fresh(), $ruleset];
    }

    /** @return array{World, RulesetVersion} */
    private function v10World(): array
    {
        $world = $this->lightweightWorld();
        $ruleset = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v10')->sole();
        config(['hakoniwa.ruleset' => $ruleset->settings]);
        $world->update(['ruleset_version_id' => $ruleset->id]);

        return [$world->fresh(), $ruleset];
    }

    /** @param list<int> $nationIds */
    private function context(World $world, RulesetVersion $ruleset, int $turn, string $label, array $nationIds): TurnContext
    {
        return $this->contextFromSeed($world, $ruleset, $turn, hash('sha256', $label), $nationIds);
    }

    /** @param list<int> $nationIds */
    private function contextFromSeed(World $world, RulesetVersion $ruleset, int $turn, string $seed, array $nationIds): TurnContext
    {
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $turn,
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

        return new TurnContext($world, $run, $ruleset, $turn, $seed, new TurnRandomStreamFactory($seed), $state);
    }

    private function monster(
        World $world,
        RulesetVersion $ruleset,
        MapCell $cell,
        string $key,
        int $hp,
    ): MonsterInstance {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', $key)->firstOrFail();
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

        return $monster->fresh(['definition', 'occupancy']);
    }

    private function neutralInteriorSea(MapSpace $space, Nation $nation): MapCell
    {
        $capital = new GridCoordinate($nation->capital->x, $nation->capital->y);

        return MapCell::query()->where('map_space_id', $space->id)
            ->whereBetween('x', [$space->min_x + 2, $space->max_x - 2])
            ->whereBetween('y', [$space->min_y + 2, $space->max_y - 2])
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->with(['terrain', 'facility', 'ownerNation'])->orderBy('id')->get()
            ->first(fn (MapCell $cell): bool => (new GridCoordinate($cell->x, $cell->y))->distanceTo($capital) > 5)
            ?? throw new DomainException('No neutral interior sea cell was available.');
    }

    /** @param callable(string): bool $matches */
    private function seedMatching(callable $matches): string
    {
        for ($index = 0; $index < 100_000; $index++) {
            $seed = hash('sha256', "c4-seed-{$index}");
            if ($matches($seed)) {
                return $seed;
            }
        }

        throw new DomainException('No deterministic C4 test seed matched.');
    }

    /** @return array<string, mixed> */
    private function eventMetadata(string $eventType): array
    {
        $row = DB::table('audit_events')->where('event_type', $eventType)->orderByDesc('id')->firstOrFail();

        return json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);
    }
}
