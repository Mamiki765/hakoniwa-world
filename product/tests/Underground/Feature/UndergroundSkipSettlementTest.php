<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Application\Underground\UndergroundRuntimeService;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundContentClearProgress;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UndergroundSkipSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_hunting_ground_skip_consumes_one_ticket_and_only_increments_total(): void
    {
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id, 'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves', 'actual_clear_count' => 50, 'total_clear_count' => 50,
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 1]);
        $result = app(UndergroundRuntimeService::class)->skipHuntingGround($user, (string) Str::uuid(), 'shallow_caves');
        $progress = UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)->firstOrFail();
        $this->assertSame(50, $progress->actual_clear_count);
        $this->assertSame(51, $progress->total_clear_count);
        $this->assertSame(0, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, $result['settlement']->ticket_cost);
    }

    public function test_skip_retry_is_idempotent_and_does_not_count_as_actual_clear(): void
    {
        [$user, $profile] = $this->readyProfile();
        UndergroundContentClearProgress::query()->create(['underground_profile_id' => $profile->id, 'content_type' => 'hunting_ground', 'content_key' => 'shallow_caves', 'actual_clear_count' => 50, 'total_clear_count' => 50]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 1]);
        $request = (string) Str::uuid();
        $runtime = app(UndergroundRuntimeService::class);
        $runtime->skipHuntingGround($user, $request, 'shallow_caves');
        $retry = $runtime->skipHuntingGround($user, $request, 'shallow_caves');
        $this->assertTrue($retry['duplicate']);
        $this->assertSame(50, UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)->value('actual_clear_count'));
        $this->assertSame(51, UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)->value('total_clear_count'));
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

    /** @return array{User, UndergroundProfile} */
    private function readyProfile(): array
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id, 'name' => 'Skip tester', 'named_at' => Carbon::now()]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['underground_contract_completed_at' => Carbon::now()->subMinute(), 'growth_path_key' => 'martial_red', 'growth_path_identity' => 'secretary-underground-growth-alpha-v1', 'growth_path_selected_at' => Carbon::now(), 'skill_points_total' => 20, 'skill_points_unspent' => 20, 'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1', 'unspent_stp' => 0]);
        $tutorial = UndergroundBattle::query()->create(['underground_profile_id' => $profile->id, 'request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'runtime_identity' => 'test', 'activity_type' => 'tutorial', 'activity_key' => 'tutorial', 'encounter_key' => 'giant_rat', 'result' => 'victory', 'rounds' => 1, 'damage_dealt' => 1, 'damage_received' => 0, 'healing_done' => 0, 'combat_level_before' => 1, 'combat_level_after' => 1, 'combat_xp_before' => 0, 'combat_xp_after' => 0, 'shard_balance_before' => 0, 'shard_balance_after' => 0, 'private_seed' => 1, 'snapshot' => [], 'started_at' => Carbon::now()->subHour(), 'finished_at' => Carbon::now()->subHour()]);
        UndergroundIntroProgress::query()->create(['underground_profile_id' => $profile->id, 'stage' => 'underground_open', 'shopkeeper_name' => '案内係', 'special_loss_required' => false, 'branch_identity' => 'normal', 'tutorial_battle_id' => $tutorial->id]);
        $profile->update(['combat_xp' => 49]);

        return [$user, $profile->refresh()];
    }
}
