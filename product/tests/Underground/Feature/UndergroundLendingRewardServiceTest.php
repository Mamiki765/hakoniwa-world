<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryLendingService;
use App\Application\Underground\UndergroundLendingRewardService;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Models\Secretary;
use App\Models\SecretaryLendingDailyReward;
use App\Models\UndergroundBattle;
use App\Models\UndergroundParty;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundLendingRewardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_rejects_more_than_four_members(): void
    {
        [$user, $secretary] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);
        $member = $this->member('self', $secretary, $user, 5, 5);
        $this->expectException(InvalidArgumentException::class);
        $service->createSnapshot($user, $secretary, 'exploration', 'ground', 'content', 5, [], array_fill(0, 5, $member));
    }

    public function test_snapshot_rejects_duplicate_secretary_and_self_borrow(): void
    {
        [$user, $secretary] = $this->secretary();
        [$owner, $borrowed] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);
        $self = $this->member('self', $secretary, $user, 5, 5);
        $borrowedMember = $this->member('borrowed_secretary', $borrowed, $owner, 5, 5);
        try {
            $service->createSnapshot($user, $secretary, 'exploration', 'ground', 'content', 5, [], [$self, $borrowedMember, $borrowedMember]);
            $this->fail('Expected a duplicate Secretary to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('underground_parties', 0);
        }

        $ownBorrow = $this->member('borrowed_secretary', $secretary, $user, 5, 5);
        $this->expectException(InvalidArgumentException::class);
        $service->createSnapshot($user, $secretary, 'exploration', 'ground', 'content', 5, [], [$self, $ownBorrow]);
    }

    public function test_snapshot_enforces_level_sync_and_rejects_own_borrow(): void
    {
        [$user, $secretary] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);
        $member = $this->member('self', $secretary, $user, 5, 4);
        $this->expectException(InvalidArgumentException::class);
        $service->createSnapshot($user, $secretary, 'exploration', 'ground', 'content', 5, [], [$member]);
    }

    public function test_public_candidates_do_not_expose_owner_identity(): void
    {
        [$owner, $secretary] = $this->secretary();
        $owner->forceFill(['visitor_code' => 'LENDSAFE'])->save();
        $profile = UndergroundProfile::query()->firstOrCreate(['secretary_id' => $secretary->id]);
        $contractAt = now()->subMinute();
        $profile->update([
            'underground_contract_completed_at' => $contractAt,
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
        ]);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile->fresh());
        app(SecretaryLendingService::class)->update($owner, true);
        $candidate = app(SecretaryLendingService::class)->publicCandidates($owner);
        $this->assertCount(1, $candidate);
        $this->assertArrayNotHasKey('user_id', $candidate[0]);
        $this->assertArrayNotHasKey('email', $candidate[0]);
        $this->assertSame($secretary->id, $candidate[0]['secretary_id']);
    }

    public function test_nine_participations_award_zero_and_tenth_awards_one(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);
        for ($i = 1; $i <= 10; $i++) {
            $party = $this->party($leader, $leaderSecretary, $borrowed, $i);
            $result = $service->settle($this->battle($leader, $party, $i), $party);
            $this->assertSame($i === 10 ? 1 : 0, $result['tickets_awarded']);
        }
        $this->assertSame(1, app(SecretaryLendingService::class)->ticketBalance($owner));
    }

    public function test_repeated_settlement_is_idempotent_and_self_is_ignored(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $party = $this->party($leader, $leaderSecretary, $borrowed, 1);
        $battle = $this->battle($leader, $party, 1);
        $this->assertSame(1, app(UndergroundLendingRewardService::class)->settle($battle, $party)['participations']);
        $this->assertSame(0, app(UndergroundLendingRewardService::class)->settle($battle, $party)['participations']);
        $this->assertSame(0, app(SecretaryLendingService::class)->ticketBalance($owner));
    }

    public function test_daily_cap_stops_ticket_at_one_hundred(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $daily = SecretaryLendingDailyReward::query()->create([
            'owner_user_id' => $owner->id, 'canonical_day' => now()->toDateString(),
            'participation_count' => 999, 'tickets_awarded' => 100,
        ]);
        $party = $this->party($leader, $leaderSecretary, $borrowed, 1);
        $result = app(UndergroundLendingRewardService::class)->settle($this->battle($leader, $party, 1), $party);
        $this->assertSame(0, $result['tickets_awarded']);
        $this->assertSame(100, $daily->refresh()->tickets_awarded);
    }

    public function test_failed_settlement_rolls_back_prior_member_rows(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $party = $this->party($leader, $leaderSecretary, $borrowed, 1);
        $party->members()->create(['source_type' => 'borrowed_secretary', 'secretary_id' => null, 'source_owner_user_id' => null, 'combatant_id' => 'bad', 'original_level' => 5, 'effective_level' => 5, 'snapshot' => []]);
        $battle = $this->battle($leader, $party, 2);
        $this->expectException(InvalidArgumentException::class);
        try {
            app(UndergroundLendingRewardService::class)->settle($battle, $party);
        } finally {
            $this->assertDatabaseCount('secretary_lending_participations', 0);
        }
    }

    public function test_trial_playtest_and_unfinished_battles_are_not_settleable(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);
        foreach ([['trial', 'finished'], ['playtest', 'finished'], ['exploration', 'unfinished']] as [$type, $state]) {
            $party = $service->createSnapshot($leader, $leaderSecretary, $type, 'content', 'content', 5, [], [
                $this->member('self', $leaderSecretary, $leader, 5, 5),
                $this->member('borrowed_secretary', $borrowed, $owner, 5, 5),
            ]);
            if ($state === 'unfinished') {
                DB::statement('ALTER TABLE underground_battles ALTER COLUMN finished_at DROP NOT NULL');
            }
            $battle = $this->battle(
                $leader,
                $party,
                random_int(1, 1000),
                UndergroundBattle::RESULT_VICTORY,
                UndergroundBattle::ACTIVITY_EXPLORATION,
                $state !== 'unfinished',
            );
            try {
                $service->settle($battle, $party);
                $this->fail('Expected ineligible battle to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('secretary_lending_participations', 0);
            }
        }
    }

    /** @return array{User, Secretary} */
    private function secretary(): array
    {
        $user = User::factory()->create();

        return [$user, Secretary::query()->create([
            'user_id' => $user->id,
            'name' => 'Lender',
            'named_at' => now(),
        ])];
    }

    /** @return array{source_type:string, secretary_id:int, source_owner_user_id:int, combatant_id:string, original_level:int, effective_level:int, snapshot:array<string,mixed>} */
    private function member(string $type, Secretary $secretary, User $owner, int $original, int $effective): array
    {
        return ['source_type' => $type, 'secretary_id' => $secretary->id, 'source_owner_user_id' => $owner->id, 'combatant_id' => 'secretary:'.$secretary->id, 'original_level' => $original, 'effective_level' => $effective, 'snapshot' => []];
    }

    private function party(User $leader, Secretary $leaderSecretary, Secretary $borrowed, int $key): UndergroundParty
    {
        return app(UndergroundLendingRewardService::class)->createSnapshot($leader, $leaderSecretary, 'exploration', 'ground', 'content', 5, [], [
            $this->member('self', $leaderSecretary, $leader, 5, 5),
            $this->member('borrowed_secretary', $borrowed, $borrowed->user, 5, 5),
        ]);
    }

    private function battle(User $leader, UndergroundParty $party, int $key, string $result = UndergroundBattle::RESULT_VICTORY, string $activityType = UndergroundBattle::ACTIVITY_EXPLORATION, bool $finished = true): UndergroundBattle
    {
        $profile = UndergroundProfile::query()->firstOrCreate(['secretary_id' => $leader->secretary->id]);

        return UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id, 'underground_party_id' => $party->id,
            'request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'party-test', 'activity_type' => $activityType,
            'activity_key' => 'ground', 'encounter_key' => 'enemy', 'result' => $result,
            'rounds' => 1, 'damage_dealt' => 1, 'damage_received' => 0, 'healing_done' => 0,
            'combat_level_before' => 5, 'combat_level_after' => 5, 'combat_xp_before' => 0, 'combat_xp_after' => 0,
            'shard_balance_before' => 0, 'shard_balance_after' => 0, 'private_seed' => $key,
            'snapshot' => [], 'started_at' => now(), 'finished_at' => $finished ? now() : null,
        ]);
    }
}
