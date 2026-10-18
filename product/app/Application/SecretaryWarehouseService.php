<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryInventoryException;
use App\Domain\Secretary\SecretaryNotFoundException;
use App\Models\Secretary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class SecretaryWarehouseService
{
    public function __construct(private ParadoxBalanceService $paradox) {}

    /** @return array{capacity: int, used: int, expansions: int, max_expansions: int, next_cost_paradox: int|null, paradox_balance: int} */
    public function present(Secretary $secretary, ?int $used = null, ?int $paradoxBalance = null): array
    {
        $rules = SecretaryItemGrantService::warehouseRules();
        $expansions = (int) $secretary->surfaceState->item_capacity_expansions;

        return [
            'capacity' => SecretaryItemGrantService::capacity($secretary),
            'used' => $used ?? $secretary->itemInstances()->count(),
            'expansions' => $expansions,
            'max_expansions' => $rules['max_expansions'],
            'next_cost_paradox' => $expansions < $rules['max_expansions'] ? $rules['expansion_cost_paradox'] : null,
            'paradox_balance' => $paradoxBalance ?? $this->paradox->balanceFor($secretary->user_id),
        ];
    }

    /** @return array<string, mixed> */
    public function expand(User $user, string $requestId, int $expectedExpansions): array
    {
        return DB::transaction(function () use ($user, $requestId, $expectedExpansions): array {
            $secretary = Secretary::query()->where('user_id', $user->id)->first();
            if (! $secretary instanceof Secretary) {
                throw new SecretaryNotFoundException('秘書がまだ作成されていません。');
            }
            $state = $secretary->lockSurfaceState();
            $previous = DB::table('secretary_warehouse_expansion_receipts')
                ->where('secretary_id', $secretary->id)->where('request_id', $requestId)
                ->lockForUpdate()->first();
            if ($previous !== null) {
                if ((int) $previous->expected_expansions !== $expectedExpansions) {
                    throw new SecretaryInventoryException('request_conflict', '同じ受付IDが別の拡張操作に使用されています。', 409);
                }

                return json_decode($previous->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
            }
            $rules = SecretaryItemGrantService::warehouseRules();
            if ($state->item_capacity_expansions >= $rules['max_expansions']) {
                throw new SecretaryInventoryException('expansion_max', '倉庫はすでに最大まで広がっています。');
            }
            if ($state->item_capacity_expansions !== $expectedExpansions) {
                throw new SecretaryInventoryException('expansion_mismatch', '倉庫の拡張状態が変わりました。画面を再読込してください。', 409);
            }
            $debit = $this->paradox->debit(
                $user->id,
                $rules['expansion_cost_paradox'],
                "secretary-warehouse:{$secretary->id}:{$requestId}",
                'secretary_warehouse_expansion',
                metadata: ['secretary_id' => $secretary->id, 'expansion' => $expectedExpansions + 1],
            );
            if ($debit === null) {
                throw new SecretaryInventoryException('insufficient_paradox', '倉庫を広げる輝石が足りません。');
            }
            $state->item_capacity_expansions++;
            $state->save();
            $result = $this->present($secretary, paradoxBalance: $debit['balance_after']);
            DB::table('secretary_warehouse_expansion_receipts')->insert([
                'secretary_id' => $secretary->id,
                'request_id' => $requestId,
                'expected_expansions' => $expectedExpansions,
                'result_snapshot' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        }, 3);
    }
}
