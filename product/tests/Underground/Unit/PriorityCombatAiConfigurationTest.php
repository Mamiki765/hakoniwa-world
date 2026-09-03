<?php

namespace Tests\Underground\Unit;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\PriorityCombatAiConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriorityCombatAiConfigurationTest extends TestCase
{
    public function test_normalization_makes_empty_conditions_always_and_hashes_canonical_json(): void
    {
        $configuration = new PriorityCombatAiConfiguration;
        $catalog = $this->catalog();
        $rules = $configuration->normalizeRules([
            ['action' => 'skill:executioner_cut', 'conditions' => []],
            [
                'jump_to' => 3,
                'action' => 'jump',
                'conditions' => [['percent' => 50, 'type' => 'own_hp_lte']],
            ],
            ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
        ], $catalog);

        $this->assertSame([
            ['conditions' => [['type' => 'always']], 'action' => 'skill:executioner_cut'],
            [
                'conditions' => [['type' => 'own_hp_lte', 'percent' => 50]],
                'action' => 'jump',
                'jump_to' => 3,
            ],
            ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
        ], $rules);
        $this->assertSame(
            $configuration->hash($rules),
            $configuration->hash($configuration->normalizeRules($rules, $catalog)),
        );
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $configuration->hash($rules));
    }

    public function test_default_preset_places_hp_twenty_awakening_before_existing_behavior(): void
    {
        $configuration = new PriorityCombatAiConfiguration;
        $rules = $configuration->defaultRules([
            ['conditions' => [['type' => 'enemy_telegraph']], 'action' => 'defend'],
            ['conditions' => [['type' => 'own_hp_lte', 'percent' => 20]], 'action' => 'defend'],
            ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
        ], $this->catalog());

        $this->assertSame([
            'conditions' => [['type' => 'own_hp_lte', 'percent' => 20]],
            'action' => 'awakening',
        ], $rules[0]);
        $this->assertSame('defend', $rules[2]['action']);
        $this->assertSame('normal_attack', $rules[3]['action']);
    }

    public function test_default_player_skill_rules_do_not_repeat_their_action_availability_as_a_condition(): void
    {
        $config = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $rules = $config['exploration']['player_skill_ai_rules'];

        $this->assertIsArray($rules);
        foreach ($rules as $rule) {
            $this->assertIsArray($rule);
            $action = $rule['action'] ?? null;
            $conditions = $rule['conditions'] ?? null;
            $this->assertIsString($action);
            $this->assertStringStartsWith('skill:', $action);
            $this->assertIsArray($conditions);

            $actionSkill = substr($action, 6);
            $this->assertNotContains(
                ['type' => 'skill_ready', 'skill' => $actionSkill],
                $conditions,
                "Default rule for {$actionSkill} must let the action attempt decide availability.",
            );
        }

        $this->assertSame(
            [['type' => 'own_hp_lte', 'percent' => 55]],
            $rules[0]['conditions'],
        );
        $this->assertSame(
            [['type' => 'always']],
            $rules[8]['conditions'],
        );
    }

    public function test_normalization_canonicalizes_commutative_and_condition_order(): void
    {
        $configuration = new PriorityCombatAiConfiguration;
        $catalog = $this->catalog();
        $first = $configuration->normalizeRules([[
            'conditions' => [
                ['type' => 'skill_ready', 'skill' => 'mending_prayer'],
                ['type' => 'own_hp_lte', 'percent' => 55],
            ],
            'action' => 'skill:mending_prayer',
        ]], $catalog);
        $second = $configuration->normalizeRules([[
            'conditions' => [
                ['percent' => 55, 'type' => 'own_hp_lte'],
                ['skill' => 'mending_prayer', 'type' => 'skill_ready'],
            ],
            'action' => 'skill:mending_prayer',
        ]], $catalog);

        $this->assertSame($first, $second);
        $this->assertSame($configuration->hash($first), $configuration->hash($second));
    }

    /** @param list<mixed> $rules */
    #[DataProvider('invalidRules')]
    public function test_rejects_noncanonical_or_unsafe_rules(array $rules): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PriorityCombatAiConfiguration)->normalizeRules($rules, $this->catalog());
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function invalidRules(): iterable
    {
        yield 'more than sixteen rules' => [array_fill(0, 17, [
            'conditions' => [],
            'action' => 'normal_attack',
        ])];
        yield 'more than two conditions' => [[[
            'conditions' => [
                ['type' => 'own_hp_lte', 'percent' => 50],
                ['type' => 'own_mp_lte', 'percent' => 50],
                ['type' => 'enemy_hp_lte', 'percent' => 50],
            ],
            'action' => 'defend',
        ]]];
        yield 'always combined with another condition' => [[[
            'conditions' => [['type' => 'always'], ['type' => 'enemy_telegraph']],
            'action' => 'defend',
        ]]];
        yield 'backward jump' => [[
            ['conditions' => [], 'action' => 'normal_attack'],
            ['conditions' => [], 'action' => 'jump', 'jump_to' => 1],
        ]];
        yield 'jump beyond rules' => [[
            ['conditions' => [], 'action' => 'jump', 'jump_to' => 2],
        ]];
        yield 'enemy-only skill' => [[[
            'conditions' => [],
            'action' => 'skill:enemy_telegraph',
        ]]];
        yield 'enemy-only skill condition' => [[[
            'conditions' => [['type' => 'skill_ready', 'skill' => 'enemy_telegraph']],
            'action' => 'defend',
        ]]];
        yield 'unknown field' => [[[
            'conditions' => [],
            'action' => 'defend',
            'note' => 'ignored fields must not enter the snapshot identity',
        ]]];
        yield 'impossible percent' => [[[
            'conditions' => [['type' => 'own_hp_lte', 'percent' => 101]],
            'action' => 'defend',
        ]]];
        yield 'invalid modulo remainder' => [[[
            'conditions' => [['type' => 'round_modulo', 'modulo' => 3, 'equals' => 3]],
            'action' => 'defend',
        ]]];
    }

    public function test_editor_catalog_contains_only_player_skill_tree_actions(): void
    {
        $catalog = (new PriorityCombatAiConfiguration)->editorCatalog($this->catalog());

        $this->assertCount(16, $catalog['skills']);
        $this->assertSame('precision_cut', $catalog['skills'][0]['key']);
        $this->assertSame('radiant_judgment', $catalog['skills'][15]['key']);
        $this->assertNotContains('enemy_telegraph', array_column($catalog['skills'], 'key'));
    }

    private function catalog(): AlphaV1BuildCatalog
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/config/underground/balance/foundation-v1.json');
        $this->assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return new AlphaV1BuildCatalog($manifest);
    }
}
