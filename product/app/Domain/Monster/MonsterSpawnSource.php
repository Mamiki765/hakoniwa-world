<?php

namespace App\Domain\Monster;

enum MonsterSpawnSource: string
{
    case Natural = 'natural';
    case MonsterDispatchCommand = 'monster_dispatch_command';

    public function canActOnSpawnTurn(): bool
    {
        return match ($this) {
            self::Natural, self::MonsterDispatchCommand => false,
        };
    }
}
