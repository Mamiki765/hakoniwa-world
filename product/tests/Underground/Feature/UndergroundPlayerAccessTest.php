<?php

namespace Tests\Underground\Feature;

use App\Application\NationCreationService;
use App\Application\SecretaryService;
use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundIntroCatalog;
use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Domain\Underground\Area\UndergroundAreaCapacity;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\NationCapital;
use App\Models\NationCommandQueueItem;
use App\Models\NationUndergroundFacility;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkillAllocation;
use App\Models\UndergroundTrialProgress;
use App\Models\UndergroundTrialRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class UndergroundPlayerAccessTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_player_routes_require_session_and_resolve_only_the_current_users_secretary(): void
    {
        $this->getJson('/api/v1/me/underground')->assertUnauthorized();
        $this->getJson('/api/v1/me/underground/surface-map')->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/entry', ['request_id' => (string) Str::uuid()])
            ->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/explore', ['request_id' => (string) Str::uuid()])
            ->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/trial/start')->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/trial/fight', [
            'run_key' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/trial/withdraw', [
            'run_key' => (string) Str::uuid(),
        ])->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/inn/rest', ['request_id' => (string) Str::uuid()])
            ->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/bank/transfer', [
            'request_id' => (string) Str::uuid(),
            'action' => 'deposit_all',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['vitality' => 1],
        ])->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_holy_bolt',
        ])->assertUnauthorized();
        $this->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', null, null, null, null],
        ])->assertUnauthorized();
        $this->putJson('/api/v1/me/underground/ai', [
            'request_id' => (string) Str::uuid(),
            'rules' => [],
        ])->assertUnauthorized();
        $this->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => '覚醒',
        ])->assertUnauthorized();
        $this->getJson('/api/v1/me/underground/equipment/shop')->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'iron_dagger',
        ])->assertUnauthorized();
        $this->getJson('/api/v1/me/underground/equipment/vault')->assertUnauthorized();

        [$owner, $ownerSecretary] = $this->secretaryUser('Owner secretary');
        [$other, $otherSecretary] = $this->secretaryUser('Other secretary');
        $this->actingAs($owner)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
            'secretary_id' => $otherSecretary->id,
        ])->assertOk()->assertJsonPath('data.secretary_name', 'Owner secretary');
        $this->actingAs($owner)->postJson('/api/v1/me/underground/explore', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_exploration_locked');

        $this->assertDatabaseHas('underground_profiles', ['secretary_id' => $ownerSecretary->id]);
        $this->assertDatabaseMissing('underground_profiles', ['secretary_id' => $otherSecretary->id]);
        $this->actingAs($other)->getJson('/api/v1/me/underground')
            ->assertOk()
            ->assertJsonPath('data.stage', 'not_started');
    }

    public function test_surface_map_is_hidden_until_trial_one_first_clear_and_projects_fixed_capital_relative_slots(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '地底表示島', '地底表示島主');
        $secretary = Secretary::query()->where('user_id', $user->id)->sole();
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);

        $this->actingAs($user)->getJson('/api/v1/me/underground/surface-map')
            ->assertOk()->assertJsonPath('data', null);

        $profile->update(['unlocked_area_layers' => 1]);
        $progress = UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now(),
            'first_cleared_at' => null,
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/surface-map')
            ->assertOk()->assertJsonPath('data', null);

        $progress->update(['first_cleared_at' => Carbon::now()]);
        $capital = NationCapital::query()->where('nation_id', $nation->id)->sole();
        foreach ([1 => 4, 2 => 8, 3 => 12] as $unlockedLayers => $slotCapacity) {
            $profile->update(['unlocked_area_layers' => $unlockedLayers]);
            $data = $this->actingAs($user)->getJson('/api/v1/me/underground/surface-map')
                ->assertOk()->json('data');

            $this->assertSame($unlockedLayers, $data['unlocked_layers']);
            $this->assertSame(UndergroundAreaCapacity::FACILITY_SLOTS_PER_LAYER, $data['facility_slots_per_layer']);
            $this->assertSame($slotCapacity, $data['total_facility_slots']);
            $this->assertCount($unlockedLayers, $data['layers']);
            $this->assertSame(['x' => $capital->x, 'y' => $capital->y], $data['capital']);
            $this->assertFalse($data['entrance']['counts_as_facility_slot']);
            $this->assertArrayNotHasKey('map_cells', $data);
            $this->assertArrayNotHasKey('world', $data);

            foreach ($data['layers'] as $layerIndex => $layer) {
                $layerNumber = $layerIndex + 1;
                $expectedZ = -($layerNumber + 1);
                $this->assertSame($layerNumber, $layer['layer']);
                $this->assertSame($expectedZ, $layer['z']);
                $this->assertFalse($layer['ladder']['counts_as_facility_slot']);
                $this->assertCount(4, $layer['slots']);
                $this->assertSame([-2, -1, 1, 2], array_column($layer['slots'], 'offset_x'));

                foreach ($layer['slots'] as $slotIndex => $slot) {
                    $this->assertSame($slotIndex, $slot['slot_index']);
                    $this->assertSame($capital->x + [-2, -1, 1, 2][$slotIndex], $slot['coordinate']['x']);
                    $this->assertSame($capital->y, $slot['coordinate']['y']);
                    $this->assertSame($expectedZ, $slot['coordinate']['z']);
                    $this->assertNull($slot['facility_key']);
                    $this->assertSame('underground.road', $slot['asset_key']);
                    $slotKeys = array_keys($slot);
                    sort($slotKeys);
                    $this->assertSame(
                        ['asset_key', 'coordinate', 'coordinate_label', 'facility_key', 'offset_x', 'relative_label', 'slot_index'],
                        $slotKeys,
                    );
                }
            }
        }

        $this->assertSame('(X-2, Y, -2)', $data['layers'][0]['slots'][0]['relative_label']);
        $this->assertSame('(X+2, Y, -2)', $data['layers'][0]['slots'][3]['relative_label']);
        $this->assertSame(-3, $data['layers'][1]['z']);

        $capitalCellBefore = $capital->cell()->firstOrFail()->only([
            'terrain_definition_id', 'facility_definition_id', 'facility_scale', 'population',
        ]);
        foreach ([
            'underground_city',
            'underground_farm',
            'underground_factory',
            'underground_missile_base',
        ] as $slotIndex => $facilityKey) {
            NationUndergroundFacility::query()->create([
                'nation_id' => $nation->id,
                'ruleset_version_id' => $world->ruleset_version_id,
                'layer' => 1,
                'slot_index' => $slotIndex,
                'facility_key' => $facilityKey,
            ]);
        }
        NationUndergroundFacility::query()->create([
            'nation_id' => $nation->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 2,
            'slot_index' => 0,
            'facility_key' => 'underground_farm',
        ]);
        $built = $this->actingAs($user)->getJson('/api/v1/me/underground/surface-map')->assertOk()->json('data');
        $this->assertSame([
            'underground.city',
            'underground.farm',
            'underground.factory',
            'underground.missile_base',
        ], array_column($built['layers'][0]['slots'], 'asset_key'));
        $this->assertSame('underground.road', $built['layers'][1]['slots'][1]['asset_key']);
        $publicMap = $this->getJson("/api/v1/public/nations/{$nation->id}")
            ->assertOk()
            ->assertJsonPath('data.underground_surface_map.unlocked_layers', 3)
            ->json('data.underground_surface_map');
        $this->assertSame(
            array_column($built['layers'][0]['slots'], 'facility_key'),
            array_column($publicMap['layers'][0]['slots'], 'facility_key'),
        );
        $this->assertArrayNotHasKey('map_cells', $publicMap);
        $this->assertSame($capitalCellBefore, $capital->cell()->firstOrFail()->only([
            'terrain_definition_id', 'facility_definition_id', 'facility_scale', 'population',
        ]));
        try {
            DB::transaction(static function () use ($nation, $world): void {
                NationUndergroundFacility::query()->create([
                    'nation_id' => $nation->id,
                    'ruleset_version_id' => $world->ruleset_version_id,
                    'layer' => 1,
                    'slot_index' => 0,
                    'facility_key' => 'underground_farm',
                ]);
            });
            $this->fail('A Nation Underground slot accepted duplicate occupancy.');
        } catch (QueryException) {
            $this->assertSame(5, NationUndergroundFacility::query()->where('nation_id', $nation->id)->count());
        }

        $nation->delete();
        $this->assertSame(0, NationUndergroundFacility::query()->count());
        $nextNation = app(NationCreationService::class)->create($user, $world, '次の地下開発島', '次の地下開発島主');
        $next = $this->actingAs($user)->getJson('/api/v1/me/underground/surface-map')->assertOk()->json('data');
        $this->assertSame($nextNation->id, $user->nationMemberships()->where('role', 'owner')->sole()->nation_id);
        $this->assertSame(3, $profile->fresh()->unlocked_area_layers);
        $this->assertSame(
            ['underground.road'],
            array_values(array_unique(array_merge(...array_map(
                static fn (array $layer): array => array_column($layer['slots'], 'asset_key'),
                $next['layers'],
            )))),
        );
    }

    public function test_underground_facility_commands_are_isolated_projected_and_only_reserved_before_turn_execution(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '地下開発島', '地下開発島主');
        $secretary = Secretary::query()->where('user_id', $user->id)->sole();
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['unlocked_area_layers' => 1]);
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now(),
            'first_cleared_at' => Carbon::now(),
        ]);
        $space = $this->surfaceMapSpace($world);
        $surfaceCell = $nation->capital()->firstOrFail()->cell()->firstOrFail();
        $base = "/api/v1/nations/{$nation->id}/map-spaces/{$space->id}";
        $undergroundKeys = [
            'build_underground_city',
            'build_underground_farm',
            'build_underground_factory',
            'build_underground_missile_base',
            'remove_underground_facility',
        ];
        $this->assertSame(0, CommandDefinition::query()->whereIn('key', $undergroundKeys)->count());

        $surfaceTargets = [
            'capital' => $surfaceCell,
            'plain' => MapCell::query()->where('map_space_id', $space->id)
                ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail(),
            'sea' => MapCell::query()->where('map_space_id', $space->id)
                ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))->firstOrFail(),
        ];
        foreach ($surfaceTargets as $surfaceTarget) {
            $surfaceCommands = $this->actingAs($user)->getJson(
                "{$base}/command-definitions?target_x={$surfaceTarget->x}&target_y={$surfaceTarget->y}&position=1",
            )->assertOk()->json('data.commands');
            $this->assertNotEmpty($surfaceCommands);
            $this->assertSame([], array_values(array_intersect(
                $undergroundKeys,
                array_column($surfaceCommands, 'key'),
            )));
        }
        $plainCommands = collect($this->actingAs($user)->getJson(
            "{$base}/command-definitions?target_x={$surfaceTargets['plain']->x}&target_y={$surfaceTargets['plain']->y}&position=1",
        )->assertOk()->json('data.commands'))->keyBy('key');
        $this->assertTrue($plainCommands->get('land_clear')['available']);
        $this->assertTrue($plainCommands->get('build_farm')['available']);

        $emptyCommands = $this->actingAs($user)->getJson(
            "{$base}/command-definitions?target_layer=1&target_slot_index=0&position=1",
        )->assertOk()->json('data.commands');
        $this->assertSame(array_slice($undergroundKeys, 0, 4), array_column($emptyCommands, 'key'));
        $this->assertSame(['underground_slot'], array_values(array_unique(array_column($emptyCommands, 'target_type'))));
        $this->actingAs($user)->getJson(
            "{$base}/command-definitions?target_layer=2&target_slot_index=0&position=1",
        )->assertUnprocessable();
        $this->actingAs($user)->getJson(
            "{$base}/command-definitions?target_layer=1&target_slot_index=4&position=1",
        )->assertUnprocessable();
        $this->actingAs($user)->getJson(
            "{$base}/command-definitions?target_layer=1&target_slot_index=-1&position=1",
        )->assertUnprocessable();

        $queue = $this->actingAs($user)->getJson("{$base}/command-queue")->assertOk()->json('data');
        $moneyBefore = (int) $nation->money;
        $requestKey = (string) Str::uuid();
        $buildPayload = [
            'command_key' => 'build_underground_city',
            'target_layer' => 1,
            'target_slot_index' => 0,
            'position' => 1,
            'request_key' => $requestKey,
            'expected_version' => $queue['version'],
            'quantity' => 1,
            'parameters' => [],
        ];
        $this->actingAs($user)->postJson("{$base}/command-queue", [
            ...$buildPayload,
            'command_key' => 'remove_underground_facility',
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson("{$base}/command-queue", [
            ...$buildPayload,
            'target_x' => $surfaceCell->x,
            'target_y' => $surfaceCell->y,
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson("{$base}/command-queue", [
            'command_key' => 'build_underground_city',
            'target_x' => $surfaceCell->x,
            'target_y' => $surfaceCell->y,
            'position' => 1,
            'request_key' => (string) Str::uuid(),
            'expected_version' => $queue['version'],
            'quantity' => 1,
            'parameters' => [],
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson("{$base}/command-queue", [
            ...$buildPayload,
            'command_key' => 'land_clear',
        ])->assertUnprocessable();

        $reserved = $this->actingAs($user)->postJson("{$base}/command-queue", $buildPayload)
            ->assertCreated()
            ->assertJsonPath('data.duplicate', false);
        $this->assertSame($moneyBefore, (int) $nation->fresh()->money);
        $this->assertSame(0, NationUndergroundFacility::query()->where('nation_id', $nation->id)->count());
        $item = NationCommandQueueItem::query()->sole();
        $this->assertSame([
            'underground_slot', null, null, 1, 0, null, 'build_underground_city',
        ], [
            $item->target_context,
            $item->target_x,
            $item->target_y,
            $item->target_layer,
            $item->target_slot_index,
            $item->command_definition_id,
            $item->underground_command_key,
        ]);
        $this->actingAs($user)->postJson("{$base}/command-queue", $buildPayload)
            ->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, NationCommandQueueItem::query()->count());
        $this->actingAs($user)->postJson("{$base}/command-queue", [
            ...$buildPayload,
            'command_key' => 'build_underground_farm',
        ])->assertConflict();

        $projectedOccupiedResponse = $this->actingAs($user)->getJson(
            "{$base}/command-definitions?target_layer=1&target_slot_index=0&position=2",
        )->assertOk();
        $projectedOccupied = $projectedOccupiedResponse->json('data.commands');
        $this->assertTrue(array_is_list($projectedOccupied));
        $this->assertSame(['remove_underground_facility'], array_column($projectedOccupied, 'key'));
        $version = $reserved->json('data.queue.version');
        $this->actingAs($user)->postJson("{$base}/command-queue", [
            ...$buildPayload,
            'request_key' => (string) Str::uuid(),
            'position' => 2,
            'expected_version' => $version,
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson("{$base}/command-queue", [
            'command_key' => 'remove_underground_facility',
            'target_layer' => 1,
            'target_slot_index' => 0,
            'position' => 2,
            'request_key' => (string) Str::uuid(),
            'expected_version' => $version,
            'quantity' => 1,
            'parameters' => [],
        ])->assertCreated();
        $projectedEmpty = $this->actingAs($user)->getJson(
            "{$base}/command-definitions?target_layer=1&target_slot_index=0&position=3",
        )->assertOk()->json('data.commands');
        $this->assertSame(array_slice($undergroundKeys, 0, 4), array_column($projectedEmpty, 'key'));
    }

    public function test_tutorial_is_a_legal_deterministic_single_settlement_then_escape_returns_to_secretary(): void
    {
        [$user] = $this->secretaryUser('Tutorial secretary');
        $entryRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => $entryRequest,
        ])->assertOk()->assertJsonPath('data.stage', 'initial_descent');
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_intro_stage_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/story/advance', [
            'request_id' => (string) Str::uuid(),
            'action' => 'escape_complete',
        ])->assertConflict()->assertJsonPath('code', 'underground_intro_stage_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/story/advance', [
            'request_id' => $entryRequest,
            'action' => 'initial_story_complete',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->advance($user, 'initial_story_complete')->assertJsonPath('data.stage', 'tutorial_ready');

        $tutorialRequest = (string) Str::uuid();
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $tutorialRequest,
        ])->assertOk()
            ->assertJsonPath('data.stage', 'escape_pending')
            ->assertJsonPath('data.battle.encounter_name', 'ジャイアントラット')
            ->assertJsonPath('data.battle.context', 'tutorial')
            ->assertJsonPath('data.battle.result', 'victory')
            ->assertJsonPath('data.battle.xp_awarded', 5)
            ->assertJsonPath('data.battle.shard_delta', 0)
            ->assertJsonMissingPath('data.battle.private_seed')
            ->assertJsonMissingPath('data.battle.snapshot');
        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $tutorialRequest,
        ])->assertOk()->assertExactJson($first->json());
        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_tutorial_already_settled');

        $profile = UndergroundProfile::query()->sole();
        $battle = UndergroundBattle::query()->sole();
        $this->assertSame([1, 5, 0, null, null], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at,
            $profile->current_hp,
        ]);
        $this->assertSame('tutorial_starter_knife', $battle->snapshot['actor']['weapon_key']);
        $this->assertSame('tutorial_giant_rat', $battle->encounter_key);
        $this->assertNotEmpty($battle->log?->actions);
        $this->assertSame(1, UndergroundBattle::query()->count());
        $this->assertSame(1, UndergroundBattleLog::query()->count());
        $this->assertSame(0, SecretaryItemInstance::query()
            ->whereIn('item_key', ['starter_knife', 'tutorial_starter_knife'])->count());
        $this->assertSame(0, UndergroundTrialProgress::query()->count());

        $this->advance($user, 'escape_complete')->assertJsonPath('data.stage', 'returned_after_tutorial');
        $this->actingAs($user)->getJson('/api/v1/me/secretary')
            ->assertOk()
            ->assertJsonPath('data.underground.combat_level', 1)
            ->assertJsonPath('data.underground.combat_xp', 5)
            ->assertJsonPath('data.underground.next_level_xp', 100);
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.stage', 'shopkeeper_encounter');
    }

    public function test_tutorial_contract_mismatch_rolls_back_battle_reward_and_story_stage(): void
    {
        [$user] = $this->secretaryUser('Fail closed secretary');
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'initial_story_complete')
            ->assertJsonPath('data.stage', 'tutorial_ready');
        config(['underground-intro.battles.tutorial.expected_winner' => 'enemy']);
        $requestId = (string) Str::uuid();

        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $requestId,
        ])->assertConflict()->assertJsonPath('code', 'underground_story_combat_contract_failed');

        $profile = UndergroundProfile::query()->sole();
        $this->assertSame([1, 0, 0, null], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at,
        ]);
        $this->assertDatabaseHas('underground_intro_progress', [
            'underground_profile_id' => $profile->id,
            'stage' => 'tutorial_ready',
            'tutorial_battle_id' => null,
        ]);
        $this->assertDatabaseMissing('underground_intro_requests', ['request_id' => $requestId]);
        $this->assertSame(0, UndergroundBattle::query()->count());
        $this->assertSame(0, UndergroundBattleLog::query()->count());
    }

    public function test_normal_shopkeeper_name_is_safe_immutable_and_requires_shop_explanation_before_main(): void
    {
        [$user] = $this->secretaryUser('Normal secretary');
        $this->reachShopkeeperNaming($user);
        $twentyGraphemes = str_repeat("e\u{0301}", 20);
        $this->assertSame(
            $twentyGraphemes,
            app(UndergroundIntroCatalog::class)->normalizeShopkeeperName($twentyGraphemes),
        );
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertConflict()->assertJsonPath('code', 'underground_main_locked');
        foreach (["改行\n名", '<b>店員</b>', str_repeat('あ', 21)] as $invalidName) {
            $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
                'request_id' => (string) Str::uuid(),
                'name' => $invalidName,
            ])->assertUnprocessable();
        }

        $nameRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => $nameRequest,
            'name' => '  ダミー店員  ',
        ])->assertOk()
            ->assertJsonPath('data.stage', 'shop_explanation')
            ->assertJsonPath('data.shopkeeper_name', 'ダミー店員');
        $this->assertDatabaseHas('underground_intro_progress', [
            'shopkeeper_name' => 'ダミー店員',
            'special_loss_required' => false,
            'scripted_loss_battle_id' => null,
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => $nameRequest,
            'name' => '別名',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => (string) Str::uuid(),
            'name' => '別名',
        ])->assertConflict()->assertJsonPath('code', 'underground_shopkeeper_already_named');
        $this->actingAs($user)->postJson('/api/v1/me/underground/scripted-loss', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_scripted_loss_unavailable');

        $this->advance($user, 'shop_explanation_complete')->assertJsonPath('data.stage', 'contract_ready');
        $this->actingAs($user)->postJson('/api/v1/me/underground/growth-path', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'martial_red',
        ])->assertConflict()->assertJsonPath('code', 'underground_growth_path_already_selected');
        $this->actingAs($user)->postJson('/api/v1/me/underground/contract', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.stage', 'crystal_selection');
        $this->actingAs($user)->postJson('/api/v1/me/underground/growth-path', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'martial_red',
        ])->assertOk()
            ->assertJsonPath('data.stage', 'growth_path_selected')
            ->assertJsonPath('data.growth_path.stats.vitality', 18)
            ->assertJsonPath('data.growth_path.stats.might', 34)
            ->assertJsonPath('data.growth_path.max_hp', 492)
            ->assertJsonPath('data.growth_path.max_mp', 10000)
            ->assertJsonPath('data.current_hp', 492)
            ->assertJsonPath('data.growth_path.natural_recovery', 300)
            ->assertJsonPath('data.skill_points_total', 20)
            ->assertJsonPath('data.skill_points_unspent', 20)
            ->assertJsonPath('data.skill_tree_identity', 'secretary-underground-skill-tree-alpha-v1')
            ->assertJsonPath('data.skill_trees.0.label', '戦技')
            ->assertJsonPath('data.skill_trees.0.nodes.0.recommended_stats', ['might'])
            ->assertJsonPath('data.skill_trees.1.label', '護身')
            ->assertJsonPath('data.skill_trees.2.label', '祝福')
            ->assertJsonPath('data.skill_trees.2.nodes.0.recommended_stats', ['spirit', 'finesse'])
            ->assertJsonPath('data.skill_trees.2.nodes.1.recommended_stats', null)
            ->assertJsonPath('data.skill_trees.2.nodes.5.recommended_stats', []);
        $playerCatalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $skillAllocation = ['martial_precision_cut' => ['rank' => 1, 'active_slot' => 1]];
        $combatBuildBeforeGuidanceChange = $playerCatalog->playerSkillBuild($skillAllocation, 'dagger');
        $originalGuidance = config('underground-alpha-v1.player_skill_guidance.precision_cut.recommended_stats');
        config(['underground-alpha-v1.player_skill_guidance.precision_cut.recommended_stats' => ['spirit']]);
        $this->assertSame(
            $combatBuildBeforeGuidanceChange,
            $playerCatalog->playerSkillBuild($skillAllocation, 'dagger'),
        );
        config(['underground-alpha-v1.player_skill_guidance.precision_cut.recommended_stats' => $originalGuidance]);
        $selectedProfile = UndergroundProfile::query()->sole();
        $this->assertDatabaseHas('underground_owned_equipment', [
            'underground_profile_id' => $selectedProfile->id,
            'definition_key' => 'starter_knife',
            'equipped_slot' => 'weapon',
            'grant_key' => 'starter-knife-alpha-v1',
        ]);
        $this->advance($user, 'growth_path_story_complete')->assertJsonPath('data.stage', 'underground_open');
        $nextBattleAt = Carbon::now()->addSeconds(10)->startOfSecond();
        $selectedProfile->update(['next_battle_at' => $nextBattleAt]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.shopkeeper_name', 'ダミー店員')
            ->assertJsonPath('data.combat_level', 1)
            ->assertJsonPath('data.combat_xp', 5)
            ->assertJsonPath('data.next_battle_at', $nextBattleAt->toAtomString())
            ->assertJsonPath('data.playtest.default_build_key', 'pure_attacker');
        $originalEnvironment = config('app.env');
        config(['app.env' => 'production']);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.playtest', null);
        $this->actingAs($user)->getJson('/api/v1/me/underground/playtest')
            ->assertConflict()
            ->assertJsonPath('code', 'underground_playtest_locked');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            'request_id' => (string) Str::uuid(),
            'build_key' => 'pure_attacker',
            'enemy_key' => 'depth_stalker',
        ])->assertConflict()->assertJsonPath('code', 'underground_playtest_locked');
        $this->assertSame(0, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_PLAYTEST)->count());
        config(['app.env' => $originalEnvironment]);
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $selectedProfile->id)
            ->where('definition_key', 'starter_knife')->count());

        $profile = UndergroundProfile::query()->sole();
        $profile->update(['combat_level' => 2, 'combat_xp' => 100, 'unspent_stp' => 5, 'current_hp' => 400]);
        $stpRequest = (string) Str::uuid();
        $stpPayload = [
            'request_id' => $stpRequest,
            'allocations' => ['vitality' => 2, 'spirit' => 1],
        ];
        $stpResult = $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', $stpPayload)
            ->assertOk()
            ->assertJsonPath('data.unspent_stp', 2)
            ->assertJsonPath('data.allocated_stp.vitality', 2)
            ->assertJsonPath('data.allocated_stp.spirit', 1)
            ->assertJsonPath('data.current_hp', 400)
            ->assertJsonPath('data.status_breakdown.vitality.allocated_stp', 2);
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', $stpPayload)
            ->assertOk()->assertExactJson($stpResult->json());
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            ...$stpPayload,
            'allocations' => ['might' => 1],
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['agility' => 2],
        ])->assertOk()
            ->assertJsonPath('data.unspent_stp', 0)
            ->assertJsonPath('data.allocated_stp.agility', 2)
            ->assertJsonPath('data.status_breakdown.agility.allocated_stp', 2);
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['vitality' => 1],
        ])->assertConflict()->assertJsonPath('code', 'underground_stp_insufficient');
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['unknown' => 1],
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['vitality' => -1],
        ])->assertUnprocessable();

        $skillRequest = (string) Str::uuid();
        $skillPayload = ['request_id' => $skillRequest, 'node_key' => 'miracle_holy_bolt'];
        $skillResult = $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', $skillPayload)
            ->assertOk()
            ->assertJsonPath('data.growth_path.label', '戦技')
            ->assertJsonPath('data.skill_trees.2.label', '祝福')
            ->assertJsonPath('data.skill_trees.2.nodes.0.rank', 1)
            ->assertJsonPath('data.skill_points_unspent', 15)
            ->assertJsonPath('data.skill_points_spent', 5);
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', $skillPayload)
            ->assertOk()->assertExactJson($skillResult->json());
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', null, null, null, null],
        ])->assertOk()
            ->assertJsonPath('data.active_slots.0.key', 'holy_bolt')
            ->assertJsonPath('data.active_slots.0.label', '聖晶弾')
            ->assertJsonCount(5, 'data.active_slots');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_holy_bolt',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_max_rank');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_spirit_channel',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_investment_gate');
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['mending_prayer', null, null, null, null],
        ])->assertConflict()->assertJsonPath('code', 'underground_active_skill_unacquired');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_mending_prayer',
        ])->assertOk()
            ->assertJsonPath('data.skill_points_unspent', 9)
            ->assertJsonPath('data.skill_points_spent', 11)
            ->assertJsonPath('data.skill_trees.2.nodes.3.rank', 1);
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', 'mending_prayer', null, null, null],
        ])->assertOk()
            ->assertJsonPath('data.active_slots.0.key', 'holy_bolt')
            ->assertJsonPath('data.active_slots.1.key', 'mending_prayer');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_spirit_channel',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_investment_gate');
        foreach (array_fill(0, 2, 'miracle_healing_study') as $nodeKey) {
            $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
                'request_id' => (string) Str::uuid(),
                'node_key' => $nodeKey,
            ])->assertOk();
        }
        foreach (array_fill(0, 4, 'miracle_spirit_channel') as $nodeKey) {
            $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
                'request_id' => (string) Str::uuid(),
                'node_key' => $nodeKey,
            ])->assertOk();
        }
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_spirit_channel',
        ])->assertOk()
            ->assertJsonPath('data.skill_points_unspent', 0)
            ->assertJsonPath('data.skill_points_spent', 20)
            ->assertJsonPath('data.skill_trees.2.nodes.1.rank', 5);
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'martial_dagger_flurry',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_prerequisite');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'unknown_node',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_node_unknown');
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', 'holy_bolt', null, null, null],
        ])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', null, null, null, null, null],
        ])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => [null, null, null, null, null],
        ])->assertOk()->assertJsonPath('data.active_slots', [null, null, null, null, null]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'martial_precision_cut',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_points_insufficient');
        $requestCount = UndergroundIntroRequest::query()->count();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_intro_stage_conflict');
        $this->assertSame($requestCount, UndergroundIntroRequest::query()->count());
        $this->assertSame(1, UndergroundBattle::query()->count());
    }

    public function test_inn_and_bank_use_owned_locked_balances_with_exact_transfer_contracts(): void
    {
        [$user, $secretary] = $this->secretaryUser('Shop secretary');
        [$other, $otherSecretary] = $this->secretaryUser('Other shop secretary');
        $profile = UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'shard_balance' => 2350,
            'banked_shard_balance' => 5000,
            'current_hp' => 123,
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);
        $otherProfile = UndergroundProfile::query()->create([
            'secretary_id' => $otherSecretary->id,
            'shard_balance' => 9000,
            'banked_shard_balance' => 8000,
        ]);

        $trialRun = UndergroundTrialRun::query()->create([
            'underground_profile_id' => $profile->id,
            'run_key' => (string) Str::uuid(),
            'trial_key' => 'trial_01',
            'trial_content_identity' => 'secretary-underground-trial-01-v2',
            'next_battle_index' => 2,
            'status' => UndergroundTrialRun::STATUS_ACTIVE,
            'started_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_trial_active');
        $this->assertSame([2350, 123], [
            $profile->fresh()->shard_balance,
            $profile->current_hp,
        ]);
        $trialRun->update([
            'status' => UndergroundTrialRun::STATUS_WITHDRAWN,
            'ended_at' => Carbon::now(),
        ]);

        $innRequest = (string) Str::uuid();
        $inn = $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => $innRequest,
            'secretary_id' => $otherSecretary->id,
            'shard_balance' => 0,
            'banked_shard_balance' => 0,
            'current_hp' => 999999,
            'inn_cost' => 0,
        ])->assertOk()
            ->assertJsonPath('data.shard_balance', 2340)
            ->assertJsonPath('data.banked_shard_balance', 5000)
            ->assertJsonPath('data.current_hp', 492);
        $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => $innRequest,
        ])->assertOk()->assertExactJson($inn->json());
        $this->assertSame([9000, 8000], [
            $otherProfile->fresh()->shard_balance,
            $otherProfile->banked_shard_balance,
        ]);

        $profile->update(['shard_balance' => 9, 'banked_shard_balance' => 5000, 'current_hp' => 123]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_inn_insufficient_carried_shards');
        $this->assertSame([9, 5000, 123], [
            $profile->fresh()->shard_balance,
            $profile->banked_shard_balance,
            $profile->current_hp,
        ]);
        $profile->update(['shard_balance' => 2340, 'banked_shard_balance' => 5000]);

        $transfer = function (string $action, ?int $amount = null) use ($user, $otherSecretary): TestResponse {
            $payload = [
                'request_id' => (string) Str::uuid(),
                'action' => $action,
                'secretary_id' => $otherSecretary->id,
                'shard_balance' => PHP_INT_MAX,
                'banked_shard_balance' => PHP_INT_MAX,
            ];
            if ($amount !== null) {
                $payload['amount'] = $amount;
            }

            return $this->actingAs($user)->postJson('/api/v1/me/underground/bank/transfer', $payload);
        };

        $transfer('deposit', 1000)->assertOk()
            ->assertJsonPath('data.shard_balance', 1340)
            ->assertJsonPath('data.banked_shard_balance', 6000);
        $transfer('withdraw', 1000)->assertOk()
            ->assertJsonPath('data.shard_balance', 2340)
            ->assertJsonPath('data.banked_shard_balance', 5000);
        $transfer('deposit', 2000)->assertOk()
            ->assertJsonPath('data.shard_balance', 340)
            ->assertJsonPath('data.banked_shard_balance', 7000);
        $transfer('withdraw', 2000)->assertOk()
            ->assertJsonPath('data.shard_balance', 2340)
            ->assertJsonPath('data.banked_shard_balance', 5000);

        foreach ([500, 1500] as $invalidAmount) {
            $transfer('deposit', $invalidAmount)
                ->assertConflict()->assertJsonPath('code', 'underground_bank_amount_invalid');
        }
        $transfer('deposit')->assertConflict()->assertJsonPath('code', 'underground_bank_amount_invalid');
        $transfer('deposit', 0)->assertUnprocessable();
        $transfer('withdraw', -1000)->assertUnprocessable();
        $transfer('deposit', 5000)
            ->assertConflict()->assertJsonPath('code', 'underground_bank_insufficient_carried_shards');
        $transfer('withdraw', 6000)
            ->assertConflict()->assertJsonPath('code', 'underground_bank_insufficient_banked_shards');

        $transfer('deposit_all')->assertOk()
            ->assertJsonPath('data.shard_balance', 0)
            ->assertJsonPath('data.banked_shard_balance', 7340);
        $transfer('withdraw_all')->assertOk()
            ->assertJsonPath('data.shard_balance', 7340)
            ->assertJsonPath('data.banked_shard_balance', 0);
        $profile->update([
            'shard_balance' => 1000,
            'banked_shard_balance' => PHP_INT_MAX - 500,
        ]);
        $transfer('deposit', 1000)
            ->assertConflict()->assertJsonPath('code', 'underground_bank_balance_overflow');
        $this->assertSame([1000, PHP_INT_MAX - 500], [
            $profile->fresh()->shard_balance,
            $profile->banked_shard_balance,
        ]);
        $this->assertSame([9000, 8000], [
            $otherProfile->fresh()->shard_balance,
            $otherProfile->banked_shard_balance,
        ]);
        $this->assertSame($other->id, $otherSecretary->user_id);
    }

    public function test_respec_combines_growth_stp_and_skill_reset_without_healing_or_rewinding_progress(): void
    {
        [$user, $secretary] = $this->secretaryUser('Respec secretary');
        $profile = $this->openEquipmentProfile($secretary, 1_000, 9_000);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $profile->update([
            'unlocked_area_layers' => 2,
            'combat_level' => 4,
            'combat_xp' => 345,
            'current_hp' => 123,
            'growth_path_key' => 'guardianship_blue',
            'unspent_stp' => 5,
            'allocated_vitality_stp' => 10,
            'skill_points_total' => 60,
            'skill_points_unspent' => 55,
            'awakening_gauge' => 750,
            'awakening_message' => '私がついています！',
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'miracle_holy_bolt',
            'rank' => 1,
            'active_slot' => 1,
        ]);
        $clearedAt = Carbon::now()->subHour();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => $clearedAt->copy()->subHour(),
            'first_cleared_at' => $clearedAt,
        ]);
        $intro = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->sole();
        $equipmentIds = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($equipmentIds);

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'growth_path_key' => 'free_black',
        ];
        $result = $this->actingAs($user)->postJson('/api/v1/me/underground/respec', $payload)
            ->assertOk()
            ->assertJsonPath('data.stage', 'underground_open')
            ->assertJsonPath('data.growth_path.key', 'free_black')
            ->assertJsonPath('data.shard_balance', 960)
            ->assertJsonPath('data.banked_shard_balance', 9_000)
            ->assertJsonPath('data.combat_level', 4)
            ->assertJsonPath('data.combat_xp', 345)
            ->assertJsonPath('data.current_hp', 123)
            ->assertJsonPath('data.unspent_stp', 18)
            ->assertJsonPath('data.allocated_stp', [
                'vitality' => 0,
                'might' => 0,
                'finesse' => 0,
                'spirit' => 0,
                'agility' => 0,
            ])
            ->assertJsonPath('data.skill_points_total', 60)
            ->assertJsonPath('data.skill_points_unspent', 60)
            ->assertJsonPath('data.active_slots', [null, null, null, null, null])
            ->assertJsonPath('data.respec.cost', 40)
            ->assertJsonPath('data.awakening.current', 750)
            ->assertJsonPath('data.awakening.custom_message', '私がついています！');
        $this->assertNotNull($result->json('data.respec.last_completed_at'));
        $this->assertNotNull($result->json('data.respec.next_available_at'));
        $this->assertSame(
            24 * 60 * 60,
            Carbon::parse((string) $result->json('data.respec.next_available_at'))->getTimestamp()
                - Carbon::parse((string) $result->json('data.respec.last_completed_at'))->getTimestamp(),
        );

        $profile->refresh();
        $this->assertSame([
            2, 4, 345, 960, 9_000, 123, 'free_black', 18, 60, 60, 750, '私がついています！',
        ], [
            $profile->unlocked_area_layers,
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->banked_shard_balance,
            $profile->current_hp,
            $profile->growth_path_key,
            $profile->unspent_stp,
            $profile->skill_points_total,
            $profile->skill_points_unspent,
            $profile->awakening_gauge,
            $profile->awakening_message,
        ]);
        $this->assertSame(0, array_sum($profile->allocatedStp()));
        $this->assertNotNull($profile->underground_contract_completed_at);
        $this->assertNotNull($profile->last_respec_at);
        $this->assertSame(0, UndergroundSkillAllocation::query()
            ->where('underground_profile_id', $profile->id)->count());
        $this->assertSame($equipmentIds, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->orderBy('id')
            ->pluck('id')
            ->all());
        $this->assertDatabaseHas('underground_trial_progress', [
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'first_cleared_at' => $clearedAt,
        ]);
        $this->assertDatabaseHas('underground_intro_progress', [
            'id' => $intro->id,
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'tutorial_battle_id' => $intro->tutorial_battle_id,
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', $payload)
            ->assertOk()->assertExactJson($result->json());
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            ...$payload,
            'growth_path_key' => 'martial_red',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'martial_red',
        ])->assertConflict()->assertJsonPath('code', 'underground_respec_cooldown');
        $this->assertSame(960, $profile->fresh()->shard_balance);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'respec')
            ->count());
    }

    public function test_respec_rejects_invalid_insufficient_and_active_trial_requests_atomically(): void
    {
        [$user, $secretary] = $this->secretaryUser('Respec failure secretary');
        $profile = $this->openEquipmentProfile($secretary, 39, 9_000);
        $profile->update([
            'combat_level' => 4,
            'combat_xp' => 345,
            'current_hp' => 123,
            'unspent_stp' => 5,
            'allocated_vitality_stp' => 10,
            'skill_points_total' => 60,
            'skill_points_unspent' => 55,
        ]);
        $allocation = UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'miracle_holy_bolt',
            'rank' => 1,
            'active_slot' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'unknown_path',
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ])->assertConflict()->assertJsonPath('code', 'underground_respec_insufficient_carried_shards');

        $profile->update(['shard_balance' => 1_000]);
        UndergroundTrialRun::query()->create([
            'underground_profile_id' => $profile->id,
            'run_key' => (string) Str::uuid(),
            'trial_key' => 'trial_01',
            'trial_content_identity' => 'secretary-underground-trial-01-v2',
            'next_battle_index' => 2,
            'status' => UndergroundTrialRun::STATUS_ACTIVE,
            'started_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ])->assertConflict()->assertJsonPath('code', 'underground_trial_active');

        $profile->refresh();
        $this->assertSame([
            4, 345, 1_000, 9_000, 123, 'martial_red', 5, 10, 60, 55, null,
        ], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->banked_shard_balance,
            $profile->current_hp,
            $profile->growth_path_key,
            $profile->unspent_stp,
            $profile->allocated_vitality_stp,
            $profile->skill_points_total,
            $profile->skill_points_unspent,
            $profile->last_respec_at,
        ]);
        $this->assertSame(1, UndergroundSkillAllocation::query()->whereKey($allocation->id)->count());
        $this->assertSame(0, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'respec')
            ->count());
    }

    public function test_true_name_branch_runs_one_logged_alpha_v1_scripted_loss_without_normal_penalties(): void
    {
        [$user] = $this->secretaryUser('Special secretary');
        $this->reachShopkeeperNaming($user);
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => (string) Str::uuid(),
            'name' => ' リカ ',
        ])->assertOk()
            ->assertJsonPath('data.stage', 'special_loss_pending')
            ->assertJsonPath('data.true_name_branch', true);

        $profile = UndergroundProfile::query()->sole();
        $profile->shard_balance = 9;
        $profile->next_battle_at = Carbon::parse('2026-08-30T00:00:00+09:00');
        $profile->save();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now(),
        ]);
        $profile->refresh();
        $before = [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at?->toAtomString(),
        ];
        $requestId = (string) Str::uuid();
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/scripted-loss', [
            'request_id' => $requestId,
        ]);
        $first->assertOk()
            ->assertJsonPath('data.stage', 'special_loss_complete')
            ->assertJsonPath('data.battle.context', 'scripted_loss')
            ->assertJsonPath('data.battle.player_display_name', 'Special secretary')
            ->assertJsonPath('data.battle.encounter_name', 'リカ')
            ->assertJsonPath('data.battle.result', 'defeat')
            ->assertJsonPath('data.battle.rounds', 1)
            ->assertJsonPath('data.battle.xp_awarded', 0)
            ->assertJsonPath('data.battle.shard_delta', 0)
            ->assertJsonPath('data.battle.summary.result', 'defeat')
            ->assertJsonPath('data.battle.summary.player_remaining_hp', 0)
            ->assertJsonPath('data.battle.summary.enemy_remaining_hp', 568_850)
            ->assertJsonPath('data.battle.summary.damage_dealt', 4)
            ->assertJsonPath('data.battle.summary.damage_received', 500)
            ->assertJsonPath('data.battle.actions.0.end_state.player.max_hp', 500)
            ->assertJsonPath('data.battle.actions.0.end_state.enemy.max_hp', 568_850)
            ->assertJsonStructure(['data' => ['battle' => ['actions' => [
                '*' => ['round', 'actions', 'end_state'],
            ]]]]);
        $firstRoundActions = $first->json('data.battle.actions.0.actions');
        $this->assertIsArray($firstRoundActions);
        $barrierDamage = collect($firstRoundActions)->first(
            static fn (array $action): bool => $action['type'] === 'damage',
        );
        $fatalDamage = collect($firstRoundActions)->first(
            static fn (array $action): bool => $action['type'] === 'counter',
        );
        $this->assertIsArray($barrierDamage);
        $this->assertSame('精密斬り', $barrierDamage['label']);
        $this->assertFalse($barrierDamage['evaded']);
        $this->assertSame(0, $barrierDamage['amount']);
        $this->assertSame(4, $barrierDamage['barrier_absorbed']);
        $this->assertNull($barrierDamage['agility_combo_hits']);
        $this->assertIsArray($fatalDamage);
        $this->assertSame('反撃', $fatalDamage['label']);
        $this->assertFalse($fatalDamage['evaded']);
        $this->assertGreaterThan(500, $fatalDamage['amount']);
        $this->actingAs($user)->postJson('/api/v1/me/underground/scripted-loss', [
            'request_id' => $requestId,
        ])->assertOk()->assertExactJson($first->json());

        $profile->refresh();
        $this->assertSame($before, [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at?->toAtomString(),
        ]);
        $this->assertSame(1, UndergroundTrialProgress::query()->count());
        $this->assertSame(2, UndergroundBattle::query()->count());
        $this->assertSame(2, UndergroundBattleLog::query()->count());
        $this->assertSame(1, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_STORY)->count());
        $this->assertNull($profile->current_hp);
        $storyBattle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_STORY)->sole();
        $this->assertSame('secretary-underground-alpha-v2', $storyBattle->runtime_identity);
        $this->assertSame(1254, $storyBattle->snapshot['enemy_combat_level_equivalent']);
        $this->assertSame(1_137_700, $storyBattle->snapshot['enemy_scale_bps']);
        $storyDefinition = app(UndergroundAlphaV1PlayerCatalog::class)->trueNameStoryBattle();
        $this->assertSame([
            'unbroken_retort',
            'renewing_guard',
            'bulwark_strike',
            'counter_stance',
            'shield_bash',
        ], $storyDefinition['catalog']->enemy($storyDefinition['enemy_key'])['skills']);

        $this->advance($user, 'special_loss_aftermath_complete')
            ->assertJsonPath('data.stage', 'shop_explanation');
        $this->advance($user, 'shop_explanation_complete')
            ->assertJsonPath('data.stage', 'contract_ready');
    }

    public function test_hidden_alias_matching_is_exact_and_keeps_the_normalized_display_name(): void
    {
        $catalog = app(UndergroundIntroCatalog::class);
        foreach (['リカ', '雨宮利香', '雨宮 利香', '雨宮　利香', 'リカ・サキュバス'] as $alias) {
            $normalized = $catalog->normalizeShopkeeperName("　{$alias}　");
            $this->assertSame($alias, $normalized);
            $this->assertSame('true_name', $catalog->branchIdentity($normalized));
        }
        $decomposed = \Normalizer::normalize('リカ・サキュバス', \Normalizer::FORM_D);
        $this->assertIsString($decomposed);
        $this->assertNotSame('リカ・サキュバス', $decomposed);
        $this->assertSame($decomposed, $catalog->normalizeShopkeeperName($decomposed));
        $this->assertSame('true_name', $catalog->branchIdentity($decomposed));
        foreach (['エリカ', 'リカちゃん', '雨宮利香さん', '雨 宮利香', '雨宮利 香', 'ダミー'] as $normal) {
            $this->assertSame('normal', $catalog->branchIdentity($normal));
        }
    }

    public function test_growth_catalog_and_rewardless_playtest_are_exact_owner_scoped_and_idempotent(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $paths = collect($catalog->growthPaths())->keyBy('key');
        $this->assertSame([
            'martial_red' => [[18, 34, 30, 8, 10], 484, 'pure_attacker', [1, 2, 1, 1, 0], 5],
            'guardianship_blue' => [[40, 22, 10, 16, 12], 660, 'pure_tank', [2, 1, 1, 1, 0], 5],
            'blessing_green' => [[22, 8, 16, 42, 12], 516, 'pure_healer', [1, 1, 1, 2, 0], 5],
            'free_black' => [[26, 22, 20, 20, 12], 548, 'balanced', [1, 1, 1, 1, 0], 6],
        ], $paths->map(fn (array $path): array => [
            array_values($path['stats']),
            $path['max_hp'],
            $path['default_build_key'],
            array_values($path['natural_growth']),
            $path['unspent_stp_per_level'],
        ])->all());
        foreach ($paths as $path) {
            $this->assertSame(100, array_sum($path['stats']));
            $this->assertSame(10, $path['points_per_level']);
            $this->assertSame(10_000, $path['max_mp']);
            $this->assertSame(300, $path['natural_recovery']);
        }
        $this->assertSame(
            [23, 44, 37, 16, 15],
            array_values($catalog->currentStats(
                'martial_red',
                5,
                ['vitality' => 1, 'might' => 2, 'finesse' => 3, 'spirit' => 4, 'agility' => 5],
            )),
        );
        $this->assertSame([20, 24], [
            $catalog->stpEntitlement('martial_red', 5),
            $catalog->stpEntitlement('free_black', 5),
        ]);
        $starterWeapon = $catalog->starterWeapon();
        $this->assertSame(
            [24, 45, 38, 17, 16],
            array_values($catalog->combatStats(
                ['vitality' => 23, 'might' => 44, 'finesse' => 37, 'spirit' => 16, 'agility' => 15],
                $starterWeapon,
            )),
        );
        $this->assertSame(
            ['starter_knife', '護身用ナイフ', 1, 'common', [], null],
            array_values(Arr::only($starterWeapon, [
                'key', 'label', 'item_level', 'rarity', 'affixes', 'unique_effect',
            ])),
        );
        $encounters = collect($catalog->explorationEncounters())->keyBy('key');
        $this->assertSame([2500, 2500, 2000, 1000, 1000, 900, 100],
            $encounters->pluck('weight')->values()->all());
        $this->assertSame(10_000, $encounters->sum('weight'));
        $vanillaWeightedXp = $encounters->except('crystal_bug')
            ->sum(fn (array $encounter): int => $encounter['weight'] * $encounter['xp']);
        $vanillaWeight = $encounters->except('crystal_bug')->sum('weight');
        $this->assertEqualsWithDelta(
            25,
            $encounters['crystal_bug']['xp'] / ($vanillaWeightedXp / $vanillaWeight),
            0.2,
        );
        $crystalBug = config('underground-alpha-v1.exploration.grounds.shallow_caves.encounters.crystal_bug');
        $this->assertIsArray($crystalBug);
        $this->assertSame('輝石虫', $crystalBug['label']);
        $this->assertSame(
            ['complete_guard_chance_bps' => 9900],
            $crystalBug['enemy']['modifiers'],
        );
        foreach (['depth_stalker', 'pressure_construct', 'crystal_warden'] as $enemyKey) {
            $this->assertSame(100, $catalog->playtestDefinition('pure_tank', $enemyKey)['max_rounds']);
        }

        [$user, $secretary] = $this->secretaryUser('Playtest secretary');
        $profile = UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'guardianship_blue',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'current_hp' => 321,
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);
        $before = $profile->fresh()->only([
            'combat_level', 'combat_xp', 'shard_balance', 'next_battle_at',
            'growth_path_key', 'growth_path_identity', 'growth_path_selected_at',
            'current_hp',
        ]);

        $this->actingAs($user)->getJson('/api/v1/me/underground/playtest')
            ->assertOk()
            ->assertJsonPath('data.default_build_key', 'pure_tank')
            ->assertJsonCount(4, 'data.builds')
            ->assertJsonCount(3, 'data.enemies');
        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'build_key' => 'pure_tank',
            'enemy_key' => 'depth_stalker',
        ];
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', $payload)
            ->assertOk()
            ->assertJsonPath('data.context', 'playtest')
            ->assertJsonPath('data.player_display_name', 'Playtest secretary')
            ->assertJsonPath('data.summary.result', 'victory')
            ->assertJsonPath('data.rewards.xp', 0)
            ->assertJsonPath('data.rewards.shards', 0)
            ->assertJsonStructure(['data' => [
                'summary' => [
                    'rounds', 'player_remaining_hp', 'enemy_remaining_hp', 'final_mp',
                    'damage_dealt', 'damage_received', 'effective_healing', 'damage_prevented',
                    'mp_spent', 'mp_natural_recovery', 'mp_skill_recovery', 'skill_unavailable_due_to_mp',
                ],
                'rounds' => ['*' => ['round', 'actions', 'end_state']],
            ]])
            ->assertJsonMissingPath('data.private_seed')
            ->assertJsonMissingPath('data.snapshot')
            ->assertJsonMissingPath('data.manifest');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', $payload)
            ->assertOk()->assertExactJson($first->json());
        $secretary->name = 'Renamed secretary';
        $secretary->save();
        $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.context', 'playtest')
            ->assertJsonPath('data.0.player_display_name', 'Playtest secretary')
            ->assertJsonPath('data.0.rounds', null)
            ->assertJsonPath('data.0.detail_available', true);
        $detail = $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.context', 'playtest')
            ->assertJsonPath('data.player_display_name', 'Playtest secretary')
            ->assertJsonPath('data.build_name', '護身特化')
            ->assertJsonPath('data.encounter_name', '深層追跡者')
            ->assertJsonCount((int) $first->json('data.summary.rounds'), 'data.rounds');
        $projectedActions = collect($detail->json('data.rounds'))
            ->flatMap(fn (array $round): array => $round['actions']);
        $this->assertTrue($projectedActions->contains(
            fn (array $action): bool => ($action['type'] ?? null) === 'action'
                && ($action['actor_name'] ?? null) === 'Playtest secretary',
        ));
        $this->assertTrue($projectedActions->every(
            fn (array $action): bool => ! array_key_exists('reason', $action)
                && ! array_key_exists('action_key', $action),
        ));
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            ...$payload,
            'build_key' => 'balanced',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            ...$payload,
            'request_id' => (string) Str::uuid(),
            'build_key' => 'unsupported',
        ])->assertUnprocessable();

        $this->assertEquals($before, $profile->fresh()->only(array_keys($before)));
        $this->assertSame(1, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_PLAYTEST)->count());
        $this->assertSame(0, UndergroundTrialProgress::query()->count());

        [$other] = $this->secretaryUser('Locked playtest secretary');
        $this->actingAs($other)->postJson('/api/v1/me/underground/playtest', [
            ...$payload,
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_playtest_locked');

        $playtestBattle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_PLAYTEST)
            ->sole();
        $this->assertSame(AlphaV1CombatRules::IDENTITY, $playtestBattle->runtime_identity);
        $this->assertSame(AlphaV1CombatRules::IDENTITY, $playtestBattle->snapshot['combat_rules_identity']);
        $this->assertTrue($playtestBattle->log?->expires_at->equalTo($playtestBattle->finished_at->addHour()) ?? false);
        config([
            'underground-alpha-v1.playtest.builds' => [],
            'underground-alpha-v1.playtest.enemies' => [],
        ]);
        $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.build_name', '護身特化')
            ->assertJsonPath('data.encounter_name', '深層追跡者')
            ->assertJsonCount((int) $first->json('data.summary.rounds'), 'data.rounds');

        foreach (range(1, 100) as $offset) {
            $copy = $playtestBattle->replicate();
            $copy->request_id = (string) Str::uuid();
            $copy->started_at = $playtestBattle->started_at->subSeconds($offset);
            $copy->finished_at = $playtestBattle->finished_at->subSeconds($offset);
            $copy->save();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $history = $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.build_name', '護身特化')
            ->assertJsonPath('data.0.encounter_name', '深層追跡者');
        $this->assertCount(20, $history->json('data'));
        $this->assertSame([], array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains(
                strtolower((string) ($query['query'] ?? '')),
                'from "underground_battle_logs"',
            ) && ! str_contains(strtolower((string) ($query['query'] ?? '')), 'exists'),
        )));
        DB::disableQueryLog();
    }

    public function test_equipment_shop_vault_purchase_sell_and_equip_are_owner_scoped_atomic_and_idempotent(): void
    {
        [$user, $secretary] = $this->secretaryUser('Equipment secretary');
        [$otherUser, $otherSecretary] = $this->secretaryUser('Other equipment secretary');
        $profile = $this->openEquipmentProfile($secretary);
        $otherProfile = $this->openEquipmentProfile($otherSecretary, 0, 0);
        $profile->update(['skill_points_total' => 22, 'skill_points_unspent' => 0]);
        foreach ([
            'martial_precision_cut' => ['rank' => 1, 'active_slot' => null],
            'martial_weapon_mastery' => ['rank' => 5, 'active_slot' => null],
            'martial_dagger_flurry' => ['rank' => 1, 'active_slot' => 1],
        ] as $nodeKey => $allocation) {
            UndergroundSkillAllocation::query()->create([
                'underground_profile_id' => $profile->id,
                'node_key' => $nodeKey,
                ...$allocation,
            ]);
        }

        $main = $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.used', 1)
            ->assertJsonPath('data.equipment_summary.capacity', 500)
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'starter_knife')
            ->assertJsonPath('data.default_hunting_ground_key', 'shallow_caves')
            ->assertJsonPath('data.hunting_grounds.0.key', 'shallow_caves')
            ->assertJsonPath('data.hunting_grounds.0.locked', false)
            ->assertJsonPath('data.hunting_grounds.1.key', 'black_crystal_cave')
            ->assertJsonPath('data.hunting_grounds.1.locked', true)
            ->assertJsonPath('data.hunting_grounds.1.unlock_condition', '試練1を初回clear')
            ->assertJsonMissingPath('data.equipment_summary.items');

        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => (string) Str::uuid(),
            'hunting_ground_key' => 'unknown_ground',
        ])->assertConflict()->assertJsonPath('code', 'underground_hunting_ground_not_supported');
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => (string) Str::uuid(),
            'hunting_ground_key' => 'black_crystal_cave',
        ])->assertConflict()->assertJsonPath('code', 'underground_hunting_ground_locked');
        $this->assertFalse($main->json('data.equipment_summary.equipped.weapon.shop_sold'));
        $starterId = (int) $main->json('data.equipment_summary.equipped.weapon.id');
        $this->assertSame([], UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('activity_type', UndergroundBattle::ACTIVITY_TUTORIAL)
            ->sole()->snapshot);
        $shop = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/shop')
            ->assertOk()
            ->assertJsonPath('data.catalog_identity', 'secretary-underground-shop-equipment-alpha-v2')
            ->assertJsonPath('data.currency_label', '輝石の欠片 G')
            ->assertJsonPath('data.shard_balance', 5_000)
            ->assertJsonPath('data.banked_shard_balance', 5_000)
            ->assertJsonPath('data.bank_auto_withdraw', false);
        $this->assertCount(40, $shop->json('data.items'));
        $this->assertCount(1, $shop->json('data.owned_items'));
        foreach ($shop->json('data.items') as $shopItem) {
            $this->assertSame(intdiv($shopItem['buy_price'], 2), $shopItem['sell_price']);
        }
        $this->assertSame([null, 0, false], [
            $shop->json('data.owned_items.0.buy_price'),
            $shop->json('data.owned_items.0.sell_price'),
            $shop->json('data.owned_items.0.sellable'),
        ]);
        $blackDagger = collect($shop->json('data.items'))->firstWhere('key', 'black_crystal_dagger');
        $this->assertSame([true, '試練1を初回clear', 'ノービス'], [
            $blackDagger['locked'],
            $blackDagger['unlock_requirement'],
            $blackDagger['rarity_label'],
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'black_crystal_dagger',
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_locked');

        $forgedRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => $forgedRequest,
            'definition_key' => 'iron_dagger',
            'buy_price' => 1,
            'stats' => ['might' => PHP_INT_MAX],
        ])->assertUnprocessable();
        $this->assertSame(5_000, $profile->fresh()->shard_balance);

        $purchaseRequest = (string) Str::uuid();
        $purchasePayload = ['request_id' => $purchaseRequest, 'definition_key' => 'iron_dagger'];
        $purchase = $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/shop/purchase', $purchasePayload)
            ->assertOk()
            ->assertJsonPath('data.shard_balance', 4_880)
            ->assertJsonPath('data.banked_shard_balance', 5_000)
            ->assertJsonPath('data.vault.used', 2);
        $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/shop/purchase', $purchasePayload)
            ->assertOk()->assertExactJson($purchase->json());
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')->count());
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'iron_dagger',
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_already_owned');
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => $purchaseRequest,
            'definition_key' => 'bronze_rapier',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'iron_longsword',
        ])->assertOk()
            ->assertJsonPath('data.shard_balance', 4_760)
            ->assertJsonPath('data.vault.used', 3);

        $profile->update(['shard_balance' => 0]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'steel_dagger',
        ])->assertConflict()
            ->assertJsonPath('code', 'underground_equipment_insufficient_carried_shards');
        $this->assertSame([0, 5_000], [
            $profile->fresh()->shard_balance,
            $profile->banked_shard_balance,
        ]);
        $profile->update(['shard_balance' => 5_000]);

        foreach (['leather_armor', 'vitality_accessory_rank_1'] as $definitionKey) {
            $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
                'request_id' => (string) Str::uuid(),
                'definition_key' => $definitionKey,
            ])->assertOk();
        }
        $ironDagger = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')->sole();
        $ironLongsword = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_longsword')->sole();
        $armor = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'leather_armor')->sole();
        $accessory = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'vitality_accessory_rank_1')->sole();

        $equipWeaponRequest = (string) Str::uuid();
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => $equipWeaponRequest,
            'item_id' => $ironDagger->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_dagger');
        $this->assertSame(484, $profile->fresh()->current_hp);
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => $equipWeaponRequest,
            'item_id' => $ironDagger->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_dagger');
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $ironLongsword->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_longsword');
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'iron_longsword')
            ->assertJsonPath('data.active_slots.0.key', 'dagger_flurry');
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $ironDagger->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_dagger');
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'iron_dagger')
            ->assertJsonPath('data.active_slots.0.key', 'dagger_flurry');
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/weapon', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_slot_invalid');

        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $armor->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.armor.key', 'leather_armor');
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $accessory->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.accessory_1.key', 'vitality_accessory_rank_1');
        $this->assertSame(484, $profile->fresh()->current_hp);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.growth_path.max_hp', 520)
            ->assertJsonPath('data.current_hp', 484);
        $this->assertSame([1, 0, 0, 0, 0, 0, 0, 0], [
            $profile->fresh()->combat_level,
            $profile->combat_xp,
            $profile->unspent_stp,
            $profile->allocated_vitality_stp,
            $profile->allocated_might_stp,
            $profile->allocated_finesse_stp,
            $profile->allocated_spirit_stp,
            $profile->allocated_agility_stp,
        ]);
        config([
            'underground-alpha-v1.exploration.drop.profiles.standard.presence_bps' => 0,
            'underground-alpha-v1.exploration.drop.profiles.elite.presence_bps' => 0,
            'underground-alpha-v1.exploration.drop.profiles.rare.presence_bps' => 0,
        ]);
        $explorationRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => $explorationRequest,
            'weapon_power' => PHP_INT_MAX,
            'equipment' => ['key' => 'forged_equipment'],
        ])->assertOk();
        $equipmentBattle = UndergroundBattle::query()
            ->where('request_id', $explorationRequest)->sole();
        $this->assertSame('iron_dagger', $equipmentBattle->snapshot['equipment']['key']);
        $this->assertSame(['dagger_flurry'], $equipmentBattle->snapshot['equipped_active_skills']);
        $this->assertSame(30, $equipmentBattle->snapshot['equipment']['weapon_power']);
        $this->assertSame([12, 9, 20], [
            $equipmentBattle->snapshot['equipment']['physical_defense'],
            $equipmentBattle->snapshot['equipment']['magical_defense'],
            $equipmentBattle->snapshot['equipment']['max_hp'],
        ]);
        $this->assertSame(
            ['iron_dagger', 'leather_armor', 'vitality_accessory_rank_1'],
            array_column($equipmentBattle->snapshot['equipment']['items'], 'key'),
        );
        $this->assertEquals([
            'vitality' => 20, 'might' => 34, 'finesse' => 33, 'spirit' => 8, 'agility' => 12,
        ], $equipmentBattle->snapshot['combat_stats']);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_equipped');
        $this->actingAs($otherUser)->getJson('/api/v1/me/underground/main')
            ->assertOk()->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'starter_knife');
        $this->actingAs($otherUser)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $accessory->id,
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_not_owned');
        $this->actingAs($otherUser)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_not_owned');
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $otherProfile->id)
            ->where('definition_key', 'starter_knife')->count());

        $profile->update(['current_hp' => 515]);
        $unequipRequest = (string) Str::uuid();
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/armor', [
            'request_id' => $unequipRequest,
        ])->assertOk()->assertJsonPath('data.vault.equipped.armor', null);
        $this->assertSame(492, $profile->fresh()->current_hp);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => (string) Str::uuid(),
            'sell_price' => 9_999,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $armor->id]);
        $balanceBeforeSale = $profile->fresh()->shard_balance;
        $saleRequest = (string) Str::uuid();
        $sale = $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => $saleRequest,
        ])->assertOk()
            ->assertJsonPath('data.shard_balance', $balanceBeforeSale + 50)
            ->assertJsonPath('data.banked_shard_balance', 5_000)
            ->assertJsonPath('data.vault.used', 4);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => $saleRequest,
        ])->assertOk()->assertExactJson($sale->json());
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$accessory->id}/sell", [
            'request_id' => $saleRequest,
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->assertDatabaseMissing('underground_owned_equipment', ['id' => $armor->id]);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$starterId}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_not_sellable');
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/accessory', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.vault.equipped.accessory_1', null);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'leather_armor',
        ])->assertOk();

        $used = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)->count();
        $fillCount = 500 - $used;
        $rows = [];
        foreach (range(1, $fillCount) as $offset) {
            $rows[] = [
                'underground_profile_id' => $profile->id,
                'definition_key' => 'bronze_rapier',
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
                'equipped_slot' => null,
                'grant_key' => null,
                'acquired_at' => Carbon::now()->addSecond($offset),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }
        DB::table('underground_owned_equipment')->insert($rows);
        $profile->update(['shard_balance' => 10_000]);
        $balanceAtCapacity = $profile->fresh()->shard_balance;
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'steel_dagger',
        ])->assertConflict()->assertJsonPath('code', 'underground_vault_full');
        $this->assertSame($balanceAtCapacity, $profile->fresh()->shard_balance);
        $this->assertSame(500, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)->count());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $vault = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?page=1')
            ->assertOk()
            ->assertJsonPath('data.used', 500)
            ->assertJsonPath('data.capacity', 500)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.per_page', 50)
            ->assertJsonPath('data.last_page', 10)
            ->assertJsonPath('data.total', 500);
        $this->assertCount(50, $vault->json('data.items'));
        $equipmentQueries = collect(DB::getQueryLog())->filter(
            static fn (array $query): bool => str_contains($query['query'], 'underground_owned_equipment'),
        );
        $this->assertLessThanOrEqual(8, $equipmentQueries->count());
        DB::disableQueryLog();
        $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?page=10')
            ->assertOk()->assertJsonCount(50, 'data.items');
        $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?page=11')
            ->assertConflict()->assertJsonPath('code', 'underground_vault_page_invalid');
    }

    public function test_bulk_sale_previews_canonical_filters_and_atomically_sells_only_the_quoted_items(): void
    {
        [$user, $secretary] = $this->secretaryUser('Bulk sale secretary');
        [$otherUser, $otherSecretary] = $this->secretaryUser('Other bulk sale secretary');
        $profile = $this->openEquipmentProfile($secretary, 100, 0);
        $otherProfile = $this->openEquipmentProfile($otherSecretary, 0, 0);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $this->actingAs($otherUser)->getJson('/api/v1/me/underground/main')->assertOk();

        $fixed = [];
        foreach (['iron_dagger', 'bronze_rapier'] as $definitionKey) {
            $fixed[$definitionKey] = UndergroundOwnedEquipment::query()->create([
                'underground_profile_id' => $profile->id,
                'definition_key' => $definitionKey,
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
                'equipped_slot' => null,
                'grant_key' => null,
                'instance_kind' => 'fixed',
                'acquired_at' => Carbon::now(),
            ]);
        }
        $generated = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            30,
            'shallow_caves',
            'epic',
            'armor',
            null,
            null,
            31,
            'bulk-sale-generated-equipment',
        );
        $generatedItem = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => $generated['key'],
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => 'drop:bulk-sale-generated-equipment',
            'instance_kind' => 'generated',
            'instance_identity' => $generated['instance_identity'],
            'generator_identity' => $generated['generator_identity'],
            'generated_payload' => $generated,
            'source_battle_id' => UndergroundBattle::query()
                ->where('underground_profile_id', $profile->id)
                ->where('activity_type', UndergroundBattle::ACTIVITY_TUTORIAL)
                ->sole()->id,
            'acquired_at' => Carbon::now()->addSecond(),
        ]);
        $equippedArmor = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => 'leather_armor',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => 'armor',
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now(),
        ]);
        $otherItem = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $otherProfile->id,
            'definition_key' => 'iron_dagger',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now(),
        ]);

        $vault = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault')
            ->assertOk()
            ->assertJsonPath('data.bulk_sell_options.rarities.0', ['key' => 'novice', 'label' => 'ノービス'])
            ->assertJsonPath('data.bulk_sell_options.rarities.5', ['key' => 'unique', 'label' => 'ユニーク'])
            ->assertJsonPath('data.bulk_sell_options.categories.0', ['key' => 'weapon', 'label' => '武器'])
            ->assertJsonPath('data.bulk_sell_options.weapon_styles.0', ['key' => 'dagger', 'label' => '短剣'])
            ->assertJsonPath('data.bulk_sell_options.weapon_styles.3', ['key' => 'crystal_staff', 'label' => '輝石杖']);
        $this->assertCount(6, $vault->json('data.bulk_sell_options.rarities'));
        $this->assertCount(4, $vault->json('data.bulk_sell_options.weapon_styles'));

        $filters = [
            'item_level_max' => 30,
            'rarities' => ['novice', 'relic'],
            'categories' => ['weapon', 'armor'],
            'weapon_styles' => ['dagger'],
        ];
        $preview = $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/vault/bulk-sell/preview', $filters)
            ->assertOk()
            ->assertJsonPath('data.catalog_identity', 'secretary-underground-shop-equipment-alpha-v2')
            ->assertJsonPath('data.count', 2);
        $previewItems = collect($preview->json('data.items'));
        $this->assertEqualsCanonicalizing(
            [$fixed['iron_dagger']->id, $generatedItem->id],
            $previewItems->pluck('id')->all(),
        );
        $this->assertNotContains($fixed['bronze_rapier']->id, $previewItems->pluck('id')->all());
        $this->assertNotContains($equippedArmor->id, $previewItems->pluck('id')->all());
        $this->assertSame(60, $previewItems->firstWhere('id', $fixed['iron_dagger']->id)['sell_price']);
        $this->assertSame($generated['sell_price'], $previewItems->firstWhere('id', $generatedItem->id)['sell_price']);
        $this->assertSame(60 + $generated['sell_price'], $preview->json('data.total_sell_price'));
        $quotes = $previewItems->map(static fn (array $item): array => [
            'id' => $item['id'],
            'sell_price' => $item['sell_price'],
        ])->values()->all();

        $duplicate = [$quotes[0], $quotes[0]];
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => (string) Str::uuid(),
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $duplicate,
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.id');

        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => (string) Str::uuid(),
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => [['id' => $otherItem->id, 'sell_price' => 60]],
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        $starter = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'starter_knife')->sole();
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => (string) Str::uuid(),
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => [['id' => $starter->id, 'sell_price' => 1]],
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');

        $saleRequest = (string) Str::uuid();
        $wrongQuote = $quotes;
        $wrongQuote[0]['sell_price']++;
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => $saleRequest,
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $wrongQuote,
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        $this->assertSame(100, $profile->fresh()->shard_balance);
        foreach ($quotes as $quote) {
            $this->assertDatabaseHas('underground_owned_equipment', ['id' => $quote['id']]);
        }

        $equippedArmor->update(['equipped_slot' => null]);
        $generatedItem->update(['equipped_slot' => 'armor']);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => $saleRequest,
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $quotes,
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        foreach ($quotes as $quote) {
            $this->assertDatabaseHas('underground_owned_equipment', ['id' => $quote['id']]);
        }
        $generatedItem->update(['equipped_slot' => null]);

        $pickedUpAfterPreview = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => 'steel_dagger',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now()->addSeconds(2),
        ]);
        $salePayload = [
            'request_id' => $saleRequest,
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $quotes,
        ];
        $sale = $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', $salePayload)
            ->assertOk()
            ->assertJsonPath('data.operation', 'equipment_bulk_sell')
            ->assertJsonPath('data.shard_balance', 160 + $generated['sell_price']);
        $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', $salePayload)
            ->assertOk()->assertExactJson($sale->json());
        foreach ($quotes as $quote) {
            $this->assertDatabaseMissing('underground_owned_equipment', ['id' => $quote['id']]);
        }
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $fixed['bronze_rapier']->id]);
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $pickedUpAfterPreview->id]);
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $otherItem->id]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            ...$salePayload,
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        $this->assertSame(160 + $generated['sell_price'], $profile->fresh()->shard_balance);
    }

    public function test_three_accessory_slots_and_trial_one_shop_unlock_keep_fixed_catalog_compatibility(): void
    {
        [$user, $secretary] = $this->secretaryUser('Three slot equipment secretary');
        $profile = $this->openEquipmentProfile($secretary, 20_000, 0);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $starter = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'starter_knife')
            ->sole();
        $starter->catalog_identity = 'secretary-underground-shop-equipment-alpha-v1';
        $starter->save();

        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.weapon.catalog_identity', 'secretary-underground-shop-equipment-alpha-v1')
            ->assertJsonPath('data.equipment_summary.equipped.accessory_1', null)
            ->assertJsonPath('data.equipment_summary.equipped.accessory_2', null)
            ->assertJsonPath('data.equipment_summary.equipped.accessory_3', null);

        $legacyRequestId = (string) Str::uuid();
        UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => 'iron_dagger',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
            'equipped_slot' => null,
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now(),
        ]);
        $legacyFingerprint = hash('sha256', json_encode([
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
            'operation' => 'equipment_purchase',
            'payload' => ['definition_key' => 'iron_dagger'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        UndergroundIntroRequest::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $legacyRequestId,
            'request_fingerprint' => $legacyFingerprint,
            'operation' => 'equipment_purchase',
            'resulting_stage' => 'underground_open',
            'underground_battle_id' => null,
        ]);
        $balanceBeforeLegacyReplay = $profile->shard_balance;
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => $legacyRequestId,
            'definition_key' => 'iron_dagger',
        ])->assertOk()->assertJsonPath('data.shard_balance', $balanceBeforeLegacyReplay);
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')
            ->count());

        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now(),
            'first_cleared_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/shop')
            ->assertOk()
            ->assertJsonPath('data.items.30.locked', false);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'black_crystal_dagger',
        ])->assertOk();

        $items = [];
        foreach (['vitality', 'might', 'finesse'] as $stat) {
            $key = $stat.'_accessory_rank_1';
            $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
                'request_id' => (string) Str::uuid(),
                'definition_key' => $key,
            ])->assertOk();
            $items[] = UndergroundOwnedEquipment::query()
                ->where('underground_profile_id', $profile->id)
                ->where('definition_key', $key)
                ->sole();
        }

        foreach ($items as $index => $item) {
            $slot = 'accessory_'.($index + 1);
            $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
                'request_id' => (string) Str::uuid(),
                'item_id' => $item->id,
                'target_slot' => $slot,
            ])->assertOk()->assertJsonPath("data.vault.equipped.{$slot}.id", $item->id);
        }
        $this->assertSame(492, $profile->fresh()->current_hp);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.accessory_1.key', 'vitality_accessory_rank_1')
            ->assertJsonPath('data.equipment_summary.equipped.accessory_2.key', 'might_accessory_rank_1')
            ->assertJsonPath('data.equipment_summary.equipped.accessory_3.key', 'finesse_accessory_rank_1');

        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $items[0]->id,
            'target_slot' => 'weapon',
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_slot_invalid');
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/accessory_2', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.vault.equipped.accessory_2', null);
    }

    public function test_generated_payload_is_persisted_projected_and_used_by_canonical_combat_without_regeneration(): void
    {
        [$user, $secretary] = $this->secretaryUser('Generated equipment secretary');
        $profile = $this->openEquipmentProfile($secretary, 5_000, 0);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $sourceBattle = UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('activity_type', UndergroundBattle::ACTIVITY_TUTORIAL)
            ->sole();
        $generated = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            30,
            'shallow_caves',
            'epic',
            'armor',
            null,
            null,
            23,
            'generated-equipment-feature-test',
        );
        $item = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => $generated['key'],
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => 'drop:generated-equipment-test',
            'instance_kind' => 'generated',
            'instance_identity' => $generated['instance_identity'],
            'generator_identity' => $generated['generator_identity'],
            'generated_payload' => $generated,
            'source_battle_id' => $sourceBattle->id,
            'acquired_at' => Carbon::now(),
        ]);

        $vault = $this->actingAs($user)
            ->getJson('/api/v1/me/underground/equipment/vault')
            ->assertOk();
        $projected = collect($vault->json('data.items'))->firstWhere('id', $item->id);
        $this->assertSame([
            'generated',
            $generated['instance_identity'],
            'secretary-underground-drop-equipment-alpha-v1',
            'epic',
            'レリック',
        ], [
            $projected['instance_kind'],
            $projected['instance_identity'],
            $projected['generator_identity'],
            $projected['rarity'],
            $projected['rarity_label'],
        ]);
        $this->assertEquals(array_map(
            static fn (array $affix): array => Arr::only(
                $affix,
                ['key', 'label', 'kind', 'target', 'value', 'quality_bps'],
            ),
            $generated['affixes'],
        ), $projected['affixes']);
        $this->assertArrayNotHasKey('source', $projected);
        $this->assertArrayNotHasKey('base', $projected);
        $this->assertEquals($generated['stats'], $projected['stats']);
        $this->assertEquals($generated['modifiers'], $projected['modifiers']);

        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $item->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.armor.instance_identity', $generated['instance_identity']);

        $exploreRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => $exploreRequest,
        ])->assertOk();
        $battle = UndergroundBattle::query()->where('request_id', $exploreRequest)->sole();
        $this->assertSame($generated['instance_identity'], collect(
            $battle->snapshot['equipment']['items'],
        )->firstWhere('equipped_slot', 'armor')['instance_identity']);
        $this->assertEquals($generated['modifiers'], $battle->snapshot['equipment']['modifiers']);
        $this->assertCount(count($generated['affixes']), $battle->snapshot['equipment']['affixes']);
        $this->assertEquals($generated, $item->fresh()->generated_payload);
    }

    public function test_player_runtime_filters_active_skills_by_actual_weapon_without_clearing_the_saved_slot(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $equipment = config('underground-alpha-v1.exploration.starter_weapon');
        $this->assertIsArray($equipment);
        $allocations = [
            'martial_precision_cut' => ['rank' => 1, 'active_slot' => null],
            'martial_weapon_mastery' => ['rank' => 5, 'active_slot' => null],
            'martial_dagger_flurry' => ['rank' => 1, 'active_slot' => 1],
            'miracle_holy_bolt' => ['rank' => 1, 'active_slot' => 2],
            'miracle_mending_prayer' => ['rank' => 1, 'active_slot' => 3],
        ];

        foreach (['dagger', 'rapier', 'longsword', 'crystal_staff', 'dagger'] as $weaponStyle) {
            $equipment['weapon_style'] = $weaponStyle;
            $definition = $catalog->explorationCombatDefinition(
                'free_black',
                1,
                ['vitality' => 0, 'might' => 0, 'finesse' => 0, 'spirit' => 0, 'agility' => 0],
                $equipment,
                '装備条件試験の秘書',
                skillAllocations: $allocations,
            );
            $supportsFlurry = in_array($weaponStyle, ['dagger', 'rapier'], true);
            $this->assertSame(
                $supportsFlurry
                    ? ['dagger_flurry', 'holy_bolt', 'mending_prayer']
                    : ['holy_bolt', 'mending_prayer'],
                $definition['active_skills'],
                $weaponStyle,
            );
            $actions = array_column($definition['player_snapshot']['ai_rules'], 'action');
            $this->assertSame($supportsFlurry, in_array('skill:dagger_flurry', $actions, true), $weaponStyle);
            $this->assertContains('skill:holy_bolt', $actions, $weaponStyle);
            $this->assertContains('skill:mending_prayer', $actions, $weaponStyle);
            $this->assertSame('normal_attack', $actions[array_key_last($actions)], $weaponStyle);
            $this->assertSame(1, $allocations['martial_dagger_flurry']['active_slot'], $weaponStyle);
        }
    }

    public function test_normal_exploration_uses_owned_growth_snapshot_rewards_history_and_cross_operation_idempotency(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser('Exploration secretary');
        $profile = UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'combat_level' => 2,
            'combat_xp' => 100,
            'shard_balance' => 101,
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'unspent_stp' => 5,
            'current_hp' => 400,
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'miracle_holy_bolt',
            'rank' => 1,
            'active_slot' => 1,
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'martial_weapon_mastery',
            'rank' => 1,
            'active_slot' => null,
        ]);
        $profile->update(['skill_points_unspent' => 13]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.next_level_requirement', 150)
            ->assertJsonPath('data.xp_to_next_level', 150)
            ->assertJsonPath('data.unspent_stp', 5)
            ->assertJsonPath('data.current_hp', 400)
            ->assertJsonPath('data.current_stats.vitality', 19)
            ->assertJsonPath('data.current_stats.might', 36)
            ->assertJsonPath('data.combat_stats.vitality', 20)
            ->assertJsonPath('data.combat_stats.might', 37)
            ->assertJsonPath('data.equipment.key', 'starter_knife')
            ->assertJsonPath('data.equipment.label', '護身用ナイフ')
            ->assertJsonPath('data.equipment_summary.used', 1)
            ->assertJsonPath('data.equipment_summary.capacity', 500)
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'starter_knife')
            ->assertJsonMissingPath('data.equipment_summary.items');

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'enemy_key' => 'client_selected_enemy',
            'private_seed' => 1,
            'combat_level' => 100,
            'weapon_power' => PHP_INT_MAX,
        ];
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/explore', $payload)
            ->assertOk()
            ->assertJsonPath('data.context', 'exploration')
            ->assertJsonPath('data.player_display_name', 'Exploration secretary')
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.hunting_ground.key', 'shallow_caves')
            ->assertJsonPath('data.hunting_ground.name', '浅い洞窟')
            ->assertJsonMissingPath('data.private_seed')
            ->assertJsonMissingPath('data.snapshot');
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', $payload)
            ->assertOk()->assertExactJson($first->json());
        $battle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_EXPLORATION)
            ->sole();
        $encounter = app(UndergroundAlphaV1PlayerCatalog::class)
            ->explorationEncounter($battle->encounter_key);
        $expectedXp = match ($battle->result) {
            UndergroundBattle::RESULT_VICTORY => $encounter['xp'],
            UndergroundBattle::RESULT_WITHDRAWAL => intdiv($encounter['xp'], 4),
            default => 0,
        };
        $expectedShardDelta = match ($battle->result) {
            UndergroundBattle::RESULT_VICTORY => $encounter['shards'],
            UndergroundBattle::RESULT_DEFEAT => -51,
            default => 0,
        };
        $this->assertSame([$expectedXp, $expectedShardDelta], [
            $battle->xp_awarded,
            $battle->shard_delta,
        ]);
        $this->assertEquals(
            ['vitality' => 19, 'might' => 36, 'finesse' => 31, 'spirit' => 9, 'agility' => 10],
            $battle->snapshot['progression_stats'],
        );
        $this->assertEquals(
            ['vitality' => 20, 'might' => 37, 'finesse' => 32, 'spirit' => 10, 'agility' => 11],
            $battle->snapshot['combat_stats'],
        );
        $this->assertSame('starter_knife', $battle->snapshot['equipment']['key']);
        $this->assertSame('secretary-underground-shop-equipment-alpha-v2', $battle->snapshot['equipment']['catalog_identity']);
        $this->assertSame(['starter_knife'], array_column($battle->snapshot['equipment']['items'], 'key'));
        $this->assertSame(400, $battle->snapshot['current_hp_before']);
        $this->assertSame(10_000, $battle->snapshot['battle_start_mp']);
        $this->assertSame('secretary-underground-skill-tree-alpha-v1', $battle->snapshot['skill_tree_identity']);
        $this->assertSame(
            AlphaV1CombatRules::TARGETING_IDENTITY,
            $battle->snapshot['targeting_contract_identity'],
        );
        $this->assertSame([
            'miracle_holy_bolt' => 1,
            'martial_weapon_mastery' => 1,
        ], $battle->snapshot['acquired_skill_nodes']);
        $this->assertSame(['holy_bolt'], $battle->snapshot['equipped_active_skills']);
        $this->assertSame(['physical_damage_bps' => 120], $battle->snapshot['effective_passive_modifiers']);
        $this->assertSame($profile->fresh()->current_hp, $battle->snapshot['current_hp_after']);
        $this->assertArrayNotHasKey('current_mp', $profile->getAttributes());
        $this->assertSame(0, UndergroundTrialProgress::query()->count());
        $this->assertTrue($battle->log?->expires_at->equalTo($battle->finished_at->addHour()) ?? false);

        $secretary->update(['name' => 'Renamed exploration secretary']);
        $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.context', 'exploration')
            ->assertJsonPath('data.0.player_display_name', 'Exploration secretary')
            ->assertJsonPath('data.0.rounds', null);
        $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.context', 'exploration')
            ->assertJsonPath('data.player_display_name', 'Exploration secretary');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            'request_id' => $requestId,
            'build_key' => 'pure_attacker',
            'enemy_key' => 'depth_stalker',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
    }

    public function test_trial_api_exposes_named_first_challenge_and_replays_the_same_result(): void
    {
        Carbon::setTestNow('2026-08-30 13:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser('封印探索者');
        $this->openEquipmentProfile($secretary);

        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.trial.label', '地下に眠る古代遺跡')
            ->assertJsonPath('data.trial.first_cleared', false)
            ->assertJsonPath('data.trial.active_run', null);
        $run = $this->actingAs($user)->postJson('/api/v1/me/underground/trial/start')
            ->assertOk()
            ->assertJsonPath('data.label', '地下に眠る古代遺跡')
            ->assertJsonPath('data.next_battle_index', 1)
            ->json('data');
        $requestId = (string) Str::uuid();
        $payload = ['run_key' => $run['run_key'], 'request_id' => $requestId];
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/trial/fight', $payload)
            ->assertOk()
            ->assertJsonPath('data.context', 'trial')
            ->assertJsonPath('data.player_display_name', '封印探索者')
            ->assertJsonPath('data.trial_battle_index', 1)
            ->assertJsonPath('data.first_clear_story', null)
            ->assertJsonPath(
                'data.challenge_intro',
                "　崩れかけた石壁の向こうに広がっていた不思議な空間。\n"
                ."　土と岩に埋もれたそこは、明らかに人の手で造られた古い石造りの遺跡であった。\n"
                .'　入り口からは生暖かい風が吹いている……そこが魔物の巣窟であることは、明らかであった。',
            );
        $this->actingAs($user)->postJson('/api/v1/me/underground/trial/fight', $payload)
            ->assertOk()
            ->assertExactJson($first->json());
        $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.context', 'trial')
            ->assertJsonPath('data.0.challenge_intro', $first->json('data.challenge_intro'));
        $this->assertSame(1, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_TRIAL)
            ->where('request_id', $requestId)
            ->count());
    }

    public function test_awakening_projection_and_plain_text_message_setting_require_first_clear(): void
    {
        Carbon::setTestNow('2026-08-30 13:30:00+09:00');
        [$user, $secretary] = $this->secretaryUser('設定秘書');
        $profile = $this->openEquipmentProfile($secretary);

        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.awakening.unlocked', false)
            ->assertJsonPath('data.awakening.current', 0)
            ->assertJsonPath('data.awakening.technique', null);
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => 'まだ使えない',
        ])->assertConflict()->assertJsonPath('code', 'underground_awakening_locked');

        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now()->subMinute(),
            'first_cleared_at' => Carbon::now(),
        ]);
        $profile->update(['awakening_gauge' => 384]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.awakening.unlocked', true)
            ->assertJsonPath('data.awakening.current', 384)
            ->assertJsonPath('data.awakening.maximum', UndergroundAwakening::GAUGE_MAX)
            ->assertJsonPath('data.awakening.default_message', UndergroundAwakening::DEFAULT_MESSAGE)
            ->assertJsonPath('data.awakening.technique.key', 'decisive_heavenrend')
            ->assertJsonPath('data.awakening.technique.name', '天断一閃')
            ->assertJsonPath('data.awakening.technique.consumes_action', true);

        $requestId = (string) Str::uuid();
        $custom = '<script>{secretary_name}</script>が覚醒した。';
        $saved = $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => $requestId,
            'message' => $custom,
        ])->assertOk()->assertJsonPath('data.awakening.custom_message', $custom);
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => $requestId,
            'message' => $custom,
        ])->assertOk()->assertExactJson($saved->json());
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => $requestId,
            'message' => '別の意図',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->assertSame($custom, $profile->refresh()->awakening_message);
        $this->assertSame(
            '<script>設定秘書</script>が覚醒した。',
            app(UndergroundAwakening::class)->renderMessage($profile->awakening_message, $secretary->name),
        );

        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => '',
        ])->assertOk()
            ->assertJsonPath('data.awakening.custom_message', null)
            ->assertJsonPath('data.awakening.default_message', UndergroundAwakening::DEFAULT_MESSAGE);
        $this->assertNull($profile->refresh()->awakening_message);
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => "invalid\nmessage",
        ])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => str_repeat('界', 101),
        ])->assertUnprocessable()->assertJsonValidationErrors('message');
    }

    public function test_ai_configuration_uses_default_preset_and_saves_normalized_rules_idempotently(): void
    {
        [$user, $secretary] = $this->secretaryUser('AI設定秘書');
        $profile = $this->openEquipmentProfile($secretary);

        $default = $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.ai.schema_version', 1)
            ->assertJsonPath('data.ai.max_rules', 16)
            ->assertJsonPath('data.ai.max_conditions_per_rule', 2)
            ->assertJsonPath('data.ai.is_custom', false)
            ->assertJsonPath('data.ai.rules.0.conditions.0.type', 'own_hp_lte')
            ->assertJsonPath('data.ai.rules.0.conditions.0.percent', 20)
            ->assertJsonPath('data.ai.rules.0.action', 'awakening')
            ->assertJsonPath('data.ai.rules.2.action', 'defend')
            ->assertJsonPath('data.ai.rules.3.action', 'normal_attack')
            ->assertJsonCount(16, 'data.ai.catalog.skills');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $default->json('data.ai.hash'));
        $this->assertSame($default->json('data.ai.default_rules'), $default->json('data.ai.rules'));

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'rules' => [
                ['conditions' => [], 'action' => 'skill:executioner_cut'],
                [
                    'conditions' => [['percent' => 50, 'type' => 'own_hp_lte']],
                    'action' => 'jump',
                    'jump_to' => 3,
                ],
                ['conditions' => [['type' => 'always']], 'action' => 'defend'],
            ],
        ];
        $saved = $this->actingAs($user)->putJson('/api/v1/me/underground/ai', $payload)
            ->assertOk()
            ->assertJsonPath('data.ai.is_custom', true)
            ->assertJsonPath('data.ai.rules.0.conditions.0.type', 'always')
            ->assertJsonPath('data.ai.rules.0.action', 'skill:executioner_cut')
            ->assertJsonPath('data.ai.rules.1.jump_to', 3);
        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', $payload)
            ->assertOk()->assertExactJson($saved->json());
        $this->assertEquals($saved->json('data.ai.rules'), $profile->refresh()->custom_ai_rules);
        $this->assertDatabaseHas('underground_intro_requests', [
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'operation' => 'ai_configuration',
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'guardianship_blue',
        ])->assertOk()
            ->assertJsonPath('data.ai.rules.0.action', 'skill:executioner_cut');
        $this->assertEquals($saved->json('data.ai.rules'), $profile->refresh()->custom_ai_rules);

        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
            ...$payload,
            'rules' => [],
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
            'request_id' => (string) Str::uuid(),
            'rules' => null,
        ])->assertOk()
            ->assertJsonPath('data.ai.is_custom', false)
            ->assertJsonPath('data.ai.rules.0.action', 'awakening');
        $this->assertNull($profile->refresh()->custom_ai_rules);
    }

    public function test_ai_configuration_rejects_invalid_rules_without_mutation(): void
    {
        [$user, $secretary] = $this->secretaryUser('AI検証秘書');
        $profile = $this->openEquipmentProfile($secretary);

        foreach ([
            [[
                'conditions' => [],
                'action' => 'jump',
                'jump_to' => 1,
            ]],
            [[
                'conditions' => [['type' => 'skill_ready', 'skill' => 'enemy_telegraph']],
                'action' => 'defend',
            ]],
        ] as $rules) {
            $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
                'request_id' => (string) Str::uuid(),
                'rules' => $rules,
            ])->assertConflict()->assertJsonPath('code', 'underground_ai_rules_invalid');
        }
        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
            'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('rules');

        $this->assertNull($profile->refresh()->custom_ai_rules);
        $this->assertSame(0, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'ai_configuration')
            ->count());
    }

    public function test_refresh_resumes_meaningful_stage_and_intro_history_is_private_and_owner_scoped(): void
    {
        [$owner, $ownerSecretary] = $this->secretaryUser('History secretary');
        [$other] = $this->secretaryUser('Other history secretary');
        $this->actingAs($owner)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($owner, 'initial_story_complete');
        $tutorialId = (string) Str::uuid();
        $this->actingAs($owner)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $tutorialId,
        ])->assertOk();

        $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()->assertJsonPath('data.stage', 'escape_pending');
        $this->actingAs($owner)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tutorialId)
            ->assertJsonMissingPath('data.0.snapshot')
            ->assertJsonMissingPath('data.0.private_seed');
        $this->actingAs($owner)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertOk()
            ->assertJsonPath('data.encounter_name', 'ジャイアントラット')
            ->assertJsonStructure(['data' => ['actions']])
            ->assertJsonMissingPath('data.snapshot');
        $battle = UndergroundBattle::query()->where('request_id', $tutorialId)->sole();
        $battle->log()->update(['expires_at' => Carbon::now()->subSecond()]);
        $this->artisan('underground:prune-battle-logs')->assertSuccessful();
        $this->actingAs($owner)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tutorialId)
            ->assertJsonPath('data.0.detail_available', false)
            ->assertJsonPath('data.0.actions', null);
        $this->actingAs($owner)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertOk()
            ->assertJsonPath('data.detail_available', false)
            ->assertJsonPath('data.detail_message', '詳細ログは保存期間を過ぎました。')
            ->assertJsonPath('data.actions', null);
        $this->actingAs($other)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertNotFound();
        $ownerSecretary->delete();
        $this->assertSame(0, UndergroundProfile::query()->count());
        $this->assertSame(0, UndergroundBattle::query()->count());
        $this->assertSame(0, UndergroundIntroRequest::query()->count());
    }

    /** @return array{User, Secretary} */
    private function secretaryUser(string $name): array
    {
        $user = User::factory()->create();
        $secretary = app(SecretaryService::class)->ensureForUser($user);
        $secretary->update(['name' => $name, 'named_at' => Carbon::now()]);

        return [$user, $secretary];
    }

    private function reachShopkeeperNaming(User $user): void
    {
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'initial_story_complete')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'escape_complete')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'shopkeeper_encounter_complete')
            ->assertOk()->assertJsonPath('data.stage', 'shopkeeper_naming');
    }

    private function advance(User $user, string $action): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/me/underground/story/advance', [
            'request_id' => (string) Str::uuid(),
            'action' => $action,
        ]);
    }

    private function openEquipmentProfile(
        Secretary $secretary,
        int $shardBalance = 5_000,
        int $bankedShardBalance = 5_000,
    ): UndergroundProfile {
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update([
            'shard_balance' => $shardBalance,
            'banked_shard_balance' => $bankedShardBalance,
            'underground_contract_completed_at' => Carbon::now(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'current_hp' => 492,
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);

        return $profile->refresh();
    }

    private function tutorialBattle(UndergroundProfile $profile): UndergroundBattle
    {
        return UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'secretary-underground-intro-alpha-v2',
            'activity_type' => UndergroundBattle::ACTIVITY_TUTORIAL,
            'activity_key' => 'first_descent_tutorial',
            'encounter_key' => 'tutorial_giant_rat',
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'xp_awarded' => 5,
            'shard_delta' => 0,
            'combat_level_before' => 1,
            'combat_level_after' => 1,
            'combat_xp_before' => 0,
            'combat_xp_after' => 5,
            'shard_balance_before' => 0,
            'shard_balance_after' => 0,
            'private_seed' => 1,
            'snapshot' => [],
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);
    }
}
