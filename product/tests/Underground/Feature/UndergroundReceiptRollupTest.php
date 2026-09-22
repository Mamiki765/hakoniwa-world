<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundBattleHistoryCompactor;
use App\Application\Underground\UndergroundJournalService;
use App\Application\Underground\UndergroundProfileService;
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
