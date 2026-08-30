<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

final class UndergroundBuildValidator
{
    public function __construct(private readonly AlphaV1CombatRules $rules) {}

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     base_stats: array<string, int>,
     *     weapon_style: string,
     *     allocations: array<string, int>,
     *     points_spent: int,
     *     tree_points: array<string, int>,
     *     active_skills: list<string>,
     *     passive_nodes: array<string, int>,
     *     ai_rules: list<array<string, mixed>>,
     *     equipment: list<array<string, mixed>>
     * }
     */
    public function validate(AlphaV1BuildCatalog $catalog, string $buildKey): array
    {
        $build = $catalog->build($buildKey);
        $label = $build['label'] ?? null;
        $baseStats = $build['base_stats'] ?? null;
        $weaponStyle = $build['weapon_style'] ?? null;
        $allocations = $build['allocations'] ?? null;
        $activeSkills = $build['active_skills'] ?? null;
        $aiRules = $build['ai_rules'] ?? null;
        $equipment = $build['equipment'] ?? null;
        if (! is_string($label) || $label === '' || ! is_array($baseStats)
            || ! is_string($weaponStyle) || ! in_array($weaponStyle, AlphaV1CombatRules::WEAPON_STYLES, true)
            || ! is_array($allocations) || array_is_list($allocations)
            || ! is_array($activeSkills) || ! array_is_list($activeSkills)
            || ! is_array($aiRules) || ! array_is_list($aiRules)
            || ! is_array($equipment) || ! array_is_list($equipment)) {
            throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] is invalid.");
        }
        $this->rules->assertFiveStats($baseStats);

        $equipmentSlots = [];
        foreach ($equipment as $request) {
            $slot = is_array($request) ? ($request['slot'] ?? null) : null;
            if (! is_string($slot) || ! in_array($slot, AlphaV1CombatRules::EQUIPMENT_SLOTS, true)
                || isset($equipmentSlots[$slot])) {
                throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] equipment loadout is invalid.");
            }
            $equipmentSlots[$slot] = true;
        }

        $treePoints = array_fill_keys(AlphaV1CombatRules::TREES, 0);
        $passiveNodes = [];
        $nodeDetails = [];
        foreach ($allocations as $nodeKey => $rank) {
            if (! is_string($nodeKey) || ! is_int($rank) || $rank < 1) {
                throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] allocation is invalid.");
            }
            $entry = $catalog->node($nodeKey);
            $node = $entry['node'];
            $maxRank = $node['max_rank'] ?? null;
            $cost = $node['point_cost_per_rank'] ?? null;
            if (! is_int($maxRank) || ! is_int($cost) || $rank > $maxRank || $cost < 1) {
                throw new InvalidArgumentException("Underground alpha-v1 node [{$nodeKey}] rank is invalid.");
            }
            $points = $rank * $cost;
            $treePoints[$entry['tree']] += $points;
            $nodeDetails[$nodeKey] = ['tree' => $entry['tree'], 'node' => $node, 'rank' => $rank, 'points' => $points];
            if (($node['type'] ?? null) === 'passive') {
                $passiveNodes[$nodeKey] = $rank;
            }
        }
        $pointsSpent = array_sum($treePoints);
        if ($pointsSpent > $catalog->balanceInt('build_point_budget')) {
            throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] exceeds the point budget.");
        }

        foreach ($nodeDetails as $nodeKey => &$detail) {
            $node = $detail['node'];
            $requiredInvestment = $node['invested_points_required'] ?? null;
            if (! is_int($requiredInvestment) || $requiredInvestment < 0) {
                throw new InvalidArgumentException("Underground alpha-v1 node [{$nodeKey}] tier gate is not met.");
            }
            $detail['required_investment'] = $requiredInvestment;
        }
        unset($detail);

        foreach ($nodeDetails as $nodeKey => $detail) {
            $requiredInvestment = $detail['required_investment'];
            $accessiblePoints = array_sum(array_map(
                static fn (array $candidate): int => $candidate['tree'] === $detail['tree']
                    && $candidate['required_investment'] < $requiredInvestment
                        ? $candidate['points']
                        : 0,
                $nodeDetails,
            ));
            if ($accessiblePoints < $requiredInvestment) {
                throw new InvalidArgumentException("Underground alpha-v1 node [{$nodeKey}] tier gate is not met.");
            }
            $prerequisite = $detail['node']['prerequisite'] ?? null;
            if ($prerequisite !== null
                && (! is_string($prerequisite) || ! isset($allocations[$prerequisite]))) {
                throw new InvalidArgumentException("Underground alpha-v1 node [{$nodeKey}] prerequisite is not met.");
            }
        }

        if ($activeSkills === [] || count($activeSkills) > $catalog->balanceInt('active_skill_limit')
            || count($activeSkills) !== count(array_unique($activeSkills))) {
            throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] active loadout is invalid.");
        }
        foreach ($activeSkills as $skillKey) {
            if (! is_string($skillKey)) {
                throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] active loadout is invalid.");
            }
            $skill = $catalog->skill($skillKey);
            $nodeKey = $skill['node_key'] ?? null;
            if (! is_string($nodeKey) || ! isset($allocations[$nodeKey])) {
                throw new InvalidArgumentException("Underground alpha-v1 skill [{$skillKey}] is not acquired.");
            }
            $requiredWeaponTags = $skill['required_weapon_styles'] ?? [];
            if (! is_array($requiredWeaponTags) || ! array_is_list($requiredWeaponTags)
                || ($requiredWeaponTags !== [] && ! in_array($weaponStyle, $requiredWeaponTags, true))) {
                throw new InvalidArgumentException("Underground alpha-v1 skill [{$skillKey}] weapon requirement is not met.");
            }
        }
        $this->assertAiRules($catalog, $activeSkills, $aiRules, $buildKey);

        return [
            'key' => $buildKey,
            'label' => $label,
            'base_stats' => $baseStats,
            'weapon_style' => $weaponStyle,
            'allocations' => $allocations,
            'points_spent' => $pointsSpent,
            'tree_points' => $treePoints,
            'active_skills' => $activeSkills,
            'passive_nodes' => $passiveNodes,
            'ai_rules' => $aiRules,
            'equipment' => $equipment,
        ];
    }

    /** @return array<string, int> */
    public function fullTreeAllocation(AlphaV1BuildCatalog $catalog, string $treeKey): array
    {
        $tree = $catalog->tree($treeKey);
        $nodes = $tree['nodes'] ?? null;
        if (! is_array($nodes)) {
            throw new InvalidArgumentException("Underground alpha-v1 tree [{$treeKey}] is invalid.");
        }
        $allocation = [];
        foreach ($nodes as $nodeKey => $node) {
            if (is_string($nodeKey) && is_array($node) && is_int($node['max_rank'] ?? null)) {
                $allocation[$nodeKey] = $node['max_rank'];
            }
        }

        return $allocation;
    }

    /**
     * Canonical alpha-v1 passive-node aggregation shared by laboratory and player runtime builds.
     *
     * @param  array<string, mixed>  $passiveNodes
     * @return array<string, int|bool|string>
     */
    public function passiveModifiers(AlphaV1BuildCatalog $catalog, array $passiveNodes): array
    {
        $modifiers = [];
        foreach ($passiveNodes as $nodeKey => $rank) {
            $node = $catalog->node($nodeKey)['node'];
            if (($node['type'] ?? null) !== 'passive' || ! is_int($rank) || $rank < 1
                || $rank > ($node['max_rank'] ?? 0)) {
                throw new InvalidArgumentException("Underground alpha-v1 passive node [{$nodeKey}] is invalid.");
            }
            foreach (($node['modifiers'] ?? []) as $modifier) {
                if (! is_array($modifier) || ! is_string($modifier['key'] ?? null)) {
                    throw new InvalidArgumentException("Underground alpha-v1 passive node [{$nodeKey}] modifier is invalid.");
                }
                $key = $modifier['key'];
                if (($modifier['flag'] ?? false) === true) {
                    $modifiers[$key] = true;
                } elseif (is_int($modifier['fixed_value'] ?? null)) {
                    $modifiers[$key] = $modifier['fixed_value'];
                } elseif (is_int($modifier['value_per_rank'] ?? null)) {
                    $modifiers[$key] = (int) ($modifiers[$key] ?? 0) + ($modifier['value_per_rank'] * $rank);
                } else {
                    throw new InvalidArgumentException("Underground alpha-v1 passive node [{$nodeKey}] modifier is invalid.");
                }
            }
        }

        return $modifiers;
    }

    /**
     * @param  list<string>  $activeSkills
     * @param  list<mixed>  $aiRules
     */
    public function assertAiRules(
        AlphaV1BuildCatalog $catalog,
        array $activeSkills,
        array $aiRules,
        string $buildKey,
    ): void {
        if (count($aiRules) > AlphaV1CombatRules::AI_RULE_LIMIT) {
            throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] has too many AI rules.");
        }
        $conditionTypes = [
            'always', 'own_hp_lte', 'own_hp_gte', 'own_mp_lte', 'own_mp_gte',
            'enemy_hp_lte', 'self_has_status', 'self_lacks_status', 'enemy_has_status',
            'enemy_lacks_status', 'status_stacks_gte', 'role_stacks_gte', 'enemy_telegraph', 'skill_ready',
            'round_gte', 'round_modulo',
        ];
        foreach ($aiRules as $rule) {
            $conditions = is_array($rule) ? ($rule['conditions'] ?? null) : null;
            $action = is_array($rule) ? ($rule['action'] ?? null) : null;
            if (! is_array($conditions) || ! array_is_list($conditions)
                || count($conditions) < 1 || count($conditions) > 2
                || ! is_string($action)) {
                throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] AI rule is invalid.");
            }
            foreach ($conditions as $condition) {
                if (! is_array($condition) || ! in_array($condition['type'] ?? null, $conditionTypes, true)) {
                    throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] AI condition is invalid.");
                }
                if (in_array($condition['type'], ['status_stacks_gte', 'role_stacks_gte'], true)
                    && (! is_string($condition['status'] ?? null)
                        || ! is_int($condition['stacks'] ?? null)
                        || $condition['stacks'] < 1)) {
                    throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] AI stack condition is invalid.");
                }
            }
            if (str_starts_with($action, 'skill:')) {
                $skillKey = substr($action, 6);
                if (! in_array($skillKey, $activeSkills, true)) {
                    throw new InvalidArgumentException("Underground alpha-v1 AI selects unequipped skill [{$skillKey}].");
                }
                $catalog->skill($skillKey);
            } elseif (! in_array($action, ['normal_attack', 'defend'], true)) {
                throw new InvalidArgumentException("Underground alpha-v1 build [{$buildKey}] AI action is invalid.");
            }
        }
    }
}
