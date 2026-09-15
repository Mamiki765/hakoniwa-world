<?php

namespace Tests\Underground\Feature;

use App\Application\NationCreationService;
use App\Application\Underground\UndergroundProfileService;
use App\Domain\Underground\Area\UndergroundAreaCapacity;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\NationCapital;
use App\Models\NationCommandQueueItem;
use App\Models\NationUndergroundFacility;
use App\Models\Secretary;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundAccessAndProjectionTest extends UndergroundPlayerAccessTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_player_routes_require_session_and_resolve_only_the_current_users_secretary(): void
    {
        $this->getJson('/api/v1/me/underground')->assertUnauthorized();
        $this->getJson('/api/v1/me/underground/surface-map')->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/entry', ['request_id' => (string) Str::uuid()])
            ->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/recollections/read', [
            'request_id' => (string) Str::uuid(),
            'chapter' => 1,
        ])->assertUnauthorized();
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
        $this->assertSame([true], array_values(array_unique(array_column($emptyCommands, 'consumes_turn'))));
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
}
