<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryLendingService;
use App\Application\Underground\UndergroundBattleHistoryCompactor;
use App\Application\Underground\UndergroundLendingRewardService;
use App\Application\Underground\UndergroundReceiptPurgeService;
use App\Application\Underground\UndergroundReceiptRollupService;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Models\Secretary;
use App\Models\SecretaryLendingDailyReward;
use App\Models\UndergroundBattle;
use App\Models\UndergroundParty;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundLendingRewardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v2',
        ]);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile->fresh());
        app(SecretaryLendingService::class)->update($owner, true);
        $candidate = app(SecretaryLendingService::class)->publicCandidates($owner);
        $this->assertCount(1, $candidate);
        $this->assertArrayNotHasKey('user_id', $candidate[0]);
        $this->assertArrayNotHasKey('email', $candidate[0]);
        $this->assertSame($secretary->id, $candidate[0]['secretary_id']);
    }

    public function test_ninth_to_tenth_participation_carries_across_days_and_is_idempotent(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);

        Carbon::setTestNow('2026-08-08 23:50:00+09:00');
        for ($i = 1; $i <= 9; $i++) {
            $party = $this->party($leader, $leaderSecretary, $borrowed, $i);
            $this->assertSame(0, $service->settle($this->battle($leader, $party, $i), $party)['tickets_awarded']);
        }
        Carbon::setTestNow('2026-09-09 00:10:00+09:00');
        $leaderProfile = UndergroundProfile::query()->where('secretary_id', $leaderSecretary->id)->sole();
        app(UndergroundBattleHistoryCompactor::class)->compact(now()->subDays(30), 100, 100, 30);
        app(UndergroundReceiptRollupService::class)->aggregate($leaderProfile->id, 'battle', now()->subDays(30), 100, true);
        app(UndergroundReceiptPurgeService::class)->purge($leaderProfile->id, 'battle', now()->subDays(30), 100, true);
        $this->assertDatabaseCount('secretary_lending_participations', 0);
        $party = $this->party($leader, $leaderSecretary, $borrowed, 10);
        $battle = $this->battle($leader, $party, 10);
        $this->assertSame(
            ['participations' => 1, 'tickets_awarded' => 1],
            $service->settle($battle, $party),
        );
        $this->assertSame(
            ['participations' => 0, 'tickets_awarded' => 0],
            $service->settle($battle, $party),
        );

        $this->assertSame(1, app(SecretaryLendingService::class)->ticketBalance($owner));
        $this->assertSame([
            ['canonical_day' => '2026-08-08', 'participation_count' => 9, 'tickets_awarded' => 0],
            ['canonical_day' => '2026-09-09', 'participation_count' => 1, 'tickets_awarded' => 1],
        ], SecretaryLendingDailyReward::query()->where('owner_user_id', $owner->id)
            ->orderBy('canonical_day')->get(['canonical_day', 'participation_count', 'tickets_awarded'])
            ->map(fn (SecretaryLendingDailyReward $reward): array => [
                'canonical_day' => $reward->canonical_day->toDateString(),
                'participation_count' => $reward->participation_count,
                'tickets_awarded' => $reward->tickets_awarded,
            ])->all());

        for ($i = 11; $i <= 19; $i++) {
            $party = $this->party($leader, $leaderSecretary, $borrowed, $i);
            $service->settle($this->battle($leader, $party, $i), $party);
        }
        Carbon::setTestNow('2026-10-10 00:10:00+09:00');
        app(UndergroundBattleHistoryCompactor::class)->compact(now()->subDays(30), 100, 100, 30);
        app(UndergroundReceiptRollupService::class)->aggregate($leaderProfile->id, 'battle', now()->subDays(30), 100, true);
        app(UndergroundReceiptPurgeService::class)->purge($leaderProfile->id, 'battle', now()->subDays(30), 100, true);
        $this->assertDatabaseCount('secretary_lending_participations', 0);
        $party = $this->party($leader, $leaderSecretary, $borrowed, 20);
        $this->assertSame(1, $service->settle($this->battle($leader, $party, 20), $party)['tickets_awarded']);
        $this->assertSame(2, app(SecretaryLendingService::class)->ticketBalance($owner));
        $this->assertDatabaseHas('user_skip_ticket_balances', ['user_id' => $owner->id, 'lifetime_participation_count' => 20]);
    }

    public function test_daily_cap_stops_the_tenth_participation_ticket_at_one_hundred(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);
        for ($i = 1; $i <= 9; $i++) {
            $party = $this->party($leader, $leaderSecretary, $borrowed, $i);
            $this->assertSame(0, $service->settle($this->battle($leader, $party, $i), $party)['tickets_awarded']);
        }
        $daily = SecretaryLendingDailyReward::query()
            ->where('owner_user_id', $owner->id)
            ->sole();
        $this->assertSame(9, $daily->participation_count);
        $daily->update(['tickets_awarded' => 100]);

        $party = $this->party($leader, $leaderSecretary, $borrowed, 10);
        $battle = $this->battle($leader, $party, 10);
        $result = $service->settle($battle, $party);

        $this->assertSame(1, $result['participations']);
        $this->assertSame(0, $result['tickets_awarded']);
        $this->assertSame(10, $daily->refresh()->participation_count);
        $this->assertSame(100, $daily->refresh()->tickets_awarded);
        $this->assertSame(0, app(SecretaryLendingService::class)->ticketBalance($owner));
        $this->assertDatabaseHas('secretary_lending_participations', [
            'underground_battle_id' => $battle->id,
            'owner_user_id' => $owner->id,
            'ticket_delta' => 0,
        ]);
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

    public function test_trial_and_playtest_battles_are_not_settleable(): void
    {
        [$owner, $borrowed] = $this->secretary();
        [$leader, $leaderSecretary] = $this->secretary();
        $service = app(UndergroundLendingRewardService::class);
        foreach (['trial', 'playtest'] as $type) {
            $party = $service->createSnapshot($leader, $leaderSecretary, $type, 'content', 'content', 5, [], [
                $this->member('self', $leaderSecretary, $leader, 5, 5),
                $this->member('borrowed_secretary', $borrowed, $owner, 5, 5),
            ]);
            $battle = $this->battle(
                $leader,
                $party,
                random_int(1, 1000),
                UndergroundBattle::RESULT_VICTORY,
                UndergroundBattle::ACTIVITY_EXPLORATION,
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
