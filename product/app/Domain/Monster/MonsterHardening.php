<?php

namespace App\Domain\Monster;

use App\Models\MonsterDefinition;
use DomainException;

final class MonsterHardening
{
    public function isHardened(MonsterDefinition $definition, int $turn): bool
    {
        if ($turn < 1) {
            throw new DomainException('Monster hardening requires a positive turn number.');
        }

        return match ($definition->skill_key) {
            'harden_odd' => $turn % 2 === 1,
            'harden_even' => $turn % 2 === 0,
            'none', 'move_2', 'move_9999' => false,
            default => throw new DomainException("Unknown monster skill {$definition->skill_key}."),
        };
    }
}
