<?php

namespace App\Domain\Secretary;

use App\Domain\Monster\MonsterHardening;
use App\Models\MonsterInstance;
use DomainException;

final class SecretaryItemTargetSafetyPolicy
{
    public const METADATA_KEY = 'secretary_item_target_safety';

    public const CERTAIN_SELF_ACTION_AT_REMAINING_HP = 'certain_self_action_at_remaining_hp';

    public function __construct(private readonly MonsterHardening $hardening) {}

    public function allows(MonsterInstance $monster, int $damage, int $targetTurn): bool
    {
        if ($damage < 1 || $monster->current_hp < 1 || ! $monster->relationLoaded('definition')) {
            throw new DomainException('Secretary Item target safety requires positive damage and a loaded alive monster definition.');
        }
        if ($this->hardening->isHardened($monster->definition, $targetTurn)) {
            return false;
        }
        $remainingHp = max(0, $monster->current_hp - $damage);
        if ($remainingHp === 0) {
            return true;
        }
        $hazard = $monster->definition->source_metadata[self::METADATA_KEY] ?? null;
        if ($hazard === null) {
            return true;
        }
        if (! is_array($hazard) || array_keys($hazard) !== ['policy', 'remaining_hp']
            || ($hazard['policy'] ?? null) !== self::CERTAIN_SELF_ACTION_AT_REMAINING_HP
            || ! is_int($hazard['remaining_hp'] ?? null) || $hazard['remaining_hp'] < 1) {
            throw new DomainException('Monster Secretary Item target-safety metadata is invalid.');
        }

        return $remainingHp !== $hazard['remaining_hp'];
    }
}
