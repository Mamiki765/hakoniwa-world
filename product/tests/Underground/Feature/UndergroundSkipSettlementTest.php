<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Application\Underground\UndergroundRuntimeService;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundContentClearProgress;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UndergroundSkipSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_hunting_ground_skip_consumes_once_retries_idempotently_and_only_increments_total(): void
    {
        config(['underground-alpha-v1.growth_paths.martial_red.unspent_stp_per_level' => 6]);
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id, 'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves', 'actual_clear_count' => 50, 'total_clear_count' => 50,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 1]);
        $request = (string) Str::uuid();
        $runtime = app(UndergroundRuntimeService::class);
        $result = $runtime->skipHuntingGround($user, $request, 'shallow_caves');
        $retry = $runtime->skipHuntingGround($user, $request, 'shallow_caves');
        $progress = UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)->firstOrFail();
        $this->assertSame(50, $progress->actual_clear_count);
        $this->assertSame(51, $progress->total_clear_count);
        $this->assertSame(0, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, $result['settlement']->ticket_cost);
        $this->assertSame(1, $result['daily_quest']['progress']);
        $this->assertFalse($result['daily_quest']['completed_now']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame([2, 6], [$profile->fresh()->combat_level, $profile->fresh()->unspent_stp]);
        $this->assertDatabaseCount('underground_skip_settlements', 1);
        $this->assertDatabaseCount('user_skip_ticket_ledger', 1);
    }

    public function test_hunting_ground_unlock_is_content_specific_and_failed_skip_is_atomic(): void
    {
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves',
            'actual_clear_count' => 49,
            'total_clear_count' => 49,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 3]);

        try {
            app(UndergroundRuntimeService::class)->skipHuntingGround(
                $user,
                (string) Str::uuid(),
                'shallow_caves',
            );
            $this->fail('49 actual victories must not unlock a Hunting Ground skip.');
        } catch (UndergroundRuntimeException $exception) {
            $this->assertSame('underground_skip_locked', $exception->errorCode);
        }

        $this->assertSame(3, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(49, $profile->refresh()->combat_xp);
        $this->assertDatabaseCount('underground_skip_settlements', 0);
        $this->assertDatabaseCount('user_skip_ticket_ledger', 0);
    }

    public function test_trial_skip_consumes_ten_tickets_settles_one_full_repeatable_run_and_creates_no_combat_state(): void
    {
        [$user, $profile] = $this->readyProfile();
        $trialProgress = UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now()->subDay(),
            'first_cleared_at' => Carbon::now()->subDay(),
        ]);
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'trial',
            'content_key' => 'trial_01',
            'actual_clear_count' => 5,
            'total_clear_count' => 5,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 10]);
        $profile->update(['next_battle_at' => Carbon::now()->addHour()]);
        $before = $profile->fresh();
        $battleCount = UndergroundBattle::query()->count();

        $result = app(UndergroundRuntimeService::class)->skipTrial(
            $user,
            (string) Str::uuid(),
            'trial_01',
        );
        $after = $profile->refresh();
        $progress = UndergroundContentClearProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('content_type', 'trial')
            ->where('content_key', 'trial_01')
            ->sole();

        $this->assertSame(10, $result['settlement']->ticket_cost);
        $this->assertSame(5, $progress->actual_clear_count);
        $this->assertSame(6, $progress->total_clear_count);
        $this->assertSame(0, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame($result['settlement']->xp_awarded, $after->combat_xp - $before->combat_xp);
        $this->assertSame($result['settlement']->shard_awarded, $after->shard_balance - $before->shard_balance);
        $this->assertEquals($before->next_battle_at, $after->next_battle_at);
        $this->assertSame($battleCount, UndergroundBattle::query()->count());
        $this->assertDatabaseCount('underground_trial_runs', 0);
        $this->assertDatabaseCount('underground_parties', 0);
        $this->assertDatabaseCount('secretary_lending_participations', 0);
        $this->assertEquals($trialProgress->first_cleared_at, $trialProgress->refresh()->first_cleared_at);
        $this->assertCount(10, $result['settlement']->reward_snapshot['encounters']);
        $this->assertSame([], $result['settlement']->reward_snapshot['drops']);
        $this->assertSame(10, $result['daily_quest']['progress']);
        $this->assertTrue($result['daily_quest']['completed_now']);
        $this->assertSame(5, $result['daily_quest']['paradox_balance']);
    }

    public function test_single_trial_skip_settles_an_authored_eleventh_drop_reward(): void
    {
        $trial = config('underground-runtime.trials.trial_02');
        $trial['content_identity'] = 'test-trial-with-eleven-rewards';
        $trial['encounters'][] = $trial['encounters'][9];
        $trial['rewards'][] = $trial['rewards'][9];
        config(['underground-runtime.trials.trial_02' => $trial]);

        [$user, $profile] = $this->readyProfile();
        $clearedAt = Carbon::now()->subDay();
        foreach (['trial_01', 'trial_02'] as $trialKey) {
            UndergroundTrialProgress::query()->create([
                'underground_profile_id' => $profile->id,
                'trial_key' => $trialKey,
                'unlocked_at' => $clearedAt,
                'first_cleared_at' => $clearedAt,
            ]);
        }
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'trial',
            'content_key' => 'trial_02',
            'actual_clear_count' => 5,
            'total_clear_count' => 5,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 10]);

        $result = app(UndergroundRuntimeService::class)->skipTrial(
            $user,
            (string) Str::uuid(),
            'trial_02',
        );

        $this->assertCount(11, $result['settlement']->reward_snapshot['encounters']);
        $this->assertCount(11, $result['settlement']->reward_snapshot['drops']);
    }

    public function test_trial_skip_remains_locked_at_four_actual_clears_without_consuming_or_rewarding(): void
    {
        [$user, $profile] = $this->readyProfile();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now()->subDay(),
            'first_cleared_at' => Carbon::now()->subDay(),
        ]);
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'trial',
            'content_key' => 'trial_01',
            'actual_clear_count' => 4,
            'total_clear_count' => 4,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 10]);
        $before = $profile->fresh()->only(['combat_level', 'combat_xp', 'shard_balance', 'unspent_stp']);

        try {
            app(UndergroundRuntimeService::class)->skipTrial($user, (string) Str::uuid(), 'trial_01');
            $this->fail('Four actual Trial clears must not unlock a full-run skip.');
        } catch (UndergroundRuntimeException $exception) {
            $this->assertSame('underground_skip_locked', $exception->errorCode);
        }

        $this->assertSame(10, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame($before, $profile->refresh()->only(array_keys($before)));
        $this->assertDatabaseCount('underground_skip_settlements', 0);
    }

    public function test_bulk_hunting_skip_is_one_idempotent_operation_with_distinct_drop_identity(): void
    {
        $this->forceShallowStandardDrop();
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves',
            'actual_clear_count' => 50,
            'total_clear_count' => 50,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 100]);
        $requestId = (string) Str::uuid();
        $runtime = app(UndergroundRuntimeService::class);

        $result = $runtime->bulkSkipHuntingGround($user, $requestId, 'shallow_caves', 50);
        $afterFirst = $profile->refresh()->only(['combat_level', 'combat_xp', 'shard_balance', 'unspent_stp']);
        $retry = $runtime->bulkSkipHuntingGround($user, $requestId, 'shallow_caves', 50);
        $batch = $result['batch']->refresh();

        $this->assertFalse($result['duplicate']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame($batch->id, $retry['batch']->id);
        $this->assertSame(50, $batch->execution_count);
        $this->assertSame(50, $batch->ticket_cost);
        $this->assertSame(50, $batch->reward_snapshot['equipment_granted_count']);
        $this->assertCount(50, $batch->reward_snapshot['encounters']);
        $this->assertSame(0, $batch->reward_snapshot['vault_full_count']);
        $this->assertSame(50, $batch->reward_snapshot['ticket_balance_after']);
        $this->assertSame($afterFirst, $profile->refresh()->only(array_keys($afterFirst)));
        $this->assertSame(50, UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)->value('actual_clear_count'));
        $this->assertSame(100, UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)->value('total_clear_count'));
        $this->assertSame(50, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertDatabaseCount('underground_skip_batches', 1);
        $this->assertDatabaseCount('underground_skip_settlements', 0);
        $this->assertDatabaseCount('user_skip_ticket_ledger', 1);
        $this->assertSame(50, UndergroundOwnedEquipment::query()->where('source_skip_batch_id', $batch->id)->count());
        $this->assertSame(50, UndergroundOwnedEquipment::query()->where('source_skip_batch_id', $batch->id)->distinct('source_reward_index')->count('source_reward_index'));
        $this->assertSame(10, $result['daily_quest']['progress']);
        $this->assertTrue($result['daily_quest']['completed_now']);
        $this->assertFalse($retry['daily_quest']['completed_now']);
        $this->assertDatabaseCount('user_daily_quest_activities', 1);

        try {
            $runtime->bulkSkipHuntingGround($user, $requestId, 'shallow_caves', 51);
            $this->fail('Changing the execution count must conflict with the established request identity.');
        } catch (UndergroundRuntimeException $exception) {
            $this->assertSame('underground_request_conflict', $exception->errorCode);
        }
    }

    public function test_bulk_skip_rejects_insufficient_balance_without_partial_rewards(): void
    {
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves',
            'actual_clear_count' => 50,
            'total_clear_count' => 50,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 5]);
        $before = $profile->only(['combat_level', 'combat_xp', 'shard_balance', 'unspent_stp']);

        try {
            app(UndergroundRuntimeService::class)->bulkSkipHuntingGround(
                $user,
                (string) Str::uuid(),
                'shallow_caves',
                6,
            );
            $this->fail('An insufficient bulk request must be rejected atomically.');
        } catch (UndergroundRuntimeException $exception) {
            $this->assertSame('underground_skip_ticket_insufficient', $exception->errorCode);
        }

        $this->assertSame($before, $profile->refresh()->only(array_keys($before)));
        $this->assertSame(5, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertDatabaseCount('underground_skip_batches', 0);
        $this->assertDatabaseCount('user_skip_ticket_ledger', 0);
    }

    public function test_vault_bulk_skip_requires_every_key_and_replays_without_double_consumption(): void
    {
        [$user, $profile] = $this->readyProfile();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_02',
            'unlocked_at' => Carbon::now()->subDay(),
            'first_cleared_at' => Carbon::now()->subDay(),
        ]);
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'hunting_ground',
            'content_key' => 'shining_kingdom_vault',
            'actual_clear_count' => 50,
            'total_clear_count' => 50,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 3]);
        $profile->update(['shining_kingdom_key_balance' => 2]);
        $runtime = app(UndergroundRuntimeService::class);

        try {
            $runtime->bulkSkipHuntingGround($user, (string) Str::uuid(), 'shining_kingdom_vault', 3);
            $this->fail('A vault bulk skip must reserve every entry key atomically.');
        } catch (UndergroundRuntimeException $exception) {
            $this->assertSame('underground_shining_kingdom_key_insufficient', $exception->errorCode);
        }
        $this->assertSame(2, $profile->refresh()->shining_kingdom_key_balance);
        $this->assertSame(3, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertDatabaseCount('underground_skip_batches', 0);

        $profile->update(['shining_kingdom_key_balance' => 3]);
        $requestId = (string) Str::uuid();
        $first = $runtime->bulkSkipHuntingGround($user, $requestId, 'shining_kingdom_vault', 3);
        $retry = $runtime->bulkSkipHuntingGround($user, $requestId, 'shining_kingdom_vault', 3);
        $this->assertFalse($first['duplicate']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame(0, $profile->refresh()->shining_kingdom_key_balance);
        $this->assertSame(0, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(3, $first['batch']->reward_snapshot['equipment_granted_count']);
        $this->assertSame(3, UndergroundOwnedEquipment::query()
            ->where('source_skip_batch_id', $first['batch']->id)
            ->count());
        $this->assertDatabaseCount('underground_skip_batches', 1);
    }

    public function test_bulk_skip_http_request_returns_one_aggregate_and_replays_it_without_double_settlement(): void
    {
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves',
            'actual_clear_count' => 50,
            'total_clear_count' => 50,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 4]);
        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'hunting_ground_key' => 'shallow_caves',
            'execution_count' => 2,
        ];

        $this->actingAs($user)->postJson('/api/v1/me/underground/skip/hunting-ground', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.execution_count', 2)
            ->assertJsonPath('data.ticket_cost', 2)
            ->assertJsonPath('data.rewards.ticket_balance_after', 2);
        $this->actingAs($user)->postJson('/api/v1/me/underground/skip/hunting-ground', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.execution_count', 2);

        $this->assertDatabaseCount('underground_skip_batches', 1);
        $this->assertDatabaseCount('user_skip_ticket_ledger', 1);
        $this->assertSame(2, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_bulk_skip_http_requests_reject_more_than_the_bounded_execution_limit(): void
    {
        [$user] = $this->readyProfile();
        $executionCount = UndergroundRuntimeService::MAX_BULK_SKIP_EXECUTIONS + 1;

        $this->actingAs($user)->postJson('/api/v1/me/underground/skip/hunting-ground', [
            'request_id' => (string) Str::uuid(),
            'hunting_ground_key' => 'shallow_caves',
            'execution_count' => $executionCount,
        ])->assertUnprocessable()->assertJsonValidationErrors('execution_count');

        $this->actingAs($user)->postJson('/api/v1/me/underground/skip/trial', [
            'request_id' => (string) Str::uuid(),
            'trial_key' => 'trial_01',
            'execution_count' => $executionCount,
        ])->assertUnprocessable()->assertJsonValidationErrors('execution_count');

        $this->assertDatabaseCount('underground_skip_batches', 0);
        $this->assertDatabaseCount('user_skip_ticket_ledger', 0);
    }

    public function test_bulk_trial_skip_preserves_actual_and_first_clear_contracts(): void
    {
        [$user, $profile] = $this->readyProfile();
        $firstClearedAt = Carbon::now()->subDay()->startOfSecond();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => $firstClearedAt,
            'first_cleared_at' => $firstClearedAt,
        ]);
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'trial',
            'content_key' => 'trial_01',
            'actual_clear_count' => 5,
            'total_clear_count' => 5,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 100]);
        $before = $profile->fresh();

        $result = app(UndergroundRuntimeService::class)->bulkSkipTrial(
            $user,
            (string) Str::uuid(),
            'trial_01',
            5,
        );
        $batch = $result['batch'];
        $progress = UndergroundContentClearProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('content_type', 'trial')
            ->where('content_key', 'trial_01')
            ->sole();

        $this->assertSame(5, $batch->execution_count);
        $this->assertSame(50, $batch->ticket_cost);
        $this->assertSame(5, $progress->actual_clear_count);
        $this->assertSame(10, $progress->total_clear_count);
        $this->assertSame(50, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame($batch->xp_awarded, $profile->refresh()->combat_xp - $before->combat_xp);
        $this->assertSame($before->skill_points_total, $profile->skill_points_total);
        $this->assertSame($before->skill_points_unspent, $profile->skill_points_unspent);
        $this->assertSame($firstClearedAt->toAtomString(), UndergroundTrialProgress::query()->where('underground_profile_id', $profile->id)->where('trial_key', 'trial_01')->value('first_cleared_at')->toAtomString());
        $this->assertDatabaseCount('underground_battles', 1);
        $this->assertDatabaseCount('underground_trial_runs', 0);
        $this->assertDatabaseCount('secretary_lending_participations', 0);
    }

    public function test_bulk_skip_aggregates_vault_full_drops_without_creating_items(): void
    {
        $this->forceShallowStandardDrop();
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves',
            'actual_clear_count' => 50,
            'total_clear_count' => 50,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 2]);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile);
        $now = Carbon::now();
        $rows = [];
        $used = UndergroundOwnedEquipment::query()->where('underground_profile_id', $profile->id)->count();
        for ($index = $used; $index < 500; $index++) {
            $rows[] = [
                'underground_profile_id' => $profile->id,
                'definition_key' => 'bronze_rapier',
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
                'equipped_slot' => null,
                'grant_key' => null,
                'instance_kind' => 'fixed',
                'acquired_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('underground_owned_equipment')->insert($chunk);
        }

        $batch = app(UndergroundRuntimeService::class)->bulkSkipHuntingGround(
            $user,
            (string) Str::uuid(),
            'shallow_caves',
            2,
        )['batch'];

        $this->assertSame(0, $batch->reward_snapshot['equipment_granted_count']);
        $this->assertSame(2, $batch->reward_snapshot['vault_full_count']);
        $this->assertSame([], $batch->reward_snapshot['drops']);
        $this->assertSame(500, UndergroundOwnedEquipment::query()->where('underground_profile_id', $profile->id)->count());
    }

    /** @return array{User, UndergroundProfile} */
    private function readyProfile(): array
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id, 'name' => 'Skip tester', 'named_at' => Carbon::now()]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['underground_contract_completed_at' => Carbon::now()->subMinute(), 'growth_path_key' => 'martial_red', 'growth_path_identity' => 'secretary-underground-growth-alpha-v1', 'growth_path_selected_at' => Carbon::now(), 'skill_points_total' => 20, 'skill_points_unspent' => 20, 'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v2', 'unspent_stp' => 0]);
        $tutorial = UndergroundBattle::query()->create(['underground_profile_id' => $profile->id, 'request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'runtime_identity' => 'test', 'activity_type' => 'tutorial', 'activity_key' => 'tutorial', 'encounter_key' => 'giant_rat', 'result' => 'victory', 'rounds' => 1, 'damage_dealt' => 1, 'damage_received' => 0, 'healing_done' => 0, 'combat_level_before' => 1, 'combat_level_after' => 1, 'combat_xp_before' => 0, 'combat_xp_after' => 0, 'shard_balance_before' => 0, 'shard_balance_after' => 0, 'private_seed' => 1, 'snapshot' => [], 'started_at' => Carbon::now()->subHour(), 'finished_at' => Carbon::now()->subHour()]);
        UndergroundIntroProgress::query()->create(['underground_profile_id' => $profile->id, 'stage' => 'underground_open', 'shopkeeper_name' => '案内係', 'special_loss_required' => false, 'branch_identity' => 'normal', 'tutorial_battle_id' => $tutorial->id]);
        $profile->update(['combat_xp' => 49]);

        return [$user, $profile->refresh()];
    }

    private function forceShallowStandardDrop(): void
    {
        $encounter = config('underground-alpha-v1.exploration.grounds.shallow_caves.encounters.subterranean_rat');
        if (! is_array($encounter)) {
            throw new \RuntimeException('Shallow drop test encounter is missing.');
        }
        $encounter['weight'] = 10_000;
        config([
            'underground-alpha-v1.exploration.grounds.shallow_caves.encounters' => [
                'subterranean_rat' => $encounter,
            ],
            'underground-alpha-v1.exploration.drop.profiles.standard.presence_bps' => 10_000,
            'underground-alpha-v1.exploration.drop.profiles.standard.rarity_weights' => [
                'common' => 10_000,
                'uncommon' => 0,
                'rare' => 0,
                'epic' => 0,
            ],
        ]);
    }
}
