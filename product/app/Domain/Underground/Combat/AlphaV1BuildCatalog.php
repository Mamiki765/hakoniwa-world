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
        foreach (['balance', 'tiers', 'skill_trees', 'skills', 'statuses', 'equipment', 'builds', 'enemies', 'experiments'] as $key) {
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

    private function assertStatusDefinitions(): void
    {
        foreach ($this->manifest['statuses'] as $key => $status) {
            if (! is_string($key) || ! is_array($status)
                || ! in_array($status['disposition'] ?? null, ['buff', 'debuff'], true)
                || ! in_array($status['stack_policy'] ?? null, ['refresh', 'stack_refresh'], true)
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
            }
        }
    }

    private function assertSkillDefinitions(): void
    {
        $effectTypes = ['damage', 'heal', 'barrier', 'apply_status', 'cleanse', 'dispel', 'mp_restore', 'telegraph'];
        foreach ($this->manifest['skills'] as $key => $skill) {
            if (! is_string($key) || ! is_array($skill)
                || ! is_int($skill['mp_cost'] ?? null) || $skill['mp_cost'] < 0
                || ! is_int($skill['cooldown'] ?? null) || $skill['cooldown'] < 0
                || ! is_array($skill['effects'] ?? null) || ! array_is_list($skill['effects'])
                || $skill['effects'] === []) {
                throw new InvalidArgumentException("Underground alpha-v1 skill [{$key}] is invalid.");
            }
            foreach ($skill['effects'] as $effect) {
                if (! is_array($effect) || ! in_array($effect['type'] ?? null, $effectTypes, true)) {
                    throw new InvalidArgumentException("Underground alpha-v1 skill [{$key}] has an invalid effect.");
                }
                if ($effect['type'] === 'damage'
                    && ($effect['target_max_hp_bps'] ?? 0) > 0
                    && ! is_array($effect['source_cap_coefficients'] ?? null)) {
                    throw new InvalidArgumentException(
                        "Underground alpha-v1 percentage damage [{$key}] requires a source-derived cap.",
                    );
                }
            }
        }
    }
}
