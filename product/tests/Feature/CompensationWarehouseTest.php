<?php

namespace Tests\Feature;

use App\Application\CompensationWarehouseService;
use App\Application\NationCreationService;
use App\Application\ParadoxBalanceService;
use App\Application\Underground\UndergroundProfileService;
use App\Domain\Economy\NationCapacityResolver;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\Secretary;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class CompensationWarehouseTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_islandless_user_receives_personal_assets_then_surface_assets_and_expired_remainders_stay_in_history(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $profile = app(UndergroundProfileService::class)->ensureForSecretary(Secretary::query()->create(['user_id' => $user->id]));
        $profile->update(['shard_balance' => 0]);
        $warehouse = app(CompensationWarehouseService::class);
        $grant = $warehouse->createForUser($world, $user, 'islandless-personal-and-surface', 'operator', '全員への配布', [
            'paradox' => 2, 'skip_ticket' => 3, 'underground_g' => 4, 'money' => 5,
        ])['grant'];
        $endpoint = "/api/v1/me/compensation-grants/{$grant->id}/claim";
        $request = ['request_id' => (string) Str::uuid()];
        $this->actingAs($user)->postJson($endpoint, $request)->assertOk()->assertJsonPath('data.grant.status', 'partial');
        $this->postJson($endpoint, $request)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(4, $profile->fresh()->shard_balance);
        $this->assertDatabaseHas('user_skip_ticket_balances', ['user_id' => $user->id, 'balance' => 3]);
        $this->assertDatabaseHas('compensation_grant_items', ['compensation_grant_id' => $grant->id, 'asset_key' => 'money', 'claimed_amount' => 0]);

        $nation = app(NationCreationService::class)->create($user, $world, 'あとから作った島', '島主');
        $before = (int) $nation->money;
        $this->postJson($endpoint, ['request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.grant.status', 'claimed');
        $this->assertSame($before + 5, (int) $nation->fresh()->money);
        $this->assertSame(4, $profile->fresh()->shard_balance);

        $expired = $warehouse->createForUser($world, $user, 'expired-remainder', 'operator', '期限のある配布', ['money' => 9])['grant'];
        $this->travelTo($expired->expires_at);
        try {
            $this->postJson("/api/v1/me/compensation-grants/{$expired->id}/claim", ['request_id' => (string) Str::uuid()])
                ->assertOk()->assertJsonPath('data.grant.status', 'expired')->assertJsonCount(0, 'data.applied_now');
            $this->getJson('/api/v1/me/compensation-grants?history=1')->assertOk()
                ->assertJsonPath('data.0.status', 'expired')->assertJsonPath('data.0.items.0.remaining_amount', 9);
            $this->assertSame($before + 5, (int) $nation->fresh()->money);
            $this->assertDatabaseHas('compensation_grants', ['id' => $expired->id, 'status' => 'expired']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_owner_claims_all_supported_assets_once_through_the_capacity_aware_warehouse(): void
    {
        [$user, $nation] = $this->registeredNation('配布受取島');
        $nation->update(['money' => 0]);
        NationResource::query()->where('nation_id', $nation->id)->update(['amount' => 0]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary(
            Secretary::query()->where('user_id', $user->id)->sole(),
        );
        $profile->update(['shard_balance' => 0]);

        $created = app(CompensationWarehouseService::class)->createGrant(
            $nation,
            'incident-2026-09-09-owner-1',
            'test-operator',
            '今回のお詫びです。',
            [
                'money' => 123,
                'wheat' => 11,
                'fish' => 12,
                'meat' => 13,
                'oil' => 14,
                'paradox' => 15,
                'skip_ticket' => 1_000,
                'underground_g' => 16,
            ],
        );
        $grant = $created['grant'];
        $this->assertFalse($created['duplicate']);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/nations/{$nation->id}/compensation-grants")
            ->assertForbidden();

        $pending = $this->actingAs($user)
            ->getJson("/api/v1/nations/{$nation->id}/compensation-grants")
            ->assertOk()
            ->assertJsonPath('data.0.id', $grant->id)
            ->assertJsonPath('data.0.reason', '今回のお詫びです。')
            ->assertJsonCount(8, 'data.0.items')
            ->json('data.0');
        $this->assertSame(1_000, collect($pending['items'])->firstWhere('asset_key', 'skip_ticket')['remaining_amount']);

        $requestId = (string) Str::uuid();
        $result = $this->postJson(
            "/api/v1/nations/{$nation->id}/compensation-grants/{$grant->id}/claim",
            ['request_id' => $requestId],
        )->assertOk()
            ->assertJsonPath('data.grant.status', 'claimed')
            ->assertJsonPath('data.already_claimed', false)
            ->assertJsonPath('data.duplicate', false)
            ->json('data');
        $this->assertSame([
            'fish' => 12,
            'meat' => 13,
            'money' => 123,
            'oil' => 14,
            'paradox' => 15,
            'skip_ticket' => 1_000,
            'underground_g' => 16,
            'wheat' => 11,
        ], collect($result['applied_now'])->pluck('applied', 'asset_key')->all());

        $this->postJson(
            "/api/v1/nations/{$nation->id}/compensation-grants/{$grant->id}/claim",
            ['request_id' => $requestId],
        )->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame(123, (int) $nation->fresh()->money);
        $this->assertSame([
            'fish' => 12,
            'monster_meat' => 13,
            'oil' => 14,
            'wheat' => 11,
        ], NationResource::query()->where('nation_id', $nation->id)
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resources.resource_definition_id')
            ->whereIn('resource_definitions.key', ['wheat', 'fish', 'monster_meat', 'oil'])
            ->orderBy('resource_definitions.key')
            ->pluck('nation_resources.amount', 'resource_definitions.key')
            ->map(static fn (mixed $amount): int => (int) $amount)->all());
        $this->assertSame(15, app(ParadoxBalanceService::class)->balanceFor($user->id));
        $this->assertSame(1_000, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(16, (int) $profile->fresh()->shard_balance);
        $this->assertDatabaseCount('compensation_grant_claims', 1);
        $this->assertDatabaseHas('user_paradox_ledger', ['source_kind' => 'compensation', 'delta' => 15]);
        $this->assertDatabaseHas('user_skip_ticket_ledger', ['delta' => 1_000]);
        $this->getJson("/api/v1/nations/{$nation->id}/compensation-grants")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_capacity_overflow_stays_pending_and_operator_command_is_confirmed_and_idempotent(): void
    {
        [$user, $nation] = $this->registeredNation('配布操作島');
        $ruleset = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail();
        $moneyCapacity = app(NationCapacityResolver::class)
            ->resolve($nation, $ruleset)->money;
        $nation->update(['money' => $moneyCapacity - 2]);

        $key = 'incident-2026-09-09-owner-2';
        $token = "GRANT:{$nation->world()->value('key')}:N{$nation->id}:{$key}";
        $arguments = [
            '--world' => $nation->world()->value('key'),
            '--nation' => $nation->name,
            '--key' => $key,
            '--operator' => 'test-operator',
            '--reason' => '容量確認配布',
            '--money' => '10',
            '--g' => '7',
        ];
        $this->artisan('hakoniwa:compensation-grant', $arguments)
            ->expectsOutputToContain("Re-run with --confirm={$token}")
            ->assertFailed();
        $this->artisan('hakoniwa:compensation-grant', [...$arguments, '--confirm' => $token])
            ->expectsOutputToContain('status=created')
            ->assertSuccessful();
        $this->artisan('hakoniwa:compensation-grant', [...$arguments, '--confirm' => $token])
            ->expectsOutputToContain('status=already_exists')
            ->assertSuccessful();

        $grantId = (int) DB::table('compensation_grants')->where('grant_key', $key)->value('id');
        $this->actingAs($user)->postJson(
            "/api/v1/nations/{$nation->id}/compensation-grants/{$grantId}/claim",
            ['request_id' => (string) Str::uuid()],
        )->assertOk()
            ->assertJsonPath('data.grant.status', 'partial')
            ->assertJsonPath('data.applied_now.0.applied', 2)
            ->assertJsonPath('data.applied_now.0.remaining', 8)
            ->assertJsonPath('data.applied_now.1.asset_key', 'underground_g')
            ->assertJsonPath('data.applied_now.1.applied', 0)
            ->assertJsonPath('data.applied_now.1.remaining', 7);
        $this->assertSame($moneyCapacity, (int) $nation->fresh()->money);
        $this->assertDatabaseHas('compensation_grant_items', [
            'compensation_grant_id' => $grantId,
            'amount' => 10,
            'claimed_amount' => 2,
        ]);
    }

    public function test_claim_is_rejected_while_the_next_production_turn_is_unresolved_and_the_same_request_can_retry(): void
    {
        [$user, $nation] = $this->registeredNation('配布Turn待機島');
        $nation->update(['money' => 0]);
        $grant = app(CompensationWarehouseService::class)->createGrant(
            $nation,
            'incident-2026-09-09-turn-guard',
            'test-operator',
            'Turn境界確認配布',
            ['money' => 25],
        )['grant'];
        $world = $nation->world()->firstOrFail();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('c', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_PENDING,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $requestId = (string) Str::uuid();

        $this->actingAs($user)->postJson(
            "/api/v1/nations/{$nation->id}/compensation-grants/{$grant->id}/claim",
            ['request_id' => $requestId],
        )->assertConflict()->assertJsonPath('code', 'compensation_claim_failed');

        $this->assertSame(0, (int) $nation->fresh()->money);
        $this->assertSame('pending', $grant->fresh()->status);
        $this->assertDatabaseHas('compensation_grant_items', [
            'compensation_grant_id' => $grant->id,
            'claimed_amount' => 0,
        ]);
        $this->assertDatabaseCount('compensation_grant_claims', 0);

        $run->update(['status' => TurnRun::STATUS_COMPLETED, 'completed_at' => now()]);
        $this->actingAs($user)->postJson(
            "/api/v1/nations/{$nation->id}/compensation-grants/{$grant->id}/claim",
            ['request_id' => $requestId],
        )->assertOk()
            ->assertJsonPath('data.grant.status', 'claimed')
            ->assertJsonPath('data.duplicate', false);
        $this->assertSame(25, (int) $nation->fresh()->money);
        $this->assertDatabaseCount('compensation_grant_claims', 1);
    }

    /** @return array{User, Nation} */
    private function registeredNation(string $name): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $data = $this->actingAs($user)->postJson('/api/v1/nations', [
            'request_key' => (string) Str::uuid(),
            'world_id' => $world->id,
            'name' => $name,
            'owner_name' => '配布島主',
            'comment' => '',
        ])->assertCreated()->json('data');

        return [$user, Nation::query()->findOrFail($data['id'])];
    }
}
