<?php

namespace App\Application;

use App\Models\UserParadoxBalance;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Surface-only, user-scoped Paradox balance. It is not a Nation trade resource. */
final class ParadoxBalanceService
{
    public const NAME = '輝石';

    public const UNIT = 'Pd';

    public const DESCRIPTION = '触れると懐かしい気持ちになる不思議な宝石。それぞれのエルフごとに相性の良い輝石が存在する。貴重な施設やコマンドの使用に使用する';

    public function balanceFor(int $userId): int
    {
        return (int) (UserParadoxBalance::query()->where('user_id', $userId)->value('balance') ?? 0);
    }

    public function balanceForUpdate(int $userId): int
    {
        $when = now();
        DB::table('user_paradox_balances')->insertOrIgnore([
            'user_id' => $userId,
            'balance' => 0,
            'created_at' => $when,
            'updated_at' => $when,
        ]);

        return (int) UserParadoxBalance::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail()
            ->balance;
    }

    /** @return array{name:string, unit:string, description:string, balance:int} */
    public function presentFor(int $userId): array
    {
        return [
            'name' => self::NAME,
            'unit' => self::UNIT,
            'description' => self::DESCRIPTION,
            'balance' => $this->balanceFor($userId),
        ];
    }

    /** @param array<string, mixed> $metadata
     * @return array{duplicate:bool, balance_before:int, balance_after:int, delta:int}
     */
    public function credit(
        int $userId,
        int $amount,
        string $entryKey,
        string $sourceKind,
        ?string $canonicalDay = null,
        array $metadata = [],
    ): array {
        if ($amount < 1) {
            throw new DomainException('Paradox credit amount must be positive.');
        }

        return $this->mutate($userId, $amount, $entryKey, $sourceKind, $canonicalDay, $metadata);
    }

    /** @param array<string, mixed> $metadata
     * @return array{duplicate:bool, balance_before:int, balance_after:int, delta:int}|null
     */
    public function debit(
        int $userId,
        int $amount,
        string $entryKey,
        string $sourceKind,
        ?string $canonicalDay = null,
        array $metadata = [],
    ): ?array {
        if ($amount < 1) {
            throw new DomainException('Paradox debit amount must be positive.');
        }

        return $this->mutate($userId, -$amount, $entryKey, $sourceKind, $canonicalDay, $metadata, true);
    }

    /** @param array<string, mixed> $metadata
     * @return array{duplicate:bool, balance_before:int, balance_after:int, delta:int}|null
     */
    private function mutate(
        int $userId,
        int $delta,
        string $entryKey,
        string $sourceKind,
        ?string $canonicalDay,
        array $metadata,
        bool $allowInsufficient = false,
    ): ?array {
        if (! in_array($sourceKind, ['daily_login', 'daily_quest', 'command', 'compensation'], true)) {
            throw new DomainException('Unknown Paradox ledger source.');
        }

        return DB::transaction(function () use ($userId, $delta, $entryKey, $sourceKind, $canonicalDay, $metadata, $allowInsufficient): ?array {
            $existing = DB::table('user_paradox_ledger')->where('entry_key', $entryKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->user_id !== $userId
                    || (int) $existing->delta !== $delta
                    || (string) $existing->source_kind !== $sourceKind) {
                    throw new DomainException('Paradox ledger idempotency key conflict.');
                }

                return [
                    'duplicate' => true,
                    'balance_before' => (int) $existing->balance_before,
                    'balance_after' => (int) $existing->balance_after,
                    'delta' => $delta,
                ];
            }

            $when = now();
            DB::table('user_paradox_balances')->insertOrIgnore([
                'user_id' => $userId,
                'balance' => 0,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
            $balance = UserParadoxBalance::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $before = (int) $balance->balance;
            if ($delta < 0 && $before < -$delta) {
                if ($allowInsufficient) {
                    return null;
                }
                throw new DomainException('Insufficient Paradox balance.');
            }
            if ($delta > 0 && $before > PHP_INT_MAX - $delta) {
                throw new DomainException('Paradox balance would overflow.');
            }
            $after = $before + $delta;
            $balance->fill(['balance' => $after])->save();
            DB::table('user_paradox_ledger')->insert([
                'user_id' => $userId,
                'entry_key' => $entryKey,
                'delta' => $delta,
                'balance_before' => $before,
                'balance_after' => $after,
                'source_kind' => $sourceKind,
                'canonical_day' => $canonicalDay,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $when,
                'updated_at' => $when,
            ]);

            return [
                'duplicate' => false,
                'balance_before' => $before,
                'balance_after' => $after,
                'delta' => $delta,
            ];
        }, 3);
    }
}
