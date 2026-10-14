<?php

namespace App\Domain\Command;

final class CapitalRelocationCooldown
{
    public const TURNS = 84;

    public static function remainingTurns(?int $lastSuccessfulTurn, int $turn): int
    {
        return $lastSuccessfulTurn === null ? 0 : max(0, $lastSuccessfulTurn + self::TURNS - $turn);
    }
}
