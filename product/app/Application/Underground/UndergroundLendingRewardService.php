<?php

namespace App\Application\Underground;

use App\Models\Secretary;
use App\Models\SecretaryLendingDailyReward;
use App\Models\SecretaryLendingParticipation;
use App\Models\UndergroundBattle;
use App\Models\UndergroundParty;
use App\Models\UndergroundPartyMember;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Settles the owner-side reward for already completed borrowed party members. */
final class UndergroundLendingRewardService
{
    public const DAILY_TICKET_CAP = 100;

    public const PARTICIPATIONS_PER_TICKET = 10;

    public function __construct(private readonly UndergroundBattleStorage $battleStorage) {}

    /**
     * Persists the minimum party identity needed by lending settlement. Combat
     * inputs remain in the in-memory battle result and are never copied here.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{source_type:string, secretary_id:int|null, source_owner_user_id:int|null, combatant_id:string, original_level:int, effective_level:int, snapshot:array<string,mixed>}>  $members
     */
    public function createSnapshot(User $leader, Secretary $leaderSecretary, string $contentType, string $contentKey, string $contentIdentity, int $leaderLevel, array $snapshot, array $members): UndergroundParty
    {
        if ($leaderLevel < 1) {
            throw new InvalidArgumentException('Leader combat level must be positive.');
        }
        if (count($members) < 1 || count($members) > 4 || count(array_filter($members, static fn (array $m): bool => $m['source_type'] === 'borrowed_secretary')) > 3) {
            throw new InvalidArgumentException('A party must contain 1-4 members and at most 3 borrowed Secretaries.');
        }
        $secretaries = array_values(array_filter(array_map(static fn (array $m): ?int => $m['secretary_id'], $members)));
        if (count($secretaries) !== count(array_unique($secretaries))) {
            throw new InvalidArgumentException('A Secretary cannot be added to a party twice.');
        }
        $self = array_values(array_filter($members, static fn (array $m): bool => $m['source_type'] === 'self'));
        if (count($self) !== 1 || (int) ($self[0]['secretary_id'] ?? 0) !== (int) $leaderSecretary->id) {
            throw new InvalidArgumentException('A party requires the leader Secretary as its only self member.');
        }
        foreach ($members as $member) {
            if (! in_array($member['source_type'], ['self', 'borrowed_secretary'], true)
                || $member['combatant_id'] === '') {
                throw new InvalidArgumentException('Party member snapshot shape is invalid.');
            }
            if ($member['source_type'] === 'self'
                && ((int) $member['source_owner_user_id'] !== (int) $leader->id
                    || (int) ($member['secretary_id'] ?? 0) !== (int) $leaderSecretary->id)) {
                throw new InvalidArgumentException('Self member identity is invalid.');
            }
            if ($member['source_type'] === 'borrowed_secretary' && (int) $member['source_owner_user_id'] === (int) $leader->id) {
                throw new InvalidArgumentException('The leader cannot borrow their own Secretary.');
            }
            if ($member['secretary_id'] === null || $member['source_owner_user_id'] === null
                || $member['original_level'] < 1
                || $member['effective_level'] !== min($member['original_level'], $leaderLevel)) {
                throw new InvalidArgumentException('Member level or identity is invalid.');
            }
        }

        return DB::transaction(function () use ($leader, $leaderSecretary, $contentType, $contentKey, $contentIdentity, $leaderLevel, $snapshot, $members): UndergroundParty {
            $party = UndergroundParty::query()->create([
                'leader_user_id' => $leader->id, 'leader_secretary_id' => $leaderSecretary->id,
                'content_type' => $contentType, 'content_key' => $contentKey,
                'content_identity' => $contentIdentity, 'party_size' => count($members),
                'leader_combat_level' => $leaderLevel, 'snapshot' => $snapshot,
            ]);
            foreach ($members as $member) {
                $member['snapshot'] = $this->battleStorage->compactPartyMemberSnapshot($member['snapshot']);
                $party->members()->create($member);
            }

            return $party->load('members');
        }, 3);
    }

