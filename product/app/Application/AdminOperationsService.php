<?php

namespace App\Application;

use App\Application\Underground\UndergroundReceiptPurgeService;
use App\Application\Underground\UndergroundReceiptRollupService;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\WorldEventContext;
use App\Domain\World\WorldMutationLock;
use App\Models\AuctionListing;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\TurnRun;
use App\Models\UndergroundProfile;
use App\Models\User;
use App\Models\World;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AdminOperationsService
{
    public function __construct(
        private AnnouncementAdminAuthorizer $authorizer,
        private CompensationWarehouseService $warehouse,
        private UserMembershipMutationLock $membershipLock,
        private WorldMutationLock $worldLock,
        private CurrentRulesetGuard $rulesetGuard,
        private NextProductionTurnRunGuard $turnGuard,
        private NationAbandonmentOperation $abandonment,
        private TradingPostTurnService $tradingPost,
        private UndergroundReceiptPurgeService $purge,
    ) {}

    /** @return array<string, mixed> */
    public function overview(User $actor, World $world): array
    {
        $this->authorize($actor);
        $recent = DB::query()->fromSub($this->recentBattles(), 'recent')
            ->select('underground_profile_id')->distinct();
        $secretaries = Secretary::query()->whereHas('undergroundProfile', fn ($query) => $query->whereIn('id', $recent))
            ->orderBy('id')->get(['id', 'user_id', 'name']);

        return [
            'world' => ['id' => $world->id, 'name' => $world->name, 'current_turn' => $world->current_turn],
            'assets' => CompensationWarehouseService::ASSETS,
            'user_count' => User::query()->count(),
            'nations' => Nation::query()->where('world_id', $world->id)->where('state', '!=', 'abandoned')
                ->orderBy('nation_number')->get(['id', 'nation_number', 'name', 'state'])->toArray(),
            'recent_secretaries' => $secretaries->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function turnStatus(User $actor, World $world): array
    {
        $this->authorize($actor);
        $status = app(TurnScheduleStatus::class)->forWorld($world);

        return [...$status, 'current_turn' => $world->current_turn,
            'calendar_initialized' => $world->turn_schedule_origin_at !== null,
            'can_attempt' => $world->turn_schedule_origin_at !== null
                && CarbonImmutable::parse($status['next_scheduled_turn_at'])->isBefore(now())];
    }

    /** One explicit manual attempt, bound to the next target displayed to the operator.
     * @return array<string, mixed>
     */
    public function advanceTurn(User $actor, World $world, int $targetTurn): array
    {
        $this->authorize($actor);
        $this->worldLock->acquire($world);
        try {
            $world = $world->fresh();
            if ($world->current_turn >= $targetTurn) {
                $run = TurnRun::query()->where('world_id', $world->id)->where('target_turn', $targetTurn)
                    ->where('is_dry_run', false)->where('status', TurnRun::STATUS_COMPLETED)->firstOrFail();

                return ['target_turn' => $targetTurn, 'status' => $run->status, 'duplicate' => true];
            }
            $status = $this->turnStatus($actor, $world);
            if ($targetTurn !== $world->current_turn + 1 || ! $status['can_attempt']) {
                throw new DomainException('次回予定時刻を過ぎていないか、表示が古くなっています。最新状態を確認してください。');
            }
            DB::table('audit_events')->insert([
                'actor_user_id' => $actor->id, 'world_id' => $world->id, 'turn' => $targetTurn,
                'event_type' => 'admin.turn_requested', 'visibility' => 'admin', 'severity' => 'info',
                'subject_type' => World::class, 'subject_id' => $world->id,
                'metadata' => json_encode(['target_turn' => $targetTurn], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $run = app(TurnRunner::class)->run($world, source: 'manual');

            return ['target_turn' => $targetTurn, 'status' => $run->status, 'duplicate' => false,
                'failure_code' => $run->failure_code];
        } finally {
            $this->worldLock->release($world);
        }
    }

    private function recentBattles(): Builder
    {
        $since = now()->subDays(7);
        $query = DB::table('underground_battles')->select('underground_profile_id')->where('finished_at', '>=', $since);
        foreach (['underground_skip_settlements', 'underground_skip_batches'] as $table) {
            $query->unionAll(DB::table($table)->select('underground_profile_id')->where('settled_at', '>=', $since));
        }

        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function purgeStatus(User $actor): array
    {
        $this->authorize($actor);
        $rows = [];
        $cutoff = CarbonImmutable::now()->subDays(30);
        foreach (UndergroundProfile::query()->orderBy('id')->lazyById(100, 'id') as $profile) {
            foreach (UndergroundReceiptRollupService::STREAMS as $stream) {
                $row = $this->purge->purge($profile->id, $stream, $cutoff);
                if ($row['old_receipts_in_scan'] > 0) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(User $actor, World $world, string $operation, array $input): array
    {
        $this->authorize($actor);
        $payload = match ($operation) {
            'distribution' => $this->distributionPreview($world, $input),
            'abandonment' => $this->abandonmentPreview($world, $input),
            'purge' => $this->purgePreview($input),
            default => throw new DomainException('管理操作が不明です。'),
        };
        $claims = [
            'version' => 1, 'request_id' => (string) Str::uuid(), 'actor_id' => $actor->id,
            'world_id' => $world->id, 'operation' => $operation,
            'expires_at' => now()->addHour()->timestamp, 'payload' => $payload,
        ];

        return ['token' => Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR)), ...$payload];
    }

    /** @return array<string, mixed> */
    public function apply(User $actor, World $world, string $operation, string $token): array
    {
        $this->authorize($actor);
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new DomainException('確認情報が無効です。もう一度内容を確認してください。');
        }
        if (! is_array($claims) || ($claims['version'] ?? null) !== 1
            || ($claims['actor_id'] ?? null) !== $actor->id || ($claims['world_id'] ?? null) !== $world->id
            || ($claims['operation'] ?? null) !== $operation) {
            throw new DomainException('確認情報の対象が一致しません。');
        }
        $key = 'hakoniwa.admin.operation.'.$claims['request_id'];
        DB::selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
        $ownerIds = [];
        $worldHeld = false;
        try {
            $stored = DB::table('audit_events')->where('event_type', 'admin.operation_completed')
                ->where('metadata->request_id', $claims['request_id'])->first(['metadata']);
            if ($stored !== null) {
                $metadata = json_decode($stored->metadata, true, 512, JSON_THROW_ON_ERROR);

                return [...$metadata['result'], 'duplicate' => true];
            }
            if ($claims['expires_at'] <= now()->timestamp) {
                throw new DomainException('確認の有効期限が切れました。内容を確認し直してください。');
            }
            $payload = $claims['payload'];
            $ownerIds = match ($operation) {
                'distribution' => array_column($payload['recipients'], 'user_id'),
                'abandonment' => [$payload['owner_user_id']],
                default => [],
            };
            sort($ownerIds, SORT_NUMERIC);
            foreach ($ownerIds as $ownerId) {
                $this->membershipLock->acquire($ownerId);
            }
            if ($operation === 'abandonment') {
                $this->worldLock->acquire($world);
                $worldHeld = true;
            }

            return DB::transaction(function () use ($actor, $world, $operation, $claims, $payload): array {
                $result = match ($operation) {
                    'distribution' => $this->distribute($actor, $world, $claims['request_id'], $payload),
                    'abandonment' => $this->abandon($actor, $world, $payload),
                    'purge' => $this->applyPurge($payload),
                    default => throw new DomainException('管理操作が不明です。'),
                };
                DB::table('audit_events')->insert([
                    'actor_user_id' => $actor->id, 'world_id' => $world->id,
                    'turn' => $world->fresh()->current_turn, 'visibility' => 'admin',
                    'event_type' => 'admin.operation_completed', 'severity' => 'info',
                    'subject_type' => World::class, 'subject_id' => $world->id,
                    'metadata' => json_encode([
                        'request_id' => $claims['request_id'], 'operation' => $operation,
                        'result' => $result, 'operator_note' => $payload['operator_note'] ?? null,
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return [...$result, 'duplicate' => false];
            }, 3);
        } finally {
            if ($worldHeld) {
                $this->worldLock->release($world);
            }
            foreach (array_reverse($ownerIds) as $ownerId) {
                $this->membershipLock->release($ownerId);
            }
            DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function distributionPreview(World $world, array $input): array
    {
        $kind = $input['target_kind'];
        $ids = array_values(array_unique([...($input['selected_ids'] ?? []), ...$this->parseIds($input['manual_ids'] ?? '')]));
        $users = User::query();
        if ($kind !== 'all') {
            if ($ids === []) {
                throw new DomainException('配布対象を選択してください。');
            }
            if ($kind === 'nation') {
                $targets = Nation::query()->where('world_id', $world->id)->where('state', '!=', 'abandoned')
                    ->whereIn('nation_number', $ids)->get();
                $resolved = $targets->pluck('nation_number')->map(fn ($id): int => (int) $id)->all();
                $users->whereIn('id', NationMembership::query()->whereIn('nation_id', $targets->modelKeys())->where('role', 'owner')->select('user_id'));
            } elseif ($kind === 'secretary') {
                $targets = Secretary::query()->whereIn('id', $ids)->get();
                $resolved = $targets->modelKeys();
                $users->whereIn('id', $targets->pluck('user_id'));
            } elseif ($kind === 'user') {
                $resolved = User::query()->whereIn('id', $ids)->pluck('id')->all();
                $users->whereIn('id', $ids);
            } else {
                throw new DomainException('配布対象の種類が不明です。');
            }
            if (array_diff($ids, $resolved) !== []) {
                throw new DomainException('見つからない対象番号があります。種類と番号を確認してください。');
            }
        }
        $islands = DB::table('nation_memberships')->join('nations', 'nations.id', '=', 'nation_memberships.nation_id')
            ->where('nation_memberships.world_id', $world->id)->where('role', 'owner')
            ->get(['user_id', 'nations.name', 'nations.nation_number'])->keyBy('user_id');
        $recipients = $users->orderBy('id')->get(['id', 'display_name'])->map(function (User $user) use ($islands): array {
            $nation = $islands->get($user->id);

            return ['user_id' => $user->id, 'name' => $user->display_name,
                'nation_name' => $nation?->name, 'nation_number' => $nation?->nation_number];
        })->all();
        $assets = array_filter($input['assets'], fn (int $amount): bool => $amount > 0);
        if ($recipients === [] || $assets === []) {
            throw new DomainException('配布対象と1以上の配布量が必要です。');
        }

        return ['recipients' => $recipients, 'assets' => $assets, 'reason' => trim($input['reason'])];
    }

    /** @return list<int> */
    private function parseIds(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $text) as $part) {
            if (! preg_match('/^\s*([1-9][0-9]{0,9})(?:\s*-\s*([1-9][0-9]{0,9}))?\s*$/D', $part, $match)) {
                throw new DomainException('番号は1-4,10,14,15の形式で入力してください。');
            }
            $from = (int) $match[1];
            $to = isset($match[2]) ? (int) $match[2] : $from;
            if ($to < $from || $to - $from > 5000 || count($ids) + $to - $from + 1 > 5000) {
                throw new DomainException('番号の範囲を小さく分けてください。');
            }
            array_push($ids, ...range($from, $to));
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function distribute(User $actor, World $world, string $requestId, array $payload): array
    {
        $grantIds = [];
        foreach ($payload['recipients'] as $recipient) {
            $grant = $this->warehouse->createForUser($world, User::query()->findOrFail($recipient['user_id']),
                "admin:{$requestId}:{$recipient['user_id']}", "user:{$actor->id}", $payload['reason'], $payload['assets']);
            $grantIds[] = $grant['grant']->id;
        }

        return ['grant_ids' => $grantIds, 'recipient_count' => count($grantIds)];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function abandonmentPreview(World $world, array $input): array
    {
        $nation = Nation::query()->where('world_id', $world->id)->where('state', '!=', 'abandoned')->findOrFail($input['nation_id']);
        $owner = NationMembership::query()->where('nation_id', $nation->id)->where('role', 'owner')->sole();

        return ['nation_id' => $nation->id, 'nation_name' => $nation->name, 'nation_number' => $nation->nation_number,
            'owner_user_id' => (int) $owner->user_id, 'public_reason' => trim($input['public_reason']),
            'operator_note' => trim($input['operator_note'] ?? ''), 'auctions' => $this->auctionSnapshot($nation)];
    }

    /** @return list<array<string, mixed>> */
    private function auctionSnapshot(Nation $nation): array
    {
        return AuctionListing::query()->where('world_id', $nation->world_id)->where('status', AuctionListing::STATUS_ACTIVE)
            ->where(fn ($query) => $query->where('seller_nation_id', $nation->id)->orWhere('highest_bidder_nation_id', $nation->id))
            ->orderBy('id')->get(['id', 'seller_nation_id', 'highest_bidder_nation_id', 'current_price', 'bid_count', 'product_type'])->toArray();
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function abandon(User $actor, World $world, array $payload): array
    {
        $world = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $this->rulesetGuard->assertMutable($world, $ruleset);
        $this->turnGuard->assertClear($world);
        $nation = Nation::query()->where('world_id', $world->id)->whereKey($payload['nation_id'])->lockForUpdate()->firstOrFail();
        $ownerId = NationMembership::query()->where('nation_id', $nation->id)->where('role', 'owner')->value('user_id');
        if ($nation->state === 'abandoned' || (int) $ownerId !== $payload['owner_user_id']
            || $nation->name !== $payload['nation_name'] || $this->auctionSnapshot($nation) !== $payload['auctions']) {
            throw new DomainException('対象島または競売の状態が変わりました。整理内容を確認し直してください。');
        }
        $settled = $this->tradingPost->settleForAbandonment(new WorldEventContext($world, $ruleset, $world->current_turn, $actor->id), $nation);
        $result = $this->abandonment->execute($world, $ruleset, $nation, $actor->id, 'administrative', $world->current_turn, $payload['public_reason']);

        return [...$result, 'settled_auction_count' => $settled];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function purgePreview(array $input): array
    {
        $cutoff = CarbonImmutable::now()->subDays(30)->startOfSecond();
        $row = $this->purge->purge($input['profile_id'], $input['stream'], $cutoff);

        return ['profile_id' => $input['profile_id'], 'stream' => $input['stream'], 'cutoff' => $cutoff->toIso8601String(), 'preview' => $row];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyPurge(array $payload): array
    {
        UndergroundProfile::query()->whereKey($payload['profile_id'])->lockForUpdate()->firstOrFail();
        $cutoff = CarbonImmutable::parse($payload['cutoff']);
        $current = $this->purge->purge($payload['profile_id'], $payload['stream'], $cutoff);
        foreach (['from_id', 'candidate_through_id', 'candidates'] as $field) {
            if ($current[$field] !== $payload['preview'][$field]) {
                throw new DomainException('削除候補が変わりました。内容を確認し直してください。');
            }
        }

        return $this->purge->purge($payload['profile_id'], $payload['stream'], $cutoff, apply: true);
    }

    private function authorize(User $actor): void
    {
        if (! $this->authorizer->allows($actor)) {
            throw new AuthorizationException('管理者だけが操作できます。');
        }
    }
}
