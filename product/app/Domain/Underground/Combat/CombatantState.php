<?php

namespace App\Domain\Underground\Combat;

final class CombatantState
{
    public int $hp;

    public int $resource = 0;

    public bool $guarding = false;

    public bool $telegraphing = false;

    /** @var array<string, int> */
    public array $cooldowns = [];

    /**
     * @param  'player'|'enemy'  $side
     * @param  list<string>  $skills
     */
    public function __construct(
        public readonly string $side,
        public readonly string $key,
        public readonly string $label,
        public readonly int $maxHp,
        public readonly int $attack,
        public readonly int $defense,
        public readonly int $speed,
        public readonly array $skills,
        public readonly string $behavior,
    ) {
        $this->hp = $maxHp;
        foreach ($skills as $skill) {
            $this->cooldowns[$skill] = 0;
        }
    }

    public function alive(): bool
    {
        return $this->hp > 0;
    }

    public function skillReady(string $key): bool
    {
        return in_array($key, $this->skills, true) && ($this->cooldowns[$key] ?? 0) === 0;
    }

    public function tickCooldowns(): void
    {
        foreach ($this->cooldowns as $key => $remaining) {
            $this->cooldowns[$key] = max(0, $remaining - 1);
        }
    }
}
