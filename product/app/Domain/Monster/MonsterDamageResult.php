<?php

namespace App\Domain\Monster;

final readonly class MonsterDamageResult
{
    /**
     * @param  array<string, int>|null  $killerMoney
     * @param  array<string, int>|null  $hostMeat
     */
    public function __construct(
        public string $status,
        public int $beforeHp,
        public int $afterHp,
        public bool $blocked,
        public bool $killed,
        public ?array $killerMoney = null,
        public ?array $hostMeat = null,
        public int $firingBaseExperienceApplied = 0,
        public ?int $killStatId = null,
        public ?int $previousKillCount = null,
        public ?int $newKillCount = null,
    ) {}
}