    /** @return array{participations: int, tickets_awarded: int} */
    public function settle(UndergroundBattle $battle, UndergroundParty $party): array
    {
        return DB::transaction(function () use ($battle, $party): array {
            $lockedBattle = UndergroundBattle::query()->whereKey($battle->id)->lockForUpdate()->firstOrFail();
            $lockedParty = UndergroundParty::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedBattle->underground_party_id !== (int) $lockedParty->id
                || $lockedBattle->finished_at === null
                || ! in_array($lockedParty->content_type, ['exploration', 'party_boss'], true)
                || ! in_array($lockedBattle->result, [UndergroundBattle::RESULT_VICTORY, UndergroundBattle::RESULT_DEFEAT, UndergroundBattle::RESULT_WITHDRAWAL], true)) {
                throw new InvalidArgumentException('This battle is not eligible for lending settlement.');
            }
            $leaderProfileId = (int) DB::table('underground_profiles')->where('secretary_id', $lockedParty->leader_secretary_id)->value('id');
            if ($leaderProfileId < 1 || $leaderProfileId !== (int) $lockedBattle->underground_profile_id) {
                throw new InvalidArgumentException('Battle and party leader profile identities do not match.');
            }
            $battleSnapshot = $lockedBattle->snapshot;
            $battlePartyIdentity = is_array($battleSnapshot['party'] ?? null) ? ($battleSnapshot['party']['content_identity'] ?? null) : null;
            if ($battlePartyIdentity !== null && $battlePartyIdentity !== $lockedParty->content_identity) {
                throw new InvalidArgumentException('Battle and party content identities do not match.');
            }
            $when = $lockedBattle->finished_at;
            $timezone = (string) config('hakoniwa.turn_schedule.timezone', 'Asia/Tokyo');
            $day = $when->copy()->setTimezone($timezone)->toDateString();
            $participations = 0;
            $tickets = 0;

            /** @var UndergroundPartyMember $member */
            foreach ($lockedParty->members()->orderBy('source_owner_user_id')->orderBy('id')->lockForUpdate()->get() as $member) {
                if ($member->source_type !== 'borrowed_secretary') {
                    continue;
                }
                if ($member->source_owner_user_id === null || $member->secretary_id === null) {
                    throw new InvalidArgumentException('Borrowed party member owner and Secretary are required.');
                }
                $existing = SecretaryLendingParticipation::query()
                    ->where('underground_battle_id', $lockedBattle->id)
                    ->where('underground_party_member_id', $member->id)
                    ->lockForUpdate()->first();
                if ($existing instanceof SecretaryLendingParticipation) {
                    continue;
                }
                // This balance row is the per-owner serialization point even
                // when the current participation does not award a ticket. It
                // keeps the cross-day remainder exact at the date boundary.
                DB::table('user_skip_ticket_balances')->insertOrIgnore([
                    'user_id' => $member->source_owner_user_id, 'balance' => 0,
                    'created_at' => $when, 'updated_at' => $when,
                ]);
                $balance = UserSkipTicketBalance::query()
                    ->where('user_id', $member->source_owner_user_id)->firstOrFail();
                $balance = UserSkipTicketBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
                $priorParticipationCount = SecretaryLendingParticipation::query()
                    ->where('owner_user_id', $member->source_owner_user_id)
                    ->count();
                $participation = SecretaryLendingParticipation::query()->create([
                    'underground_battle_id' => $lockedBattle->id,
                    'underground_party_member_id' => $member->id,
                    'secretary_id' => $member->secretary_id,
                    'owner_user_id' => $member->source_owner_user_id,
                    'canonical_day' => $day,
                    'result' => $lockedBattle->result,
                    'ticket_delta' => 0,
                    'settled_at' => $when,
                ]);
                DB::table('secretary_lending_daily_rewards')->insertOrIgnore([
                    'owner_user_id' => $member->source_owner_user_id, 'canonical_day' => $day,
                    'participation_count' => 0, 'tickets_awarded' => 0,
                    'created_at' => $when, 'updated_at' => $when,
                ]);
                $daily = SecretaryLendingDailyReward::query()
                    ->where('owner_user_id', $member->source_owner_user_id)->where('canonical_day', $day)
                    ->firstOrFail();
                $daily = SecretaryLendingDailyReward::query()->whereKey($daily->id)->lockForUpdate()->firstOrFail();
                $beforeCount = (int) $daily->participation_count;
                $daily->participation_count = $beforeCount + 1;
                $thresholdTickets = intdiv($priorParticipationCount + 1, self::PARTICIPATIONS_PER_TICKET)
                    - intdiv($priorParticipationCount, self::PARTICIPATIONS_PER_TICKET);
                $available = max(0, self::DAILY_TICKET_CAP - (int) $daily->tickets_awarded);
                $ticketDelta = min($thresholdTickets, $available);
                if ($ticketDelta > 0) {
                    $balanceBefore = (int) $balance->balance;
                    $balance->balance += $ticketDelta;
                    $balance->save();
                    DB::table('user_skip_ticket_ledger')->insert([
                        'user_id' => $member->source_owner_user_id,
                        'underground_battle_id' => $lockedBattle->id,
                        'underground_party_member_id' => $member->id,
                        'entry_key' => 'lending:'.$participation->id,
                        'delta' => $ticketDelta,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balance->balance,
                        'canonical_day' => $day,
                        'metadata' => json_encode(['participation_id' => $participation->id], JSON_THROW_ON_ERROR),
                        'created_at' => $when,
                        'updated_at' => $when,
                    ]);
                }
                $daily->tickets_awarded += $ticketDelta;
                $daily->save();
                $participation->ticket_delta = $ticketDelta;
                $participation->save();
                DB::table('audit_events')->insert([
                    'actor_user_id' => $lockedParty->leader_user_id,
                    'event_type' => 'secretary.lending_participation_settled',
                    'severity' => 'info', 'visibility' => 'private',
                    'subject_type' => SecretaryLendingParticipation::class,
                    'subject_id' => $participation->id,
                    'metadata' => json_encode(['battle_id' => $lockedBattle->id, 'owner_user_id' => $member->source_owner_user_id, 'ticket_delta' => $ticketDelta, 'canonical_day' => $day], JSON_THROW_ON_ERROR),
                    'occurred_at' => $when, 'created_at' => $when, 'updated_at' => $when,
                ]);
                $participations++;
                $tickets += $ticketDelta;
            }

            return ['participations' => $participations, 'tickets_awarded' => $tickets];
        }, 3);
    }
}
