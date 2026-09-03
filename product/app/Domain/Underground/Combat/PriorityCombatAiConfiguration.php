<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;
use JsonException;

final readonly class PriorityCombatAiConfiguration
{
    public const SCHEMA_VERSION = 1;

    public const MAX_CONDITIONS_PER_RULE = 2;

    /**
     * @param  array<mixed>  $rules
     * @return list<array<string, mixed>>
     */
    public function normalizeRules(array $rules, AlphaV1BuildCatalog $catalog): array
    {
        if (! array_is_list($rules) || count($rules) > AlphaV1CombatRules::AI_RULE_LIMIT) {
            throw new InvalidArgumentException('AI rules must be a list of at most 16 rules.');
        }

        $skillKeys = array_fill_keys(array_column($this->playerSkills($catalog), 'key'), true);
        $normalized = [];
        $ruleCount = count($rules);
        foreach ($rules as $index => $rule) {
            if (! is_array($rule) || ! array_key_exists('conditions', $rule)
                || ! is_array($rule['conditions']) || ! array_is_list($rule['conditions'])
                || count($rule['conditions']) > self::MAX_CONDITIONS_PER_RULE
                || ! is_string($rule['action'] ?? null)) {
                throw new InvalidArgumentException("AI rule [{$index}] is invalid.");
            }

            $conditions = $rule['conditions'] === []
                ? [['type' => 'always']]
                : array_map(
                    fn (mixed $condition): array => $this->normalizeCondition($condition, $catalog, $skillKeys),
                    $rule['conditions'],
                );
            if (count($conditions) > 1
                && count(array_filter($conditions, static fn (array $condition): bool => $condition['type'] === 'always')) > 0) {
                throw new InvalidArgumentException("AI rule [{$index}] cannot combine always with another condition.");
            }
            usort(
                $conditions,
                static fn (array $left, array $right): int => strcmp(
                    (string) json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (string) json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ),
            );

            $action = $rule['action'];
            $allowedRuleKeys = ['conditions', 'action'];
            $normalizedRule = ['conditions' => $conditions, 'action' => $action];
            if ($action === 'jump') {
                $allowedRuleKeys[] = 'jump_to';
                $jumpTo = $rule['jump_to'] ?? null;
                $ruleNumber = $index + 1;
                if (! is_int($jumpTo) || $jumpTo <= $ruleNumber || $jumpTo > $ruleCount) {
                    throw new InvalidArgumentException("AI rule [{$index}] jump must target a later rule.");
                }
                $normalizedRule['jump_to'] = $jumpTo;
            } elseif (str_starts_with($action, 'skill:')) {
                $skillKey = substr($action, 6);
                if ($skillKey === '' || ! isset($skillKeys[$skillKey])) {
                    throw new InvalidArgumentException("AI rule [{$index}] selects an unknown player skill.");
                }
            } elseif (! in_array($action, ['normal_attack', 'defend', 'awakening'], true)) {
                throw new InvalidArgumentException("AI rule [{$index}] action is invalid.");
            }

            if (array_diff(array_keys($rule), $allowedRuleKeys) !== []) {
                throw new InvalidArgumentException("AI rule [{$index}] contains an unknown field.");
            }
            $normalized[] = $normalizedRule;
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $currentDefaultRules
     * @return list<array<string, mixed>>
     */
    public function defaultRules(array $currentDefaultRules, AlphaV1BuildCatalog $catalog): array
    {
        return $this->normalizeRules([
            [
                'conditions' => [[
                    'type' => 'own_hp_lte',
                    'percent' => intdiv(UndergroundAwakening::ACTIVATION_HP_BPS, 100),
                ]],
                'action' => 'awakening',
            ],
            ...$currentDefaultRules,
        ], $catalog);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array{schema_version: int, rules: list<array<string, mixed>>, hash: string}
     */
    public function snapshot(array $rules, AlphaV1BuildCatalog $catalog): array
    {
        $normalized = $this->normalizeRules($rules, $catalog);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'rules' => $normalized,
            'hash' => $this->hash($normalized),
        ];
    }

    /** @param list<array<string, mixed>> $rules */
    public function hash(array $rules): string
    {
        try {
            $json = json_encode(
                $rules,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('AI rules cannot be encoded.', previous: $exception);
        }

        return hash('sha256', $json);
    }

    /**
     * @return array{
     *   condition_types: list<array{key: string, label: string, value_kind: string}>,
     *   actions: list<array{key: string, label: string}>,
     *   skills: list<array{key: string, label: string, summary: string}>,
     *   statuses: list<array{key: string, label: string, max_stacks: int}>,
     *   role_stacks: list<array{key: string, label: string, max_stacks: int}>
     * }
     */
    public function editorCatalog(AlphaV1BuildCatalog $catalog): array
    {
        $manifest = $catalog->manifest();
        $statuses = [];
        foreach ($manifest['statuses'] as $key => $status) {
            if (! is_string($key) || ! is_array($status)
                || ! is_string($status['label'] ?? null) || ! is_int($status['max_stacks'] ?? null)) {
                throw new InvalidArgumentException('AI status catalog is invalid.');
            }
            $statuses[] = [
                'key' => $key,
                'label' => $status['label'],
                'max_stacks' => $status['max_stacks'],
            ];
        }

        return [
            'condition_types' => [
                ['key' => 'always', 'label' => '常に', 'value_kind' => 'none'],
                ['key' => 'own_hp_lte', 'label' => '自分のHPが指定%以下', 'value_kind' => 'percent'],
                ['key' => 'own_hp_gte', 'label' => '自分のHPが指定%以上', 'value_kind' => 'percent'],
                ['key' => 'own_mp_lte', 'label' => '自分のMPが指定%以下', 'value_kind' => 'percent'],
                ['key' => 'own_mp_gte', 'label' => '自分のMPが指定%以上', 'value_kind' => 'percent'],
                ['key' => 'enemy_hp_lte', 'label' => '敵のHPが指定%以下', 'value_kind' => 'percent'],
                ['key' => 'self_has_status', 'label' => '自分に指定状態がある', 'value_kind' => 'status'],
                ['key' => 'self_lacks_status', 'label' => '自分に指定状態がない', 'value_kind' => 'status'],
                ['key' => 'enemy_has_status', 'label' => '敵に指定状態がある', 'value_kind' => 'status'],
                ['key' => 'enemy_lacks_status', 'label' => '敵に指定状態がない', 'value_kind' => 'status'],
                ['key' => 'status_stacks_gte', 'label' => '自分の指定状態が指定stack以上', 'value_kind' => 'status_stacks'],
                ['key' => 'role_stacks_gte', 'label' => '自分のrole stackが指定数以上', 'value_kind' => 'role_stacks'],
                ['key' => 'enemy_telegraph', 'label' => '敵が強打予告中', 'value_kind' => 'none'],
                ['key' => 'skill_ready', 'label' => '指定skillが現在使用可能', 'value_kind' => 'skill'],
                ['key' => 'round_gte', 'label' => '指定round以降', 'value_kind' => 'round'],
                ['key' => 'round_modulo', 'label' => 'roundの周期条件', 'value_kind' => 'round_modulo'],
            ],
            'actions' => [
                ['key' => 'normal_attack', 'label' => '通常攻撃'],
                ['key' => 'defend', 'label' => '防御'],
                ['key' => 'awakening', 'label' => '覚醒'],
                ['key' => 'jump', 'label' => '後ろのruleへ移動'],
            ],
            'skills' => $this->playerSkills($catalog),
            'statuses' => $statuses,
            'role_stacks' => [
                ['key' => 'fighting_spirit', 'label' => '闘志', 'max_stacks' => 5],
                ['key' => 'grace', 'label' => '恩寵', 'max_stacks' => 5],
            ],
        ];
    }

    /** @return list<array{key: string, label: string, summary: string}> */
    public function playerSkills(AlphaV1BuildCatalog $catalog): array
    {
        $skills = [];
        foreach (AlphaV1CombatRules::TREES as $treeKey) {
            $nodes = $catalog->tree($treeKey)['nodes'] ?? null;
            if (! is_array($nodes)) {
                throw new InvalidArgumentException("AI skill tree [{$treeKey}] is invalid.");
            }
            foreach ($nodes as $node) {
                if (! is_array($node) || ($node['type'] ?? null) !== 'active') {
                    continue;
                }
                $skillKey = $node['skill_key'] ?? null;
                $label = $node['label'] ?? null;
                $summary = $node['summary'] ?? null;
                if (! is_string($skillKey) || ! is_string($label) || ! is_string($summary)) {
                    throw new InvalidArgumentException('AI player skill catalog is invalid.');
                }
                $catalog->skill($skillKey);
                $skills[] = ['key' => $skillKey, 'label' => $label, 'summary' => $summary];
            }
        }

        return $skills;
    }

    /**
     * @param  array<string, true>  $skillKeys
     * @return array<string, mixed>
     */
    private function normalizeCondition(
        mixed $condition,
        AlphaV1BuildCatalog $catalog,
        array $skillKeys,
    ): array {
        if (! is_array($condition) || ! is_string($condition['type'] ?? null)) {
            throw new InvalidArgumentException('AI condition is invalid.');
        }
        $type = $condition['type'];
        $normalized = ['type' => $type];
        $allowedKeys = ['type'];

        if (in_array($type, ['own_hp_lte', 'own_hp_gte', 'own_mp_lte', 'own_mp_gte', 'enemy_hp_lte'], true)) {
            $allowedKeys[] = 'percent';
            $percent = $condition['percent'] ?? null;
            if (! is_int($percent) || $percent < 0 || $percent > 100) {
                throw new InvalidArgumentException('AI percentage condition is invalid.');
            }
            $normalized['percent'] = $percent;
        } elseif (in_array($type, ['self_has_status', 'self_lacks_status', 'enemy_has_status', 'enemy_lacks_status'], true)) {
            $allowedKeys[] = 'status';
            $status = $condition['status'] ?? null;
            if (! is_string($status)) {
                throw new InvalidArgumentException('AI status condition is invalid.');
            }
            $catalog->status($status);
            $normalized['status'] = $status;
        } elseif ($type === 'status_stacks_gte') {
            $allowedKeys = ['type', 'status', 'stacks'];
            $statusKey = $condition['status'] ?? null;
            $stacks = $condition['stacks'] ?? null;
            if (! is_string($statusKey) || ! is_int($stacks)) {
                throw new InvalidArgumentException('AI status stack condition is invalid.');
            }
            $status = $catalog->status($statusKey);
            if ($stacks < 1 || ! is_int($status['max_stacks'] ?? null) || $stacks > $status['max_stacks']) {
                throw new InvalidArgumentException('AI status stack condition is invalid.');
            }
            $normalized['status'] = $statusKey;
            $normalized['stacks'] = $stacks;
        } elseif ($type === 'role_stacks_gte') {
            $allowedKeys = ['type', 'status', 'stacks'];
            $status = $condition['status'] ?? null;
            $stacks = $condition['stacks'] ?? null;
            if (! in_array($status, ['fighting_spirit', 'grace'], true)
                || ! is_int($stacks) || $stacks < 1
                || $stacks > $catalog->balanceInt('role_stack_cap')) {
                throw new InvalidArgumentException('AI role stack condition is invalid.');
            }
            $normalized['status'] = $status;
            $normalized['stacks'] = $stacks;
        } elseif ($type === 'skill_ready') {
            $allowedKeys[] = 'skill';
            $skill = $condition['skill'] ?? null;
            if (! is_string($skill) || ! isset($skillKeys[$skill])) {
                throw new InvalidArgumentException('AI skill condition is invalid.');
            }
            $normalized['skill'] = $skill;
        } elseif ($type === 'round_gte') {
            $allowedKeys[] = 'round';
            $round = $condition['round'] ?? null;
            if (! is_int($round) || $round < 1) {
                throw new InvalidArgumentException('AI round condition is invalid.');
            }
            $normalized['round'] = $round;
        } elseif ($type === 'round_modulo') {
            $allowedKeys = ['type', 'modulo', 'equals'];
            $modulo = $condition['modulo'] ?? null;
            $equals = $condition['equals'] ?? null;
            if (! is_int($modulo) || $modulo < 1 || ! is_int($equals) || $equals < 0 || $equals >= $modulo) {
                throw new InvalidArgumentException('AI round modulo condition is invalid.');
            }
            $normalized['modulo'] = $modulo;
            $normalized['equals'] = $equals;
        } elseif (! in_array($type, ['always', 'enemy_telegraph'], true)) {
            throw new InvalidArgumentException('AI condition type is invalid.');
        }

        if (array_diff(array_keys($condition), $allowedKeys) !== []) {
            throw new InvalidArgumentException('AI condition contains an unknown field.');
        }

        return $normalized;
    }
}
