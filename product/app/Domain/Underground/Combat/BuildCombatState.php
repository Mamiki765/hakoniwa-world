<?php

namespace App\Domain\Underground\Combat;

final class BuildCombatState
{
    public int $hp;

    public int $mp = AlphaV1CombatRules::MAX_MP;

    public int $barrier = 0;

    public bool $guarding = false;

    /** @var array{source_side: 'player'|'enemy', source_key: string, applied_round: int}|null */
    public ?array $taunt = null;

    /** @var array<string, int> */
    public array $cooldowns = [];

    /**
     * @var array<string, array{
     *     key: string,
     *     disposition: 'buff'|'debuff',
     *     remaining: int,
     *     applied_round: int,
     *     stacks: int,
     *     effects: list<array<string, mixed>>,
     *     control: bool
     * }>
     */
    public array $statuses = [];

    /** @var array{fighting_spirit: int, grace: int} */
    public array $roleStacks = ['fighting_spirit' => 0, 'grace' => 0];

    public int $controlResistance = 0;

    public int $controlResistanceRounds = 0;

    /** @var array<string, bool|int|string> */
    public array $flags = [];

    public bool $awakeningUnlocked = false;

    public int $awakeningGauge = 0;

    public int $awakeningGaugeBefore = 0;

    public int $awakeningGaugeGained = 0;

    public bool $awakened = false;

    public ?string $awakeningMessage = null;

    public ?string $awakeningTechniqueKey = null;

    public bool $awakeningTechniqueUsed = false;

    public int $awakeningGuardRoundsRemaining = 0;

    public ?int $awakeningGuardAppliedRound = null;

    /** @var array<string, int> */
    public array $normalStats;

    public int $normalMaxHp;

    public int $equipmentMaxHp = 0;

    public int $equipmentPhysicalDefense = 0;

    public int $equipmentMagicalDefense = 0;

    /**
     * @param  'player'|'enemy'  $side
     * @param  array<string, int>  $stats
     * @param  list<string>  $skills
     * @param  list<array<string, mixed>>  $aiRules
     * @param  array<string, int|bool|string>  $modifiers
     * @param  array{round: int, status: string, message: string}|null  $phaseTransition
     * @param  array<string, mixed>  $normalAttack
     */
    public function __construct(
        public readonly string $side,
        public readonly string $key,
        public readonly string $label,
        public readonly bool $boss,
        public int $maxHp,
        public array $stats,
        public int $physicalDefense,
        public int $magicalDefense,
        public readonly int $defenseReference,
        public readonly int $weaponPower,
        public readonly array $skills,
        public readonly array $aiRules,
        public readonly array $modifiers,
        public readonly ?array $phaseTransition,
        public readonly array $normalAttack,
    ) {
        $this->hp = $maxHp;
        $this->normalMaxHp = $maxHp;
        $this->normalStats = $stats;
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

    public function hasStatus(string $key): bool
    {
        return isset($this->statuses[$key]);
    }

    public function statusStacks(string $key): int
    {
        return $this->statuses[$key]['stacks'] ?? 0;
    }

    public function roleStack(string $key): int
    {
        return $this->roleStacks[$key] ?? 0;
    }

    public function stat(string $key): int
    {
        $base = $this->stats[$key] ?? 0;
        $modifierBps = 0;
        foreach ($this->statuses as $status) {
            foreach ($status['effects'] as $effect) {
                if (($effect['type'] ?? null) === 'stat_modifier'
                    && ($effect['stat'] ?? null) === $key
                    && is_int($effect['value_bps'] ?? null)) {
                    $modifierBps += $effect['value_bps'] * $status['stacks'];
                }
            }
        }

        return max(1, $base + intdiv($base * $modifierBps, 10_000));
    }
}
