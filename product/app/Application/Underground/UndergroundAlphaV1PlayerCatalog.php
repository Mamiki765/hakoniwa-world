<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
use JsonException;
use RuntimeException;

final readonly class UndergroundAlphaV1PlayerCatalog
{
    public function __construct(
        private AlphaV1CombatRules $rules,
        private UndergroundBuildValidator $buildValidator,
    ) {}

    public function growthIdentity(): string
    {
        return $this->string($this->data(), 'growth_identity');
    }

    public function skillTreeIdentity(): string
    {
        return $this->string($this->data(), 'skill_tree_identity');
    }

    public function targetingIdentity(): string
    {
        $contract = $this->laboratoryCatalog()->targetingContract();
        $identity = $contract['identity'] ?? null;

        return is_string($identity) && $identity === AlphaV1CombatRules::TARGETING_IDENTITY
            ? $identity
            : throw new RuntimeException('Underground targeting identity is invalid.');
    }

    public function initialSkillPoints(): int
    {
        $points = $this->data()['initial_skill_points'] ?? null;

        return is_int($points) && $points === 20
            ? $points
            : throw new RuntimeException('Underground initial Skill Point contract must be exactly 20.');
    }

    /**
     * @param  array<array-key, mixed>  $allocations
     * @return array{
     *     acquired_nodes: array<string, int>,
     *     passive_nodes: array<string, int>,
     *     passive_modifiers: array<string, int|bool|string>,
     *     active_skills: list<string>,
     *     ai_rules: list<array<string, mixed>>,
     *     tree_points: array<string, int>
     * }
     */
    public function playerSkillBuild(array $allocations, ?string $weaponStyle = null): array
    {
        if ($weaponStyle !== null && $weaponStyle === '') {
            throw new RuntimeException('Underground player weapon style is invalid.');
        }
        $catalog = $this->laboratoryCatalog();
        $acquired = [];
        $passives = [];
        $activeBySlot = [];
        $treePoints = array_fill_keys(AlphaV1CombatRules::TREES, 0);
        foreach ($allocations as $nodeKey => $allocation) {
            if (! is_string($nodeKey) || ! is_array($allocation)
                || ! is_int($allocation['rank'] ?? null) || $allocation['rank'] < 1
                || ! array_key_exists('active_slot', $allocation)
                || (! is_null($allocation['active_slot']) && ! is_int($allocation['active_slot']))) {
                throw new RuntimeException('Underground player skill allocation is invalid.');
            }
            $entry = $catalog->node($nodeKey);
            $node = $entry['node'];
            $rank = $allocation['rank'];
            $maxRank = $node['max_rank'] ?? null;
            $cost = $node['point_cost_per_rank'] ?? null;
            if (! is_int($maxRank) || ! is_int($cost) || $rank > $maxRank || $cost < 1) {
                throw new RuntimeException("Underground player skill allocation [{$nodeKey}] is invalid.");
            }
            $acquired[$nodeKey] = $rank;
            $treePoints[$entry['tree']] += $rank * $cost;
            if (($node['type'] ?? null) === 'passive') {
                if ($allocation['active_slot'] !== null) {
                    throw new RuntimeException('Underground passive skill cannot occupy an active slot.');
                }
                $passives[$nodeKey] = $rank;

                continue;
            }
            $skillKey = $node['skill_key'] ?? null;
            if (! is_string($skillKey)) {
                throw new RuntimeException("Underground active skill node [{$nodeKey}] is invalid.");
            }
            $skill = $catalog->skill($skillKey);
            if (($skill['node_key'] ?? null) !== $nodeKey) {
                throw new RuntimeException("Underground active skill node [{$nodeKey}] is invalid.");
            }
            $slot = $allocation['active_slot'];
            if ($slot !== null) {
                if ($slot < 1 || $slot > AlphaV1CombatRules::ACTIVE_SKILL_LIMIT || isset($activeBySlot[$slot])) {
                    throw new RuntimeException('Underground active skill loadout is invalid.');
                }
                if ($weaponStyle === null || $this->skillSupportsWeaponStyle($skill, $weaponStyle)) {
                    $activeBySlot[$slot] = $skillKey;
                }
            }
        }
        ksort($activeBySlot);
        $activeSkills = array_values($activeBySlot);
        $modifiers = $this->buildValidator->passiveModifiers($catalog, $passives);
        $aiRules = $this->playerAiRules($activeSkills);
        $this->buildValidator->assertAiRules($catalog, $activeSkills, $aiRules, 'secretary_runtime');

        return [
            'acquired_nodes' => $acquired,
            'passive_nodes' => $passives,
            'passive_modifiers' => $modifiers,
            'active_skills' => $activeSkills,
            'ai_rules' => $aiRules,
            'tree_points' => $treePoints,
        ];
    }

    /**
     * @param  array<string, array{rank: int, active_slot: int|null}>  $allocations
     * @return list<array<string, mixed>>
     */
    public function skillTrees(array $allocations, int $unspentSkillPoints): array
    {
        if ($unspentSkillPoints < 0) {
            throw new RuntimeException('Underground unspent Skill Points are invalid.');
        }
        $catalog = $this->laboratoryCatalog();
        $build = $this->playerSkillBuild($allocations);
        $trees = [];
        foreach (AlphaV1CombatRules::TREES as $treeKey) {
            $tree = $catalog->tree($treeKey);
            $nodes = [];
            foreach (($tree['nodes'] ?? []) as $nodeKey => $node) {
                if (! is_string($nodeKey) || ! is_array($node)) {
                    throw new RuntimeException("Underground skill tree [{$treeKey}] is invalid.");
                }
                $rank = $build['acquired_nodes'][$nodeKey] ?? 0;
                $cost = $node['point_cost_per_rank'] ?? null;
                $maxRank = $node['max_rank'] ?? null;
                $required = $node['invested_points_required'] ?? null;
                $prerequisite = $node['prerequisite'] ?? null;
                if (! is_int($cost) || ! is_int($maxRank) || ! is_int($required)) {
                    throw new RuntimeException("Underground skill node [{$nodeKey}] is invalid.");
                }
                $reason = null;
                if ($rank >= $maxRank) {
                    $reason = '最大rankです';
                } elseif (is_string($prerequisite) && ! isset($build['acquired_nodes'][$prerequisite])) {
                    $reason = '前提skill未取得';
                } elseif ($this->investedBelowGate($catalog, $allocations, $treeKey, $required) < $required) {
                    $remaining = $required - $this->investedBelowGate($catalog, $allocations, $treeKey, $required);
                    $reason = ($tree['label'] ?? $treeKey)."へあと{$remaining}SP必要";
                } elseif ($unspentSkillPoints < $cost) {
                    $reason = 'SP不足';
                }
                $skill = ($node['type'] ?? null) === 'active'
                    ? $catalog->skill((string) ($node['skill_key'] ?? ''))
                    : null;
                $nodes[] = [
                    'key' => $nodeKey,
                    'label' => $this->string($node, 'label'),
                    'summary' => $this->string($node, 'summary'),
                    'type' => $node['type'],
                    'rank' => $rank,
                    'max_rank' => $maxRank,
                    'point_cost' => $cost,
                    'invested_points_required' => $required,
                    'prerequisite' => $prerequisite,
                    'can_acquire' => $reason === null,
                    'unavailable_reason' => $reason,
                    'skill_key' => $skill === null ? null : $node['skill_key'],
                    'mp_cost' => $skill['mp_cost'] ?? null,
                    'cooldown' => $skill['cooldown'] ?? null,
                    'required_weapon_styles' => $skill['required_weapon_styles'] ?? [],
                    'recommended_stats' => $skill === null
                        ? null
                        : $this->recommendedStats((string) $node['skill_key']),
                    'active_slot' => $allocations[$nodeKey]['active_slot'] ?? null,
                ];
            }
            $trees[] = [
                'key' => $treeKey,
                'label' => $this->string($tree, 'label'),
                'invested_points' => $build['tree_points'][$treeKey],
                'full_points' => $this->fullTreePoints($catalog, $treeKey),
                'nodes' => $nodes,
            ];
        }

        return $trees;
    }

    /**
     * @param  array<string, array{rank: int, active_slot: int|null}>  $allocations
     */
    public function investedBelowGate(
        AlphaV1BuildCatalog $catalog,
        array $allocations,
        string $treeKey,
        int $requiredInvestment,
    ): int {
        $points = 0;
        foreach ($allocations as $nodeKey => $allocation) {
            $entry = $catalog->node($nodeKey);
            $node = $entry['node'];
            if ($entry['tree'] === $treeKey
                && is_int($node['invested_points_required'] ?? null)
                && $node['invested_points_required'] < $requiredInvestment) {
                $points += $allocation['rank'] * (int) $node['point_cost_per_rank'];
            }
        }

        return $points;
    }

    public function fullTreePoints(AlphaV1BuildCatalog $catalog, string $treeKey): int
    {
        $points = 0;
        foreach (($catalog->tree($treeKey)['nodes'] ?? []) as $node) {
            if (! is_array($node) || ! is_int($node['max_rank'] ?? null)
                || ! is_int($node['point_cost_per_rank'] ?? null)) {
                throw new RuntimeException("Underground skill tree [{$treeKey}] is invalid.");
            }
            $points += $node['max_rank'] * $node['point_cost_per_rank'];
        }

        return $points;
    }

    public function explorationIdentity(): string
    {
        return $this->string($this->explorationConfig(), 'identity');
    }

    public function explorationHuntingGroundKey(): string
    {
        return $this->string($this->explorationConfig(), 'hunting_ground_key');
    }

    public function explorationMaxRounds(): int
    {
        $value = $this->explorationConfig()['max_rounds'] ?? null;

        return $value === 100
            ? $value
            : throw new RuntimeException('Underground exploration max rounds must be exactly 100.');
    }

    public function innCost(): int
    {
        $cost = $this->shopConfig()['inn']['cost_shards'] ?? null;

        return is_int($cost) && $cost === 10
            ? $cost
            : throw new RuntimeException('Underground inn cost must be exactly 10G.');
    }

    public function bankTransferUnit(): int
    {
        $unit = $this->shopConfig()['bank']['transfer_unit_shards'] ?? null;

        return is_int($unit) && $unit === 1000
            ? $unit
            : throw new RuntimeException('Underground bank transfer unit must be exactly 1000G.');
    }

    /** @return list<array<string, mixed>> */
    public function growthPaths(): array
    {
        $paths = $this->data()['growth_paths'];

        return array_map(fn (string $key): array => $this->growthPath($key), array_keys($paths));
    }

    /** @return array<string, mixed> */
    public function growthPath(string $key): array
    {
        $configured = $this->data()['growth_paths'][$key] ?? null;
        if (! is_array($configured)) {
            throw new UndergroundRuntimeException('underground_growth_path_invalid', '輝石の選択を確認してください。');
        }
        $buildKey = $this->string($configured, 'build_key');
        $build = $this->laboratoryCatalog()->build($buildKey);
        $stats = $build['base_stats'] ?? null;
        $growth = $configured['natural_growth'] ?? null;
        $stp = $configured['unspent_stp_per_level'] ?? null;
        if (! is_array($stats) || ! is_array($growth) || ! is_int($stp)) {
            throw new RuntimeException("Underground growth path [{$key}] is invalid.");
        }
        $this->rules->assertFiveStats($stats);
        if (array_keys($growth) !== AlphaV1CombatRules::STATS
            || array_filter($growth, 'is_int') !== $growth
            || array_sum($growth) + $stp !== 10
            || ($growth['agility'] ?? null) !== 0) {
            throw new RuntimeException("Underground growth path [{$key}] growth contract is invalid.");
        }
        $description = $configured['description'] ?? null;
        if (! is_array($description) || ! array_is_list($description)
            || array_filter($description, 'is_string') !== $description) {
            throw new RuntimeException("Underground growth path [{$key}] description is invalid.");
        }

        return [
            'key' => $key,
            'identity' => $this->growthIdentity(),
            'label' => $this->string($configured, 'label'),
            'color' => $this->string($configured, 'color'),
            'description' => $description,
            'default_build_key' => $buildKey,
            'stats' => $stats,
            'max_hp' => $this->rules->maxHp($stats, 10_000),
            'max_mp' => AlphaV1CombatRules::MAX_MP,
            'natural_recovery' => $this->laboratoryCatalog()->balanceInt('mp_natural_recovery'),
            'natural_growth' => $growth,
            'unspent_stp_per_level' => $stp,
            'points_per_level' => array_sum($growth) + $stp,
        ];
    }

    /**
     * @param  array<string, mixed>  $allocatedStp
     * @return array{vitality: int, might: int, finesse: int, spirit: int, agility: int}
     */
    public function currentStats(string $growthPathKey, int $combatLevel, array $allocatedStp): array
    {
        if ($combatLevel < 1) {
            throw new RuntimeException('Underground player growth state is invalid.');
        }
        $normalizedAllocatedStp = [];
        foreach (AlphaV1CombatRules::STATS as $key) {
            $value = $allocatedStp[$key] ?? null;
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Underground allocated STP is invalid.');
            }
            $normalizedAllocatedStp[$key] = $value;
        }
        if (array_keys($allocatedStp) !== AlphaV1CombatRules::STATS) {
            throw new RuntimeException('Underground allocated STP is invalid.');
        }
        $path = $this->growthPath($growthPathKey);
        $stats = [];
        foreach (AlphaV1CombatRules::STATS as $key) {
            $stats[$key] = $path['stats'][$key]
                + ($path['natural_growth'][$key] * ($combatLevel - 1))
                + $normalizedAllocatedStp[$key];
        }
        $this->rules->assertFiveStats($stats, false);

        return $stats;
    }

    public function stpEntitlement(string $growthPathKey, int $combatLevel): int
    {
        if ($combatLevel < 1) {
            throw new RuntimeException('Underground combat level is invalid.');
        }

        return ($combatLevel - 1) * $this->growthPath($growthPathKey)['unspent_stp_per_level'];
    }

    /** @return array<string, mixed> */
    public function starterWeapon(): array
    {
        $weapon = $this->explorationConfig()['starter_weapon'] ?? null;
        if (! is_array($weapon)
            || ($weapon['key'] ?? null) !== 'starter_knife'
            || ($weapon['label'] ?? null) !== '護身用ナイフ'
            || ($weapon['item_level'] ?? null) !== 1
            || ($weapon['rarity'] ?? null) !== 'common'
            || ($weapon['weapon_style'] ?? null) !== 'dagger'
            || ! is_int($weapon['weapon_power'] ?? null)
            || $weapon['weapon_power'] < 1
            || ! is_int($weapon['physical_defense'] ?? null) || $weapon['physical_defense'] < 0
            || ! is_int($weapon['magical_defense'] ?? null) || $weapon['magical_defense'] < 0
            || ! is_int($weapon['max_hp'] ?? null) || $weapon['max_hp'] < 0
            || ! is_array($weapon['stats'] ?? null)
            || array_keys($weapon['stats']) !== AlphaV1CombatRules::STATS
            || ($weapon['modifiers'] ?? null) !== []
            || ($weapon['affixes'] ?? null) !== []
            || ! array_key_exists('unique_effect', $weapon)
            || $weapon['unique_effect'] !== null) {
            throw new RuntimeException('Underground starter weapon is invalid.');
        }
        foreach ($weapon['stats'] as $value) {
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Underground starter weapon stats are invalid.');
            }
        }

        return $weapon;
    }

    /**
     * @param  array{vitality: int, might: int, finesse: int, spirit: int, agility: int}  $progressionStats
     * @param  array<string, mixed>  $equipment
     * @return array{vitality: int, might: int, finesse: int, spirit: int, agility: int}
     */
    public function combatStats(array $progressionStats, array $equipment): array
    {
        $this->rules->assertFiveStats($progressionStats, false);
        $bonuses = $equipment['stats'] ?? null;
        if (! is_array($bonuses) || array_keys($bonuses) !== AlphaV1CombatRules::STATS) {
            throw new RuntimeException('Underground equipment stat bonuses are invalid.');
        }
        foreach (AlphaV1CombatRules::STATS as $key) {
            if (! is_int($bonuses[$key])) {
                throw new RuntimeException('Underground equipment stat bonuses are invalid.');
            }
            $progressionStats[$key] += $bonuses[$key];
        }
        $this->rules->assertFiveStats($progressionStats, false);

        return $progressionStats;
    }

    /**
     * @param  array{vitality: int, might: int, finesse: int, spirit: int, agility: int}  $combatStats
     * @param  array<string, mixed>  $equipment
     */
    public function maxHp(array $combatStats, array $equipment): int
    {
        $maxHp = $equipment['max_hp'] ?? null;
        if (! is_int($maxHp) || $maxHp < 0) {
            throw new RuntimeException('Underground equipment max HP bonus is invalid.');
        }

        return $this->rules->maxHp($combatStats, 10_000, $maxHp);
    }

    /**
     * @param  array<string, mixed>  $allocatedStp
     * @param  array<string, mixed>|null  $equipment
     */
    public function currentMaxHp(
        string $growthPathKey,
        int $combatLevel,
        array $allocatedStp,
        ?array $equipment = null,
    ): int {
        $equipment ??= $this->starterWeapon();
        $progressionStats = $this->currentStats($growthPathKey, $combatLevel, $allocatedStp);

        return $this->maxHp($this->combatStats($progressionStats, $equipment), $equipment);
    }

    /** @return list<array{key: string, label: string, weight: int, xp: int, shards: int}> */
    public function explorationEncounters(): array
    {
        $configured = $this->explorationConfig()['encounters'] ?? null;
        if (! is_array($configured) || $configured === []) {
            throw new RuntimeException('Underground exploration encounters are invalid.');
        }
        $encounters = [];
        $weightTotal = 0;
        foreach ($configured as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)
                || ! is_string($entry['label'] ?? null)
                || ! is_int($entry['weight'] ?? null) || $entry['weight'] < 1
                || ! is_int($entry['xp'] ?? null) || $entry['xp'] < 0
                || ! is_int($entry['shards'] ?? null) || $entry['shards'] < 0
                || ! is_array($entry['enemy'] ?? null)) {
                throw new RuntimeException("Underground exploration encounter [{$key}] is invalid.");
            }
            $weightTotal += $entry['weight'];
            $encounters[] = [
                'key' => $key,
                'label' => $entry['label'],
                'weight' => $entry['weight'],
                'xp' => $entry['xp'],
                'shards' => $entry['shards'],
            ];
        }
        if ($weightTotal !== 10_000) {
            throw new RuntimeException('Underground exploration encounter weights must total 10000 bps.');
        }
        $catalog = $this->explorationCatalog();
        foreach ($encounters as $encounter) {
            $catalog->enemy($encounter['key']);
        }

        return $encounters;
    }

    /** @return array{key: string, label: string, weight: int, xp: int, shards: int} */
    public function explorationEncounter(string $key): array
    {
        foreach ($this->explorationEncounters() as $encounter) {
            if ($encounter['key'] === $key) {
                return $encounter;
            }
        }

        throw new UndergroundRuntimeException('underground_encounter_not_supported', '探索先の敵を解決できません。');
    }

    public function weightedExplorationEncounter(int $roll): string
    {
        if ($roll < 1 || $roll > 10_000) {
            throw new RuntimeException('Underground exploration encounter roll is invalid.');
        }
        $upper = 0;
        foreach ($this->explorationEncounters() as $encounter) {
            $upper += $encounter['weight'];
            if ($roll <= $upper) {
                return $encounter['key'];
            }
        }

        throw new RuntimeException('Underground exploration encounter weights are invalid.');
    }

    public function explorationCatalog(): AlphaV1BuildCatalog
    {
        $manifest = $this->laboratoryCatalog()->manifest();
        foreach ($this->explorationConfig()['encounters'] as $key => $entry) {
            if (! is_string($key) || ! is_array($entry) || ! is_array($entry['enemy'] ?? null)
                || array_key_exists($key, $manifest['enemies'])) {
                throw new RuntimeException('Underground exploration enemy catalog is invalid.');
            }
            $manifest['enemies'][$key] = $entry['enemy'];
        }

        return new AlphaV1BuildCatalog($manifest);
    }

    /**
     * @param  array{vitality: int, might: int, finesse: int, spirit: int, agility: int}  $allocatedStp
     * @param  array<string, array{rank: int, active_slot: int|null}>  $skillAllocations
     * @param  array<string, mixed>  $equipment
     * @return array{catalog: AlphaV1BuildCatalog, player_snapshot: array<string, mixed>, progression_stats: array<string, int>, combat_stats: array<string, int>, equipment: array<string, mixed>, current_hp: int, max_hp: int, acquired_nodes: array<string, int>, active_skills: list<string>, passive_modifiers: array<string, int|bool|string>}
     */
    public function explorationCombatDefinition(
        string $growthPathKey,
        int $combatLevel,
        array $allocatedStp,
        array $equipment,
        string $playerDisplayName,
        ?int $currentHp = null,
        array $skillAllocations = [],
    ): array {
        $progressionStats = $this->currentStats($growthPathKey, $combatLevel, $allocatedStp);
        $combatStats = $this->combatStats($progressionStats, $equipment);
        $maxHp = $this->maxHp($combatStats, $equipment);
        $currentHp ??= $maxHp;
        if ($currentHp < 1 || $currentHp > $maxHp) {
            throw new RuntimeException('Underground current HP is invalid.');
        }

        $weaponStyle = $equipment['weapon_style'] ?? null;
        if (! is_string($weaponStyle) || $weaponStyle === '') {
            throw new RuntimeException('Underground player weapon style is invalid.');
        }
        $skillBuild = $this->playerSkillBuild($skillAllocations, $weaponStyle);

        return [
            'catalog' => $this->explorationCatalog(),
            'player_snapshot' => [
                'key' => 'secretary_runtime',
                'label' => $playerDisplayName,
                'stats' => $progressionStats,
                'active_skills' => $skillBuild['active_skills'],
                'ai_rules' => $skillBuild['ai_rules'],
                'modifiers' => $skillBuild['passive_modifiers'],
                'equipment' => $equipment,
                'current_hp' => $currentHp,
            ],
            'progression_stats' => $progressionStats,
            'combat_stats' => $combatStats,
            'equipment' => $equipment,
            'current_hp' => $currentHp,
            'max_hp' => $maxHp,
            'acquired_nodes' => $skillBuild['acquired_nodes'],
            'active_skills' => $skillBuild['active_skills'],
            'passive_modifiers' => $skillBuild['passive_modifiers'],
        ];
    }

    /** @return array<string, mixed> */
    public function playtestOptions(string $growthPathKey): array
    {
        $playtest = $this->playtestConfig();
        $catalog = $this->laboratoryCatalog();
        $builds = [];
        foreach ($playtest['builds'] as $key => $configured) {
            if (! is_string($key) || ! is_array($configured)) {
                throw new RuntimeException('Underground playtest build configuration is invalid.');
            }
            $builds[] = [
                'key' => $key,
                'label' => $this->string($catalog->build($key), 'label'),
                'description' => $this->string($configured, 'description'),
            ];
        }
        $enemies = [];
        foreach ($playtest['enemies'] as $key => $configured) {
            if (! is_string($key) || ! is_array($configured)) {
                throw new RuntimeException('Underground playtest enemy configuration is invalid.');
            }
            $catalog->enemy($key);
            $enemies[] = [
                'key' => $key,
                'label' => $this->string($configured, 'label'),
                'description' => $this->string($configured, 'description'),
            ];
        }

        return [
            'notice' => $this->string($playtest, 'notice'),
            'default_build_key' => $this->growthPath($growthPathKey)['default_build_key'],
            'builds' => $builds,
            'enemies' => $enemies,
        ];
    }

    /** @return array{catalog: AlphaV1BuildCatalog, identity: string, tier_key: string, max_rounds: int, build_label: string, enemy_label: string} */
    public function playtestDefinition(string $buildKey, string $enemyKey): array
    {
        $playtest = $this->playtestConfig();
        $build = $playtest['builds'][$buildKey] ?? null;
        $enemy = $playtest['enemies'][$enemyKey] ?? null;
        if (! is_array($build) || ! is_array($enemy)) {
            throw new UndergroundRuntimeException(
                'underground_playtest_selection_invalid',
                '力試しのbuildまたは対戦相手を確認してください。',
            );
        }
        $maxRounds = $enemy['max_rounds'] ?? null;
        if ($maxRounds !== 100) {
            throw new RuntimeException('Underground playtest round contract is invalid.');
        }
        $catalog = $this->laboratoryCatalog();
        $buildDefinition = $catalog->build($buildKey);
        $catalog->enemy($enemyKey);

        return [
            'catalog' => $catalog,
            'identity' => $this->string($playtest, 'identity'),
            'tier_key' => $this->string($playtest, 'tier_key'),
            'max_rounds' => $maxRounds,
            'build_label' => $this->string($buildDefinition, 'label'),
            'enemy_label' => $this->string($enemy, 'label'),
        ];
    }

    /** @return array{catalog: AlphaV1BuildCatalog, build_key: string, enemy_key: string, tier_key: string, combat_level_equivalent: int, enemy_scale_bps: int, seed: int, max_rounds: int, expected_winner: string} */
    public function trueNameStoryBattle(): array
    {
        $definition = $this->data()['true_name_story_battle'];
        $manifest = $this->laboratoryCatalog()->manifest();
        $buildKey = $this->string($definition, 'build_key');
        $enemyKey = $this->string($definition, 'enemy_key');
        $manifest['builds'][$buildKey] = $definition['build'];
        $manifest['enemies'][$enemyKey] = $definition['enemy'];
        $catalog = new AlphaV1BuildCatalog($manifest);
        $seed = $definition['seed'] ?? null;
        $maxRounds = $definition['max_rounds'] ?? null;
        $equivalentCombatLevel = $definition['combat_level_equivalent'] ?? null;
        if (! is_int($seed) || ! is_int($maxRounds) || ! is_int($equivalentCombatLevel)) {
            throw new RuntimeException('Underground true-name story battle contract is invalid.');
        }

        return [
            'catalog' => $catalog,
            'build_key' => $buildKey,
            'enemy_key' => $enemyKey,
            'tier_key' => $this->string($definition, 'tier_key'),
            'combat_level_equivalent' => $equivalentCombatLevel,
            'enemy_scale_bps' => $this->rules->storyBenchmarkScaleBps($equivalentCombatLevel),
            'seed' => $seed,
            'max_rounds' => $maxRounds,
            'expected_winner' => $this->string($definition, 'expected_winner'),
        ];
    }

    public function laboratoryCatalog(): AlphaV1BuildCatalog
    {
        try {
            $manifest = json_decode(
                file_get_contents(config_path('underground/balance/foundation-v1.json')) ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Underground alpha-v1 manifest is invalid.', previous: $exception);
        }
        if (! is_array($manifest)) {
            throw new RuntimeException('Underground alpha-v1 manifest is invalid.');
        }

        return new AlphaV1BuildCatalog($manifest);
    }

    /** @return array<string, mixed> */
    private function playtestConfig(): array
    {
        $playtest = $this->data()['playtest'];
        if (! is_array($playtest['builds'] ?? null) || ! is_array($playtest['enemies'] ?? null)) {
            throw new RuntimeException('Underground playtest configuration is invalid.');
        }

        return $playtest;
    }

    /** @return array<string, mixed> */
    private function explorationConfig(): array
    {
        $exploration = $this->data()['exploration'];
        if (! is_array($exploration['encounters'] ?? null)
            || ! is_array($exploration['starter_weapon'] ?? null)
            || ! is_array($exploration['player_ai_rules'] ?? null)
            || ! is_array($exploration['player_skill_ai_rules'] ?? null)) {
            throw new RuntimeException('Underground exploration configuration is invalid.');
        }

        return $exploration;
    }

    /** @return array<string, mixed> */
    private function shopConfig(): array
    {
        $shop = $this->data()['shop'];
        if (! is_array($shop['inn'] ?? null) || ! is_array($shop['bank'] ?? null)) {
            throw new RuntimeException('Underground shop configuration is invalid.');
        }

        return $shop;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = config('underground-alpha-v1');
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1
            || ! is_array($data['growth_paths'] ?? null)
            || ! is_string($data['skill_tree_identity'] ?? null)
            || ! is_int($data['initial_skill_points'] ?? null)
            || ! is_array($data['player_skill_guidance'] ?? null)
            || ! is_array($data['exploration'] ?? null)
            || ! is_array($data['shop'] ?? null)
            || ! is_array($data['playtest'] ?? null)
            || ! is_array($data['true_name_story_battle'] ?? null)) {
            throw new RuntimeException('Underground alpha-v1 player configuration is invalid.');
        }

        return $data;
    }

    /** @return list<string> */
    private function recommendedStats(string $skillKey): array
    {
        $guidance = $this->data()['player_skill_guidance'][$skillKey] ?? null;
        $stats = is_array($guidance) ? ($guidance['recommended_stats'] ?? null) : null;
        if (! is_array($stats) || ! array_is_list($stats)
            || count(array_unique($stats)) !== count($stats)) {
            throw new RuntimeException("Underground player skill guidance [{$skillKey}] is invalid.");
        }
        foreach ($stats as $stat) {
            if (! is_string($stat) || ! in_array($stat, AlphaV1CombatRules::STATS, true)) {
                throw new RuntimeException("Underground player skill guidance [{$skillKey}] is invalid.");
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : throw new RuntimeException("Underground alpha-v1 [{$key}] is invalid.");
    }

    /**
     * @param  list<string>  $activeSkills
     * @return list<array<string, mixed>>
     */
    private function playerAiRules(array $activeSkills): array
    {
        $configured = $this->explorationConfig()['player_skill_ai_rules'];
        $emergencyActions = ['skill:mending_prayer', 'skill:renewing_guard'];
        $emergency = [];
        $remaining = [];
        foreach ($configured as $rule) {
            $action = is_array($rule) ? ($rule['action'] ?? null) : null;
            if (! is_string($action) || ! str_starts_with($action, 'skill:')) {
                throw new RuntimeException('Underground player skill AI rule is invalid.');
            }
            if (! in_array(substr($action, 6), $activeSkills, true)) {
                continue;
            }
            if (in_array($action, $emergencyActions, true)) {
                $emergency[] = $rule;
            } else {
                $remaining[] = $rule;
            }
        }
        $base = $this->explorationConfig()['player_ai_rules'];
        $fallback = array_pop($base);
        if (! is_array($fallback) || ($fallback['action'] ?? null) !== 'normal_attack') {
            throw new RuntimeException('Underground player AI fallback is invalid.');
        }

        return [...$emergency, ...$base, ...$remaining, $fallback];
    }

    /** @param array<string, mixed> $skill */
    private function skillSupportsWeaponStyle(array $skill, string $weaponStyle): bool
    {
        $required = $skill['required_weapon_styles'] ?? [];
        if (! is_array($required) || ! array_is_list($required)) {
            throw new RuntimeException('Underground active skill weapon requirement is invalid.');
        }
        foreach ($required as $style) {
            if (! is_string($style) || $style === '') {
                throw new RuntimeException('Underground active skill weapon requirement is invalid.');
            }
        }

        return $required === [] || in_array($weaponStyle, $required, true);
    }
}
