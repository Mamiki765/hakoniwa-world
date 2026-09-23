<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundBattleHistoryCompactor;
use App\Application\Underground\UndergroundJournalService;
use App\Application\Underground\UndergroundLifetimeStatistics;
use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundReceiptPurgeService;
use App\Application\Underground\UndergroundReceiptRollupService;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundParty;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkipBatch;
use App\Models\UndergroundSkipSettlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class UndergroundReceiptRollupTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_prefixes_preserve_journal_meaning_without_deleting_or_reawarding(): void
    {
        [$user, $profile] = $this->profile();
        $old = CarbonImmutable::now()->subDays(40);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $rollups = app(UndergroundReceiptRollupService::class);
        $journal = app(UndergroundJournalService::class);
        $party = UndergroundParty::query()->create([
            'leader_user_id' => $user->id, 'leader_secretary_id' => $profile->secretary_id,
            'content_type' => 'exploration', 'content_key' => 'ground', 'content_identity' => 'historical-party',
            'party_size' => 2, 'leader_combat_level' => 1, 'snapshot' => [],
        ]);
        $unknown = $this->battle($profile, $old, [
            'underground_party_id' => $party->id,
            'damage_dealt' => 900, 'damage_received' => 500,
            'statistics_version' => 1,
            'statistics' => ['self' => ['damage_dealt' => null, 'damage_received' => null]],
        ]);
        $this->assertNull($journal->forUser($user)['damage_dealt']);
        $rollups->aggregate($profile->id, 'battle', $cutoff, 1, true);
        $this->assertNull($journal->forUser($user)['damage_dealt']);

        $this->battle($profile, $old, ['damage_dealt' => 12, 'damage_received' => 4]);
        $this->battle($profile, $old, [
            'activity_type' => 'tutorial', 'activity_key' => 'first_descent_tutorial', 'damage_dealt' => 2,
        ]);
        $this->battle($profile, $old, ['activity_type' => 'guide_duel', 'damage_dealt' => 9_999]);
        $this->skip($profile, $user, $old, false);
        $this->skip($profile, $user, $old, true);
        $profile->update(['trophy_shelf_purchased_at' => $old]);
        DB::table('underground_trial_progress')->insert([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_02',
            'unlocked_at' => $old, 'first_cleared_at' => $old, 'first_challenged_at' => $old,
        ]);
        DB::table('underground_content_clear_progress')->insert([
            'underground_profile_id' => $profile->id, 'content_type' => 'hunting_ground',
            'content_key' => 'ground', 'actual_clear_count' => 1, 'total_clear_count' => 5,
        ]);
        [$otherUser, $otherProfile] = $this->profile();
        $this->battle($otherProfile, $old, ['damage_dealt' => 7_777]);
        $before = $journal->forUser($user);
        $resources = $profile->fresh()->getRawOriginal();
        $sourceRows = DB::table('underground_battles')->where('underground_profile_id', $profile->id)->orderBy('id')->get()->all();
        $this->assertSame(3, $before['battle_count']);
        $this->assertSame(14, $before['damage_dealt']);
        $this->assertSame(1, $before['damage_dealt_unknown_battles']);
        $this->assertSame(4, $before['skip_tickets_used']);

        $preview = $rollups->aggregate($profile->id, 'battle', $cutoff, 1);
        $this->assertFalse($preview['applied']);
        $this->assertSame($unknown->id, $preview['verified_through_id']);
        $this->assertSame($before, $journal->forUser($user));
        $this->artisan('hakoniwa:underground:receipts:aggregate', [
            '--profile' => (string) $profile->id, '--before' => $cutoff->toIso8601String(), '--apply' => true,
        ])->assertSuccessful();
        $this->assertSame($before, $journal->forUser($user));
        foreach (UndergroundReceiptRollupService::STREAMS as $stream) {
            $this->assertSame(0, $rollups->aggregate($profile->id, $stream, $cutoff, 500, true)['receipts']);
        }
        $this->assertSame($resources, $profile->fresh()->getRawOriginal());
        $this->assertEquals($sourceRows, DB::table('underground_battles')->where('underground_profile_id', $profile->id)->orderBy('id')->get()->all());
        $this->assertSame(1, UndergroundSkipSettlement::query()->where('underground_profile_id', $profile->id)->count());
        $this->assertSame(1, UndergroundSkipBatch::query()->where('underground_profile_id', $profile->id)->count());
        $this->assertSame(7_777, $journal->forUser($otherUser)['damage_dealt']);

        $saved = DB::table('underground_receipt_rollups')->where('underground_profile_id', $profile->id)->orderBy('stream')->get()->all();
        $this->artisan('hakoniwa:underground:receipts:purge', ['--profile' => $profile->id])->assertSuccessful();
        $this->assertEquals($saved, DB::table('underground_receipt_rollups')->where('underground_profile_id', $profile->id)->orderBy('stream')->get()->all());
        $this->assertEquals($sourceRows, DB::table('underground_battles')->where('underground_profile_id', $profile->id)->orderBy('id')->get()->all());
        $this->artisan('hakoniwa:underground:receipts:purge', ['--profile' => $profile->id, '--apply' => true])->assertSuccessful();
        $this->assertSame($before, $journal->forUser($user));
        $this->assertSame($resources, $profile->fresh()->getRawOriginal());
        $this->assertDatabaseHas('underground_content_clear_progress', ['underground_profile_id' => $profile->id, 'total_clear_count' => 5]);
        $this->assertDatabaseMissing('underground_battles', ['underground_profile_id' => $profile->id]);
        $this->assertDatabaseMissing('underground_skip_settlements', ['underground_profile_id' => $profile->id]);
        $this->assertDatabaseMissing('underground_skip_batches', ['underground_profile_id' => $profile->id]);

        $this->battle($profile, CarbonImmutable::now(), ['damage_dealt' => 5]);
        $after = $journal->forUser($user);
        $this->assertSame(4, $after['battle_count']);
        $this->assertSame(19, $after['damage_dealt']);
        $this->assertSame(1, $after['damage_dealt_unknown_battles']);
    }

    public function test_prefix_never_skips_legacy_preparation_or_a_newer_receipt(): void
    {
        [, $profile] = $this->profile();
        $old = CarbonImmutable::now()->subDays(40);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $rollups = app(UndergroundReceiptRollupService::class);
        $first = $this->battle($profile, $old);
        $legacy = $this->battle($profile, $old, ['compaction_version' => null, 'compacted_at' => null]);
        $lastOld = $this->battle($profile, $old);
        $recent = $this->battle($profile, CarbonImmutable::now());
        $this->battle($profile, $old); // Backdated later ID must not leap over the retained prefix.
        $before = $rollups->totals($profile->id);

        $firstResult = $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true);
        $this->assertSame('legacy_statistics_pending', $firstResult['stop_reason']);
        $this->assertSame($first->id, $firstResult['verified_through_id']);
        $this->assertSame(1, $firstResult['receipts']);
        $this->assertSame($before, $rollups->totals($profile->id));

        // Use the existing supported preparation, rather than freezing pre-backfill totals.
        app(UndergroundBattleHistoryCompactor::class)->compact(Carbon::parse($cutoff->toIso8601String()), 1, 1, 10);
        $this->assertNotNull($legacy->fresh()->compaction_version);
        $secondResult = $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true);
        $this->assertSame('retention_window', $secondResult['stop_reason']);
        $this->assertSame($lastOld->id, $secondResult['verified_through_id']);
        $this->assertLessThan($recent->id, $secondResult['verified_through_id']);
        $this->assertSame($before, $rollups->totals($profile->id));
        $this->assertSame(0, $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true)['receipts']);
    }

    public function test_failed_checkpoint_write_rolls_back_and_retry_does_not_double_count(): void
    {
        [, $profile] = $this->profile();
        $old = CarbonImmutable::now()->subDays(40);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $rollups = app(UndergroundReceiptRollupService::class);
        $first = $this->battle($profile, $old);
        $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true);
        $second = $this->battle($profile, $old, ['damage_dealt' => 7]);
        $before = $rollups->totals($profile->id);
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        $connection->setEventDispatcher($dispatcher);
        $injected = false;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$injected): void {
            if (str_starts_with($query->sql, 'update "underground_receipt_rollups"')) {
                $injected = true;
                throw new RuntimeException('receipt rollup interrupted before verification');
            }
        });
        try {
            $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true);
            $this->fail('Expected the interrupted aggregation to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('receipt rollup interrupted before verification', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
        $this->assertTrue($injected);
        $this->assertDatabaseHas('underground_receipt_rollups', [
            'underground_profile_id' => $profile->id, 'stream' => 'battle', 'verified_through_id' => $first->id,
        ]);
        $this->assertSame($before, $rollups->totals($profile->id));
        $retry = $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true);
        $this->assertSame($second->id, $retry['verified_through_id']);
        $this->assertSame(1, $retry['receipts']);
        $this->assertSame($before, $rollups->totals($profile->id));
    }

    public function test_lifetime_statistics_preserve_known_unknown_and_maximum_after_receipt_deletion(): void
    {
        [, $profile] = $this->profile();
        $old = CarbonImmutable::now()->subDays(40);
        $known = $this->battle($profile, $old, ['statistics_version' => 1, 'statistics' => [
            'party' => ['damage_dealt' => 70, 'effective_healing' => 20],
            'self' => ['damage_dealt' => 35, 'maximum_hit' => 25, 'maximum_hit_action_key' => 'combo',
                'maximum_hit_damage_source' => 'direct', 'effective_healing' => 7,
                'complete_guard_count' => 1, 'damage_prevented' => 4, 'action_usage' => ['combo' => 2]],
        ]]);
        $unknown = $this->battle($profile, $old, ['statistics_version' => 1, 'statistics' => [
            'party' => ['damage_dealt' => 100, 'effective_healing' => 30],
            'self' => ['damage_dealt' => null, 'maximum_hit' => null, 'effective_healing' => null],
            'completeness' => ['reasons' => ['legacy_party_self_attribution_unavailable' => 1]],
        ]]);
        $statistics = app(UndergroundLifetimeStatistics::class);
        $before = $statistics->totals($profile->id);
        app(UndergroundReceiptRollupService::class)->aggregate($profile->id, 'battle', CarbonImmutable::now()->subDays(30), 500, true);
        app(UndergroundReceiptPurgeService::class)->purge($profile->id, 'battle', CarbonImmutable::now()->subDays(30), 500, true);
        $after = $statistics->totals($profile->id);
        $this->assertEquals($before, $after);
        $this->assertSame(170, $after['party']['damage_dealt']['known_sum']);
        $this->assertSame(50, $after['party']['effective_healing']['known_sum']);
        $this->assertSame(['known_sum' => 7, 'known_count' => 1, 'unknown_count' => 1], $before['self']['effective_healing']);
        $this->assertSame(25, $after['self']['maximum_hit']['value']);
        $this->assertSame('combo', $after['self']['maximum_hit']['action_key']);
        $this->assertSame(['combo' => 2], $after['self']['action_usage']['known_sums']);
        $this->assertSame(1, $after['self']['complete_guard_count']['known_sum']);
        $this->assertSame(4, $after['self']['damage_prevented']['known_sum']);
        $this->assertSame(1, $after['incomplete_reasons']['legacy_party_self_attribution_unavailable']);
    }

    public function test_purge_stops_at_pins_and_unprepared_rows_and_requires_verification(): void
    {
        [, $profile] = $this->profile();
        $old = CarbonImmutable::now()->subDays(40);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $first = $this->battle($profile, $old);
        $pinned = $this->battle($profile, $old);
        $last = $this->battle($profile, $old);
        $unprepared = $this->battle($profile, $old, ['compaction_version' => null, 'compacted_at' => null]);
        $this->battle($profile, $old);
        DB::table('underground_battle_image_references')->insert([
            'reference_key' => 'pin', 'underground_battle_id' => $pinned->id,
            'path' => str_repeat('a', 64).'.png', 'retained_until' => now()->addDay(),
        ]);
        $purge = app(UndergroundReceiptPurgeService::class);
        $this->assertSame('unverified_receipt', $purge->purge($profile->id, 'battle', $cutoff, 500, true)['stop_reason']);
        $this->assertDatabaseCount('underground_receipt_rollups', 0);
        app(UndergroundReceiptRollupService::class)->aggregate($profile->id, 'battle', $cutoff, 500, true);
        $result = $purge->purge($profile->id, 'battle', $cutoff, 500, true);
        $this->assertSame($first->id, $result['deleted_through_id']);
        $this->assertSame('retention_pin', $result['stop_reason']);
        $this->assertSame($pinned->id, $result['stopped_at_id']);
        DB::table('underground_battle_image_references')->where('underground_battle_id', $pinned->id)->update(['retained_until' => $old]);
        $result = $purge->purge($profile->id, 'battle', $cutoff, 500, true);
        $this->assertSame($last->id, $result['deleted_through_id']);
        $this->assertSame($last->id, $result['verified_through_id']);
        $this->assertSame('legacy_statistics_pending', $result['stop_reason']);
        $this->assertSame($unprepared->id, $result['stopped_at_id']);
        $this->assertSame(2, UndergroundBattle::query()->where('underground_profile_id', $profile->id)->count());
    }

    public function test_interrupted_purge_resumes_after_committed_prefix_without_reaggregating(): void
    {
        [, $profile] = $this->profile();
        $old = CarbonImmutable::now()->subDays(40);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $first = $this->battle($profile, $old);
        $second = $this->battle($profile, $old);
        $rollups = app(UndergroundReceiptRollupService::class);
        $purge = app(UndergroundReceiptPurgeService::class);
        $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true);
        $totals = $rollups->totals($profile->id);
        $purge->purge($profile->id, 'battle', $cutoff, 1, true);
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'delete from "underground_battles"')) {
                throw new RuntimeException('interrupted after DELETE');
            }
        });
        try {
            $purge->purge($profile->id, 'battle', $cutoff, 1, true);
            $this->fail('Expected deletion rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('interrupted after DELETE', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertDatabaseHas('underground_battles', ['id' => $second->id]);
        $this->assertDatabaseHas('underground_receipt_rollups', [
            'underground_profile_id' => $profile->id, 'stream' => 'battle',
            'deleted_through_id' => $first->id, 'deleted_receipt_count' => 1,
        ]);
        $result = $purge->purge($profile->id, 'battle', $cutoff, 500, true);
        $this->assertSame($second->id, $result['deleted_through_id']);
        $this->assertSame(1, $result['candidates']);
        $this->assertSame(0, $rollups->aggregate($profile->id, 'battle', $cutoff, 500, true)['receipts']);
        $this->assertSame($totals, $rollups->totals($profile->id));
    }

    /** @return array{User, UndergroundProfile} */
    private function profile(): array
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update(['villa_purchased_at' => now(), 'shard_balance' => 1_234, 'banked_shard_balance' => 5_678]);

        return [$user, $profile];
    }

    /** @param array<string, mixed> $overrides */
    private function battle(UndergroundProfile $profile, CarbonInterface $at, array $overrides = []): UndergroundBattle
    {
        return UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id, 'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64), 'runtime_identity' => 'receipt-rollup-fixture',
            'activity_type' => 'exploration', 'activity_key' => 'ground', 'encounter_key' => 'enemy',
            'result' => 'victory', 'rounds' => 1, 'damage_dealt' => 1, 'damage_received' => 0, 'healing_done' => 0,
            'xp_awarded' => 0, 'shard_delta' => 0, 'combat_level_before' => 1, 'combat_level_after' => 1,
            'combat_xp_before' => 0, 'combat_xp_after' => 0, 'shard_balance_before' => 0, 'shard_balance_after' => 0,
            'private_seed' => 1, 'snapshot' => [], 'started_at' => $at, 'finished_at' => $at,
            'compaction_version' => 1, 'compacted_at' => $at, ...$overrides,
        ]);
    }

    private function skip(UndergroundProfile $profile, User $user, CarbonInterface $at, bool $bulk): void
    {
        $values = [
            'underground_profile_id' => $profile->id, 'user_id' => $user->id, 'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64), 'skip_identity' => 'receipt-rollup-fixture',
            'content_type' => 'hunting_ground', 'content_key' => 'ground', 'content_identity' => 'receipt-rollup-fixture',
            'ticket_cost' => $bulk ? 3 : 1, 'xp_awarded' => 0, 'shard_awarded' => 0,
            'combat_level_before' => 1, 'combat_level_after' => 1, 'combat_xp_before' => 0, 'combat_xp_after' => 0,
            'shard_balance_before' => 0, 'shard_balance_after' => 0, 'reward_snapshot' => [], 'settled_at' => $at,
        ];
        if ($bulk) {
            UndergroundSkipBatch::query()->create([...$values, 'execution_count' => 3]);
        } else {
            UndergroundSkipSettlement::query()->create([...$values, 'private_seed' => 1]);
        }
    }
}
