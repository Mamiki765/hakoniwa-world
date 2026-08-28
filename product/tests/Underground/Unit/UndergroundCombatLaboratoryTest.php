<?php

namespace Tests\Underground\Unit;

use App\Domain\Underground\Combat\BuiltInCombatAi;
use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatEngine;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class UndergroundCombatLaboratoryTest extends TestCase
{
    /** @var list<string> */
    private const LOADOUT = [
        'quick_slash',
        'piercing_thrust',
        'mending_light',
        'crystal_burst',
    ];

    public function test_same_input_and_seed_replay_exactly_while_another_seed_can_change_legal_damage(): void
    {
        $first = $this->fight('cave_crawler', 37);
        $retry = $this->fight('cave_crawler', 37);
        $different = $this->fight('cave_crawler', 38);

        $this->assertSame($first->toArray(), $retry->toArray());
        $this->assertNotSame($first->actionLog, $different->actionLog);
        $this->assertSame(UndergroundCombatRules::IDENTITY, $first->rulesIdentity);
    }

    public function test_fixed_vector_keeps_formula_rng_and_action_order_stable(): void
    {
        $result = $this->fight('cave_crawler', 0);

        $this->assertSame([
            'winner' => 'player',
            'rounds' => 7,
            'player_hp' => 13,
            'enemy_hp' => 0,
            'damage_dealt' => 429,
            'damage_received' => 506,
            'final_resource' => 0,
            'skill_usage' => [
                'quick_slash' => 3,
                'piercing_thrust' => 0,
                'mending_light' => 1,
                'crystal_burst' => 1,
            ],
        ], [
            'winner' => $result->winner,
            'rounds' => $result->rounds,
            'player_hp' => $result->playerRemainingHp,
            'enemy_hp' => $result->enemyRemainingHp,
            'damage_dealt' => $result->damageDealt,
            'damage_received' => $result->damageReceived,
            'final_resource' => $result->finalResource,
            'skill_usage' => $result->skillUsage,
        ]);
    }

    public function test_built_in_ai_exercises_skill_cooldown_resource_and_normal_attack_fallback(): void
    {
        $result = $this->fight('stone_shell', 11);

        $this->assertGreaterThan(0, $result->skillUsage['piercing_thrust']);
        $this->assertGreaterThan(0, $result->skillUsage['quick_slash']);
        $this->assertGreaterThan(0, $result->normalAttackUsage);
        $this->assertGreaterThan(0, $result->aiFallbackUsage);
        $this->assertNotEmpty($result->resourceHistory);
        foreach ($result->resourceHistory as $row) {
            $this->assertGreaterThanOrEqual(0, $row['after']);
            $this->assertLessThanOrEqual(UndergroundCombatRules::RESOURCE_CAP, $row['after']);
        }
    }

    public function test_telegraphed_heavy_attack_causes_the_built_in_ai_to_defend(): void
    {
        $result = $this->fight('gloom_herald', 21);
        $defends = array_values(array_filter(
            $result->actionLog,
            static fn (array $row): bool => $row['side'] === 'player'
                && $row['action'] === 'defend'
                && $row['reason'] === 'enemy_telegraph',
        ));

        $this->assertGreaterThan(0, $result->defendUsage);
        $this->assertNotEmpty($defends);
        $heavyStrikes = array_values(array_filter(
            $result->actionLog,
            static fn (array $row): bool => $row['action'] === 'enemy_heavy_strike',
        ));
        $this->assertNotEmpty($heavyStrikes);
        foreach ($heavyStrikes as $heavyStrike) {
            $this->assertTrue($heavyStrike['guarded']);
        }

        $rules = new UndergroundCombatRules;
        $actor = $rules->actor('knife_initiate');
        $enemy = $rules->enemy('gloom_herald');
        $raw = intdiv($enemy['attack'] * 205, 100);
        $unmitigatedMaximum = intdiv(intdiv($raw * 100, 100 + $actor['defense']) * 105, 100);
        $guardedMaximum = intdiv($unmitigatedMaximum * UndergroundCombatRules::GUARD_DAMAGE_PERCENT, 100);
        $this->assertLessThanOrEqual($guardedMaximum, max(array_column($heavyStrikes, 'amount')));
    }

    public function test_fast_and_armored_prototypes_have_relative_scenario_semantics(): void
    {
        $rules = new UndergroundCombatRules;
        $actor = $rules->actor('knife_initiate');
        $standard = $rules->enemy('cave_crawler');
        $fast = $rules->enemy('needle_bat');
        $armored = $rules->enemy('stone_shell');

        $this->assertLessThan($actor['speed'], $standard['speed']);
        $this->assertGreaterThan($actor['speed'], $fast['speed']);
        $this->assertGreaterThan($standard['defense'], $armored['defense']);
        $this->assertGreaterThan($standard['max_hp'], $armored['max_hp']);
        $this->assertSame('player', $this->fight('cave_crawler', 9)->actionLog[0]['side']);
        $this->assertSame('enemy', $this->fight('needle_bat', 9)->actionLog[0]['side']);
    }

    public function test_max_round_is_an_explicit_stalemate_result(): void
    {
        $result = $this->engine()->fight(
            'knife_initiate',
            self::LOADOUT,
            'stone_shell',
            UndergroundCombatRules::AI_PRESET,
            3,
            1,
        );

        $this->assertSame('stalemate', $result->winner);
        $this->assertSame(1, $result->rounds);
        $this->assertGreaterThan(0, $result->playerRemainingHp);
        $this->assertGreaterThan(0, $result->enemyRemainingHp);
    }

    #[DataProvider('prototypeEnemyProvider')]
    public function test_each_prototype_enemy_exposes_its_distinct_laboratory_role(
        string $enemyKey,
        string $expectedSignal,
    ): void {
        $result = $this->fight($enemyKey, 9);
        $actions = array_column($result->actionLog, 'action');

        match ($expectedSignal) {
            'standard' => $this->assertContains('normal_attack', $actions),
            'fast_first' => $this->assertSame('enemy', $result->actionLog[0]['side']),
            'piercing' => $this->assertGreaterThan(0, $result->skillUsage['piercing_thrust']),
            'telegraph' => $this->assertContains('telegraph', $actions),
        };
        $this->assertSame([], $result->abnormalState);
    }

    /** @return array<string, array{string, string}> */
    public static function prototypeEnemyProvider(): array
    {
        return [
            'standard enemy' => ['cave_crawler', 'standard'],
            'fast fragile enemy' => ['needle_bat', 'fast_first'],
            'armored enemy' => ['stone_shell', 'piercing'],
            'telegraphed threat' => ['gloom_herald', 'telegraph'],
        ];
    }

    public function test_result_is_structured_and_compact_for_future_runtime_consumers(): void
    {
        $result = $this->fight('needle_bat', 17)->toArray();

        $this->assertSame([
            'rules_identity',
            'seed',
            'actor_key',
            'enemy_key',
            'winner',
            'rounds',
            'remaining_hp',
            'damage_dealt',
            'damage_received',
            'healing_done',
            'skill_usage',
            'normal_attack_usage',
            'defend_usage',
            'ai_fallback_usage',
            'resource_overflow',
            'final_resource',
            'resource_history',
            'abnormal_state',
            'action_log',
        ], array_keys($result));
        $this->assertLessThanOrEqual(60, count($result['action_log']));
    }

    public function test_pure_underground_domain_has_no_surface_or_database_dependency(): void
    {
        $root = dirname(__DIR__, 3).'/app/Domain/Underground';
        $contents = '';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $read = file_get_contents($file->getPathname());
                $this->assertIsString($read);
                $contents .= $read;
            }
        }

        foreach ([
            'App\\Models',
            'App\\Domain\\Turn',
            'Illuminate\\Database',
            'World',
            'Nation',
            'MapCell',
            'TurnRun',
            'current_turn',
            'hakoniwa-2s-plus-v18',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $contents);
        }
    }

    private function fight(string $enemyKey, int $seed): CombatResult
    {
        return $this->engine()->fight(
            'knife_initiate',
            self::LOADOUT,
            $enemyKey,
            UndergroundCombatRules::AI_PRESET,
            $seed,
            30,
        );
    }

    private function engine(): UndergroundCombatEngine
    {
        return new UndergroundCombatEngine(new UndergroundCombatRules, new BuiltInCombatAi);
    }
}
