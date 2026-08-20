<?php

namespace App\Domain\Secretary;

use App\Domain\Turn\TurnState;
use DomainException;

final class SecretaryRingFinanceBonus
{
    /** @return array{equipped_level_sum: int, requested: int} */
    public function resolve(TurnState $state, int $nationId): array
    {
        if (! $state->hasSecretaryItemEffectSnapshot($nationId)) {
            return ['equipped_level_sum' => 0, 'requested' => 0];
        }
        $levelSum = 0;
        $requested = 0;
        foreach ($state->secretaryItemEffectSnapshot($nationId)['items'] as $item) {
            foreach ($item['effects'] as $effect) {
                if ($effect['type'] !== SecretaryItemGameplayContract::FINANCE_INCOME_BONUS) {
                    continue;
                }
                if ($item['item_key'] !== SecretaryItemCatalog::RING
                    || ($effect['parameters']['stacking'] ?? null) !== SecretaryItemGameplayContract::RING_STACKING
                    || ! is_int($effect['parameters']['bonus_money_per_level'] ?? null)
                    || $effect['parameters']['bonus_money_per_level'] < 1) {
                    throw new DomainException('Secretary Ring snapshot contains an invalid finance effect.');
                }
                $levelSum += $item['level'];
                $requested += $item['level'] * $effect['parameters']['bonus_money_per_level'];
            }
        }

        return ['equipped_level_sum' => $levelSum, 'requested' => $requested];
    }
}
