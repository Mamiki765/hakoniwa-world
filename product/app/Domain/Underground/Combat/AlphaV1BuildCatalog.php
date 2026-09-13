<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

final readonly class AlphaV1BuildCatalog
{
    /** @param array<string, mixed> $manifest */
    public function __construct(private array $manifest)
    {
        if (($manifest['schema_version'] ?? null) !== 2
            || ($manifest['combat_identity'] ?? null) !== AlphaV1CombatRules::IDENTITY
            || ($manifest['generator_identity'] ?? null) !== AlphaV1CombatRules::GENERATOR_IDENTITY) {
            throw new InvalidArgumentException('Underground alpha-v1 manifest identity is invalid.');
        }
        foreach (['balance', 'targeting_contract', 'tiers', 'skill_trees', 'skills', 'statuses', 'equipment', 'builds', 'enemies', 'experiments'] as $key) {
            if (! is_array($manifest[$key] ?? null)) {
                throw new InvalidArgumentException("Underground alpha-v1 manifest [{$key}] is invalid.");
            }
        }
        if ($this->balanceInt('max_mp') !== AlphaV1CombatRules::MAX_MP
            || $this->balanceInt('active_skill_limit') !== AlphaV1CombatRules::ACTIVE_SKILL_LIMIT
            || $this->balanceInt('build_point_budget') !== AlphaV1CombatRules::BUILD_POINT_BUDGET
            || $this->balanceInt('damage_reduction_cap_bps') !== AlphaV1CombatRules::DAMAGE_REDUCTION_CAP_BPS) {
            throw new InvalidArgumentException('Underground alpha-v1 fixed balance contracts are invalid.');
        }
        if (($manifest['base_stats'] ?? null) !== AlphaV1CombatRules::STATS
            || ($manifest['skill_tree_keys'] ?? null) !== AlphaV1CombatRules::TREES
            || ($manifest['weapon_styles'] ?? null) !== AlphaV1CombatRules::WEAPON_STYLES) {
            throw new InvalidArgumentException('Underground alpha-v1 stable key lists are invalid.');
        }

        $this->assertTargetingContract();
        $this->assertStatusDefinitions();
        $this->assertSkillDefinitions();
    }

    public function balanceInt(string $key): int
    {
        $value = $this->manifest['balance'][$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException("Underground alpha-v1 balance [{$key}] must be an integer.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function tier(string $key): array
    {
        return $this->entry('tiers', $key);
    }

    /** @return array<string, mixed> */
    public function build(string $key): array
    {
        return $this->entry('builds', $key);
    }

    /** @return array<string, mixed> */
    public function enemy(string $key): array
    {
        return $this->entry('enemies', $key);
    }

    /** @return array<string, mixed> */
    public function skill(string $key): array
    {
        return $this->entry('skills', $key);
    }

    /** @return array<string, mixed> */
    public function status(string $key): array
    {
        return $this->entry('statuses', $key);
    }

    /** @return array<string, mixed> */
    public function equipment(): array
    {
        return $this->manifest['equipment'];
    }

    /** @return array<string, mixed> */
    public function experiments(): array
    {
        return $this->manifest['experiments'];
    }

    /** @return array<string, mixed> */
    public function targetingContract(): array
    {
        return $this->manifest['targeting_contract'];
    }

    /** @return list<string> */
    public function buildKeys(): array
    {
        return array_values(array_filter(array_keys($this->manifest['builds']), 'is_string'));
    }

    /** @return list<string> */
    public function tierKeys(): array
    {
        return array_values(array_filter(array_keys($this->manifest['tiers']), 'is_string'));
    }

    /** @return array<string, mixed> */
    public function tree(string $key): array
    {
        return $this->entry('skill_trees', $key);
    }

    /** @return array{tree: string, node: array<string, mixed>} */
    public function node(string $key): array
    {
        foreach (AlphaV1CombatRules::TREES as $treeKey) {
            $tree = $this->tree($treeKey);
            $nodes = $tree['nodes'] ?? null;
            if (is_array($nodes) && is_array($nodes[$key] ?? null)) {
                return ['tree' => $treeKey, 'node' => $nodes[$key]];
            }
        }

        throw new InvalidArgumentException("Unknown Underground alpha-v1 skill node [{$key}].");
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return array<string, mixed> */
    private function entry(string $section, string $key): array
    {
        $value = $this->manifest[$section][$key] ?? null;
        if (! is_array($value)) {
            throw new InvalidArgumentException("Unknown Underground alpha-v1 {$section} entry [{$key}].");
        }

        return $value;
    }

    private function assertTargetingContract(): void
    {
        $contract = $this->manifest['targeting_contract'];
        $taunt = $contract['taunt'] ?? null;
        if (($contract['identity'] ?? null) !== AlphaV1CombatRules::TARGETING_IDENTITY
            || ! is_array($taunt)
            || ($taunt['key'] ?? null) !== AlphaV1CombatRules::TAUNT_KEY
            || ($taunt['label'] ?? null) !== '挑発'
            || ($taunt['duration'] ?? null) !== 'battle'
            || ($taunt['targeting_scope'] ?? null) !== AlphaV1CombatRules::TAUNT_TARGETING_SCOPE
            || ($taunt['overrides_explicit_targeting'] ?? null) !== false
            || ($taunt['latest_source_wins'] ?? null) !== true
            || ($taunt['invalid_source_fallback'] ?? null) !== 'normal_target_selection') {
            throw new InvalidArgumentException('Underground alpha-v1 targeting contract is invalid.');
        }
    }

    private function assertStatusDefinitions(): void
    {
        foreach ($this->manifest['statuses'] as $key => $status) {
            if (! is_string($key) || ! is_array($status)
                || ! in_array($status['disposition'] ?? null, ['buff', 'debuff'], true)
                || ! in_array($status['stack_policy'] ?? null, ['refresh', 'stack_refresh'], true)
                || (array_key_exists('dispellable', $status) && ! is_bool($status['dispellable']))
                || ! is_int($status['duration_rounds'] ?? null) || $status['duration_rounds'] < 1
                || ! is_int($status['max_stacks'] ?? null) || $status['max_stacks'] < 1
                || ! is_array($status['effects'] ?? null) || ! array_is_list($status['effects'])) {
                throw new InvalidArgumentException("Underground alpha-v1 status [{$key}] is invalid.");
            }
            foreach ($status['effects'] as $effect) {
                if (! is_array($effect)
                    || ! in_array($effect['type'] ?? null, AlphaV1CombatRules::STATUS_EFFECT_TYPES, true)) {
                    throw new InvalidArgumentException("Underground alpha-v1 status [{$key}] has an invalid effect.");
                }
                if ($effect['type'] === 'periodic_mp_restore'
                    && (! is_int($effect['amount'] ?? null) || $effect['amount'] < 1)) {
                    throw new InvalidArgumentException("Underground status [{$key}] has invalid MP recovery.");
                }
            }
        }
    }

    private function assertSkillDefinitions(): void
    {
        $effectTypes = ['damage', 'heal', 'revive', 'barrier', 'apply_status', 'cleanse', 'dispel', 'mp_restore', 'telegraph', 'taunt'];
        foreach ($this->manifest['skills'] as $key => $skill) {
            if (! is_string($key) || ! is_array($skill)
                || ! is_int($skill['mp_cost'] ?? null) || $skill['mp_cost'] < 0
                || ! is_int($skill['cooldown'] ?? null) || $skill['cooldown'] < 0
                || ! is_array($skill['effects'] ?? null) || ! array_is_list($skill['effects'])
                || $skill['effects'] === []) {
                throw new InvalidArgumentException("Underground alpha-v1 skill [{$key}] is invalid.");
            }
            if (array_key_exists('consumes_action', $skill)
                && (! is_bool($skill['consumes_action'])
                    || ($skill['consumes_action'] === false && $skill['cooldown'] < 2))) {
                throw new InvalidArgumentException("Underground skill [{$key}] needs a cooldown for an additional action.");
            }
            if (isset($skill['required_healing_actions'])
                && (! is_int($skill['required_healing_actions']) || $skill['required_healing_actions'] < 1
                    || $skill['required_healing_actions'] > $this->balanceInt('role_stack_cap'))) {
                throw new InvalidArgumentException("Underground skill [{$key}] has an invalid healing requirement.");
            }
            if (isset($skill['equipped_modifiers'])) {
                if (! is_array($skill['equipped_modifiers'])) {
                    throw new InvalidArgumentException("Underground skill [{$key}] has invalid equipped modifiers.");
                }
                foreach ($skill['equipped_modifiers'] as $modifier => $value) {
                    $valid = match ($modifier) {
                        'counter_power_bps' => is_int($value) && $value >= 0 && $value <= 10_000,
                        'fighting_spirit_enabled' => is_bool($value),
                        default => false,
                    };
                    if (! $valid) {
                        throw new InvalidArgumentException("Underground skill [{$key}] has an unsupported equipped modifier.");
                    }
                }
            }
            if (isset($skill['required_fighting_spirit'])
                && (! is_int($skill['required_fighting_spirit']) || $skill['required_fighting_spirit'] < 1
                    || $skill['required_fighting_spirit'] > $this->balanceInt('role_stack_cap'))) {
                throw new InvalidArgumentException("Underground skill [{$key}] has an invalid fighting spirit requirement.");
            }
            foreach ($skill['effects'] as $effect) {
                if (! is_array($effect) || ! in_array($effect['type'] ?? null, $effectTypes, true)) {
                    throw new InvalidArgumentException("Underground alpha-v1 skill [{$key}] has an invalid effect.");
                }
                if (isset($effect['target_scope'])
                    && ! in_array($effect['target_scope'], ['single_enemy', 'all_enemies', 'single_ally', 'fallen_ally', 'all_allies', 'self'], true)) {
                    throw new InvalidArgumentException("Underground alpha-v1 skill [{$key}] has an invalid target scope.");
                }
                if ($effect['type'] === 'damage'
                    && ($effect['target_max_hp_bps'] ?? 0) > 0
                    && ! is_array($effect['source_cap_coefficients'] ?? null)) {
                    throw new InvalidArgumentException(
                        "Underground alpha-v1 percentage damage [{$key}] requires a source-derived cap.",
                    );
                }
                if ($effect['type'] === 'taunt' && ($effect['target'] ?? null) !== 'enemy') {
                    throw new InvalidArgumentException("Underground alpha-v1 taunt [{$key}] must target the enemy.");
                }
                if ($effect['type'] === 'revive'
                    && (($effect['target_scope'] ?? null) !== 'fallen_ally'
                        || ! is_int($effect['revive_hp_bps'] ?? null)
                        || $effect['revive_hp_bps'] < 1 || $effect['revive_hp_bps'] >= 10_000)) {
                    throw new InvalidArgumentException("Underground skill [{$key}] has an invalid single revival.");
                }
            }
        }
    }
}
