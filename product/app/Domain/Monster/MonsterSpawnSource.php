<?php

namespace App\Domain\Monster;

enum MonsterSpawnSource: string
{
    case Natural = 'natural';
    case MonsterDispatchCommand = 'monster_dispatch_command';
    case WorldSeaDisaster = 'world_sea_disaster';

    public function canActOnSpawnTurn(): bool
    {
        return match ($this) {
            self::Natural, self::MonsterDispatchCommand, self::WorldSeaDisaster => false,
        };
    }
}
