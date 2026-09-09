<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\WorldMutationLock;
use App\Models\CompensationGrant;
use App\Models\CompensationGrantItem;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\UndergroundProfile;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CompensationWarehouseService
{
    /** @var array<string, array{label: string, unit: string}> */
    public const ASSETS = [
        'money' => ['label' => '資金', 'unit' => '億円'],
        'wheat' => ['label' => '小麦', 'unit' => 'トン'],
        'fish' => ['label' => '魚', 'unit' => 'トン'],
        'meat' => ['label' => '肉', 'unit' => 'トン'],
        'oil' => ['label' => '石油', 'unit' => '万バレル'],
        'paradox' => ['label' => '輝石', 'unit' => 'Pd'],
        'skip_ticket' => ['label' => 'スキップチケット', 'unit' => '枚'],
        'underground_g' => ['label' => '輝石の欠片', 'unit' => 'G'],
    ];

    public function __construct(
        private CapacityBoundedAssetService $boundedAssets,
        private ParadoxBalanceService $paradox,
        private WorldMutationLock $worldMutationLock,
        private CurrentRulesetGuard $rulesetGuard,
        private NextProductionTurnRunGuard $turnRunGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $assets
     * @return array{grant: CompensationGrant, duplicate: bool}
     */
    public function createGrant(
        Nation $nation,
        string $grantKey,
        string $operatorIdentifier,
        string $reason,
        array $assets,
    ): array {
        $assets = $this->normalizedAssets($assets);
        if ($grantKey === '' || mb_strlen($grantKey) > 180
            || $operatorIdentifier === '' || mb_strlen($operatorIdentifier) > 120
            || trim($reason) === '') {
            throw new DomainException('Grant key, operator identifier, and reason are required.');
        }

        return DB::transaction(function () use ($nation, $grantKey, $operatorIdentifier, $reason, $assets): array {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $owner = NationMembership::query()
                ->where('nation_id', $lockedNation->id)
                ->where('world_id', $lockedNation->world_id)
                ->where('role', 'owner')
                ->lockForUpdate()
                ->sole();
            $existing = CompensationGrant::query()->where('grant_key', $grantKey)
                ->with('items')->lockForUpdate()->first();
            if ($existing instanceof CompensationGrant) {
                $existingAssets = $existing->items->pluck('amount', 'asset_key')
                    ->map(static fn (mixed $amount): int => (int) $amount)->all();
                ksort($existingAssets);
                if ($existing->nation_id !== $lockedNation->id
                    || $existing->recipient_user_id !== (int) $owner->user_id
                    || $existing->operator_identifier !== $operatorIdentifier
                    || $existing->reason !== $reason
                    || $existingAssets !== $assets) {
                    throw new DomainException('Compensation grant key conflicts with a different grant.');
                }

                return ['grant' => $existing, 'duplicate' => true];
            }

            $grant = CompensationGrant::query()->create([
                'world_id' => $lockedNation->world_id,
                'nation_id' => $lockedNation->id,
                'recipient_user_id' => (int) $owner->user_id,
                'grant_key' => $grantKey,
                'operator_identifier' => $operatorIdentifier,
                'reason' => trim($reason),
                'status' => CompensationGrant::STATUS_PENDING,
            ]);
            foreach ($assets as $assetKey => $amount) {
                $grant->items()->create([
                    'asset_key' => $assetKey,
                    'amount' => $amount,
                    'claimed_amount' => 0,
                ]);
            }

            return ['grant' => $grant->load('items'), 'duplicate' => false];
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function pendingFor(User $user, Nation $nation): array
    {
        $this->assertRecipient($user, $nation);

        return CompensationGrant::query()
            ->where('nation_id', $nation->id)
            ->where('recipient_user_id', $user->id)
            ->whereIn('status', [CompensationGrant::STATUS_PENDING, CompensationGrant::STATUS_PARTIAL])
            ->with('items')->orderBy('id')->get()
            ->map(fn (CompensationGrant $grant): array => $this->present($grant))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function claim(User $user, Nation $nation, CompensationGrant $grant, string $requestKey): array
    {
        $world = World::query()->findOrFail($nation->world_id);
        $claimLockKey = 'hakoniwa.compensation.claim.request.'.$requestKey;
        $this->acquireClaimRequestLock($claimLockKey);

        try {
            $existing = $this->storedClaimResult($user, $grant, $requestKey);
            if ($existing !== null) {
                return $existing;
            }

            $this->worldMutationLock->acquire($world);
            try {
                return DB::transaction(function () use ($user, $nation, $grant, $requestKey, $world): array {
                    $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                    $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                    $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                    $this->turnRunGuard->assertClear($lockedWorld);

                    $lockedNation = Nation::query()
                        ->whereKey($nation->id)
                        ->where('world_id', $lockedWorld->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $lockedGrant = CompensationGrant::query()->whereKey($grant->id)
                        ->with(['items' => fn ($query) => $query->orderBy('id')])
                        ->lockForUpdate()->firstOrFail();
                    $this->assertGrantRecipient($user, $lockedNation, $lockedGrant);
                    if ($lockedGrant->status === CompensationGrant::STATUS_CLAIMED) {
                        return [
                            'grant' => $this->present($lockedGrant),
                            'applied_now' => [],
                            'already_claimed' => true,
                            'duplicate' => false,
                        ];
                    }

                    $this->lockUserAssetsInCanonicalOrder($user, $lockedGrant);
                    $appliedNow = [];
                    foreach ($lockedGrant->items as $item) {
                        $remaining = $item->amount - $item->claimed_amount;
                        if ($remaining < 1) {
                            continue;
                        }
                        $applied = $this->creditAsset($user, $lockedNation, $item, $remaining, $ruleset);
                        if ($applied < 0 || $applied > $remaining) {
                            throw new DomainException('Compensation claim applied an invalid amount.');
                        }
                        if ($applied > 0) {
                            $item->increment('claimed_amount', $applied);
                        }
                        $appliedNow[] = [
                            'asset_key' => $item->asset_key,
                            'applied' => $applied,
                            'remaining' => $remaining - $applied,
                        ];
                    }

                    $lockedGrant->load('items');
                    $complete = $lockedGrant->items->every(
                        static fn (CompensationGrantItem $item): bool => $item->claimed_amount === $item->amount,
                    );
                    $lockedGrant->fill([
                        'status' => $complete ? CompensationGrant::STATUS_CLAIMED : CompensationGrant::STATUS_PARTIAL,
                        'claimed_at' => $complete ? now() : null,
                    ])->save();
                    $result = [
                        'grant' => $this->present($lockedGrant->fresh('items')),
                        'applied_now' => $appliedNow,
                        'already_claimed' => false,
                    ];
                    DB::table('compensation_grant_claims')->insert([
                        'compensation_grant_id' => $lockedGrant->id,
                        'user_id' => $user->id,
                        'request_key' => $requestKey,
                        'result' => json_encode($result, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);

                    return [...$result, 'duplicate' => false];
                }, 3);
            } finally {
                $this->worldMutationLock->release($world);
            }
        } finally {
            $this->releaseClaimRequestLock($claimLockKey);
        }
    }

    /** @return array<string, mixed>|null */
    private function storedClaimResult(User $user, CompensationGrant $grant, string $requestKey): ?array
    {
        $existing = DB::table('compensation_grant_claims')->where('request_key', $requestKey)->first();
        if ($existing === null) {
            return null;
        }
        if ((int) $existing->compensation_grant_id !== $grant->id
            || (int) $existing->user_id !== $user->id) {
            throw new DomainException('Compensation claim request key conflict.');
        }
        $result = json_decode((string) $existing->result, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new DomainException('Stored compensation claim result is invalid.');
        }

        return [...$result, 'duplicate' => true];
    }

    private function acquireClaimRequestLock(string $key): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException('Compensation claim mutation requires PostgreSQL advisory locks.');
        }
        DB::selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
    }

    private function releaseClaimRequestLock(string $key): void
    {
        DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released', [$key]);
    }

    private function lockUserAssetsInCanonicalOrder(User $user, CompensationGrant $grant): void
    {
        $assetKeys = $grant->items->pluck('asset_key');
        if ($assetKeys->contains('underground_g')) {
            UndergroundProfile::query()
                ->whereHas('secretary', fn ($query) => $query->where('user_id', $user->id))
                ->lockForUpdate()
                ->first();
        }
        if ($assetKeys->contains('skip_ticket')) {
            $when = now();
            DB::table('user_skip_ticket_balances')->insertOrIgnore([
                'user_id' => $user->id,
                'balance' => 0,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
            UserSkipTicketBalance::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        }
        if ($assetKeys->contains('paradox')) {
            $this->paradox->balanceForUpdate($user->id);
        }
    }

    private function creditAsset(
        User $user,
        Nation $nation,
        CompensationGrantItem $item,
        int $amount,
        RulesetVersion $ruleset,
    ): int {
        return match ($item->asset_key) {
            'money' => $this->boundedAssets->creditMoney($nation, $amount, $ruleset)->applied,
            'wheat', 'fish', 'meat' => $this->boundedAssets->creditFood(
                $nation,
                $this->resource($item->asset_key),
                $amount,
                $ruleset,
            )->applied,
            'oil' => $this->boundedAssets->creditResource(
                $nation,
                $this->resource('oil'),
                $amount,
                $ruleset,
            )->applied,
            'paradox' => $this->creditParadox($user, $item, $amount),
            'skip_ticket' => $this->creditSkipTickets($user, $item, $amount),
            'underground_g' => $this->creditUndergroundShards($user, $amount),
            default => throw new DomainException('Unknown compensation asset.'),
        };
    }

    private function creditParadox(User $user, CompensationGrantItem $item, int $amount): int
    {
        $this->paradox->credit(
            $user->id,
            $amount,
            "compensation:{$item->compensation_grant_id}:{$item->id}",
            'compensation',
            metadata: ['compensation_grant_id' => $item->compensation_grant_id],
        );

        return $amount;
    }

    private function creditSkipTickets(User $user, CompensationGrantItem $item, int $amount): int
    {
        $when = now();
        DB::table('user_skip_ticket_balances')->insertOrIgnore([
            'user_id' => $user->id,
            'balance' => 0,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
        $balance = UserSkipTicketBalance::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        $before = (int) $balance->balance;
        if ($amount > 4_294_967_295 - $before) {
            throw new DomainException('Skip ticket balance would overflow.');
        }
        $balance->fill(['balance' => $before + $amount])->save();
        $timezone = (string) config('hakoniwa.turn_schedule.timezone', 'Asia/Tokyo');
        DB::table('user_skip_ticket_ledger')->insert([
            'user_id' => $user->id,
            'underground_battle_id' => null,
            'underground_party_member_id' => null,
            'underground_skip_settlement_id' => null,
            'underground_skip_batch_id' => null,
            'entry_key' => "compensation:{$item->compensation_grant_id}:{$item->id}",
            'delta' => $amount,
            'balance_before' => $before,
            'balance_after' => (int) $balance->balance,
            'canonical_day' => $when->copy()->setTimezone($timezone)->toDateString(),
            'metadata' => json_encode([
                'source' => 'compensation',
                'compensation_grant_id' => $item->compensation_grant_id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $when,
            'updated_at' => $when,
        ]);

        return $amount;
    }

    private function creditUndergroundShards(User $user, int $amount): int
    {
        $profile = UndergroundProfile::query()
            ->whereHas('secretary', fn ($query) => $query->where('user_id', $user->id))
            ->lockForUpdate()->first();
        if (! $profile instanceof UndergroundProfile) {
            return 0;
        }
        if ($amount > PHP_INT_MAX - $profile->shard_balance) {
            throw new DomainException('Underground shard balance would overflow.');
        }
        $profile->increment('shard_balance', $amount);

        return $amount;
    }

    private function resource(string $assetKey): ResourceDefinition
    {
        $resourceKey = $assetKey === 'meat' ? 'monster_meat' : $assetKey;

        return ResourceDefinition::query()->where('key', $resourceKey)->firstOrFail();
    }

    /** @param array<string, mixed> $assets
     * @return array<string, int>
     */
    private function normalizedAssets(array $assets): array
    {
        $normalized = [];
        foreach ($assets as $assetKey => $amount) {
            if (! array_key_exists($assetKey, self::ASSETS) || ! is_int($amount) || $amount < 0) {
                throw new DomainException('Compensation assets must use known keys and non-negative integer amounts.');
            }
            if ($amount > 0) {
                $normalized[$assetKey] = $amount;
            }
        }
        if ($normalized === []) {
            throw new DomainException('At least one positive compensation asset is required.');
        }
        ksort($normalized);

        return $normalized;
    }

    private function assertRecipient(User $user, Nation $nation): void
    {
        if (! NationMembership::query()
            ->where('user_id', $user->id)
            ->where('nation_id', $nation->id)
            ->where('world_id', $nation->world_id)
            ->where('role', 'owner')->exists()) {
            throw new DomainException('Only the target Nation owner can access compensation grants.');
        }
    }

    private function assertGrantRecipient(User $user, Nation $nation, CompensationGrant $grant): void
    {
        $ownerMembership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('nation_id', $nation->id)
            ->where('world_id', $nation->world_id)
            ->where('role', 'owner')
            ->lockForUpdate()
            ->first(['id']);
        if (! $ownerMembership instanceof NationMembership) {
            throw new DomainException('Only the target Nation owner can access compensation grants.');
        }
        if ($grant->nation_id !== $nation->id
            || $grant->world_id !== $nation->world_id
            || $grant->recipient_user_id !== $user->id) {
            throw new DomainException('Compensation grant recipient does not match the authenticated Nation owner.');
        }
    }

    /** @return array<string, mixed> */
    private function present(CompensationGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'grant_key' => $grant->grant_key,
            'reason' => $grant->reason,
            'status' => $grant->status,
            'claimed_at' => $grant->claimed_at?->toIso8601String(),
            'items' => $grant->items->sortBy('id')->map(function (CompensationGrantItem $item): array {
                $display = self::ASSETS[$item->asset_key] ?? throw new DomainException('Unknown compensation asset.');

                return [
                    'asset_key' => $item->asset_key,
                    'label' => $display['label'],
                    'unit' => $display['unit'],
                    'amount' => $item->amount,
                    'claimed_amount' => $item->claimed_amount,
                    'remaining_amount' => $item->amount - $item->claimed_amount,
                ];
            })->values()->all(),
        ];
    }
}
