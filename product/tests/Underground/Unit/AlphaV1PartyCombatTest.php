<?php

namespace Tests\Underground\Unit;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\CanonicalCombatOrchestrator;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\PriorityCombatAi;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
use PHPUnit\Framework\TestCase;

final class AlphaV1PartyCombatTest extends TestCase
{
    public function test_heavenrend_logs_multi_enemy_identity_with_full_primary_and_half_secondary_potency(): void
    {
        $catalog = $this->catalog(enemyHp: 10_000_000, enemyPower: 1, enemyAgility: 1);
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$this->player('secretary:1', awakening: true)],
            ['party_target', 'party_target'],
            380,
            1,
            0,
        );

        $technique = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'awakening_technique',
        );
        self::assertIsArray($technique);
        self::assertSame('secretary:1', $technique['actor_id']);
        self::assertSame(['enemy:1', 'enemy:2'], $technique['target_ids']);
        $damage = collect($result->actionLog)
            ->filter(static fn (array $row): bool => ($row['action'] ?? null) === 'decisive_heavenrend'
                && ($row['effect_type'] ?? null) === 'damage')
            ->groupBy('target_id');
        self::assertSame(['enemy:1', 'enemy:2'], $damage->keys()->all());
        self::assertSame($damage['enemy:1']->count(), $damage['enemy:2']->count());
        self::assertGreaterThan(0, $damage['enemy:1']->sum('amount'));
        self::assertGreaterThan(0, $damage['enemy:2']->sum('amount'));
        self::assertLessThan($damage['enemy:1']->sum('amount'), $damage['enemy:2']->sum('amount'));
        self::assertGreaterThanOrEqual(
            (int) floor($damage['enemy:1']->sum('amount') * 0.4),
            $damage['enemy:2']->sum('amount'),
        );
        self::assertLessThanOrEqual(
            (int) ceil($damage['enemy:1']->sum('amount') * 0.6),
            $damage['enemy:2']->sum('amount'),
        );
    }

    public function test_winner_uses_team_survival_instead_of_leader_survival(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 500_000, enemyAgility: 1_000);
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [
                $this->player('secretary:1', currentHp: 1, defend: true),
                $this->player('borrowed:2', currentHp: 1, defend: true),
            ],
            ['party_target'],
            381,
            1,
            0,
        );

        self::assertSame(0, $result->finalStates['secretary:1']['hp']);
        self::assertGreaterThan(0, $result->finalStates['borrowed:2']['hp']);
        self::assertSame('stalemate', $result->winner);

        $defeat = $this->model()->fightPartySnapshots(
            $catalog,
            [
                $this->player('secretary:1', currentHp: 1, defend: true),
                $this->player('borrowed:2', currentHp: 1, defend: true),
            ],
            ['party_target'],
            381,
            2,
            0,
        );
        self::assertSame('enemy', $defeat->winner);
    }

    public function test_content_authored_all_enemy_scope_hits_each_opposing_combatant_with_explicit_ids(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 500_000, enemyAgility: 1_000, enemyAoe: true);
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [
                $this->player('secretary:1', currentHp: 1, defend: true),
                $this->player('borrowed:2', currentHp: 1, defend: true),
            ],
            ['party_target'],
            382,
            1,
            0,
        );

        $damage = collect($result->actionLog)->filter(
            static fn (array $row): bool => ($row['actor_id'] ?? null) === 'enemy:1'
                && ($row['action'] ?? null) === 'party_wave'
                && ($row['effect_type'] ?? null) === 'damage',
        );
        self::assertSame(['secretary:1', 'borrowed:2'], $damage->pluck('target_id')->all());
        self::assertSame([
            ['secretary:1', 'borrowed:2'],
            ['secretary:1', 'borrowed:2'],
        ], $damage->pluck('target_ids')->all());
        self::assertSame(['all_enemies', 'all_enemies'], $damage->pluck('target_scope')->all());
        self::assertSame(0, $result->finalStates['secretary:1']['hp']);
        self::assertSame(0, $result->finalStates['borrowed:2']['hp']);
        self::assertSame('enemy', $result->winner);
    }

    public function test_healer_targets_the_lowest_hp_ally_while_non_healer_healing_remains_self_only(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 1, enemyAgility: 1);
        $healer = $this->player('borrowed:2', currentHp: 100);
        $healer['active_skills'] = ['mending_prayer'];
        $healer['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:mending_prayer',
        ]];
        $healer['party_healing_target_scope'] = 'single_ally';
        $healerResult = $this->model()->fightPartySnapshots(
            $catalog,
            [$this->player('secretary:1', currentHp: 1, defend: true), $healer],
            ['party_target'],
            383,
            1,
            0,
        );
        $healerLog = collect($healerResult->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'mending_prayer'
                && ($row['effect_type'] ?? null) === 'recovery',
        );
        self::assertIsArray($healerLog);
        self::assertSame('borrowed:2', $healerLog['actor_id']);
        self::assertSame('secretary:1', $healerLog['target_id']);
        self::assertSame('single_ally', $healerLog['target_scope']);

        $attacker = $this->player('secretary:3', currentHp: 1);
        $attacker['active_skills'] = ['mending_prayer'];
        $attacker['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:mending_prayer',
        ]];
        $attacker['party_healing_target_scope'] = 'self';
        $attackerResult = $this->model()->fightPartySnapshots(
            $catalog,
            [$attacker, $this->player('borrowed:4', currentHp: 1, defend: true)],
            ['party_target'],
            384,
            1,
            0,
        );
        $attackerLog = collect($attackerResult->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'mending_prayer'
                && ($row['effect_type'] ?? null) === 'recovery',
        );
        self::assertIsArray($attackerLog);
        self::assertSame('secretary:3', $attackerLog['target_id']);
        self::assertSame('self', $attackerLog['target_scope']);
    }

    public function test_healer_awakening_revives_every_defeated_ally_at_full_hp(): void
    {
        $catalog = $this->catalog(enemyHp: 10_000_000, enemyPower: 500_000, enemyAgility: 1_000);
        $healer = $this->player('borrowed:2', currentHp: 1, awakening: true);
        $healer['awakening']['growth_path'] = 'blessing_green';
        $healer['awakening']['technique_key'] = 'life_requiem';
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$this->player('secretary:1', currentHp: 1, defend: true), $healer],
            ['party_target'],
            385,
            1,
            0,
        );

        $revive = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'life_requiem'
                && ($row['effect_type'] ?? null) === 'recovery'
                && ($row['target_id'] ?? null) === 'secretary:1',
        );
        self::assertIsArray($revive);
        self::assertTrue($revive['revived']);
        self::assertSame(10_000, $revive['revive_hp_bps']);
        self::assertSame(
            $result->finalStates['secretary:1']['max_hp'],
            $result->finalStates['secretary:1']['hp'],
        );
    }

    private function catalog(int $enemyHp, int $enemyPower, int $enemyAgility, bool $enemyAoe = false): AlphaV1BuildCatalog
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/config/underground/balance/foundation-v1.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $manifest['enemies']['party_target'] = [
            'label' => 'PT試験体',
            'boss' => false,
            'base_stats' => [
                'vitality' => 10,
                'might' => 80,
                'finesse' => 5,
                'spirit' => 5,
                'agility' => $enemyAgility,
            ],
            'max_hp' => $enemyHp,
            'physical_defense' => 100,
            'magical_defense' => 100,
            'weapon_power' => $enemyPower,
            'normal_attack' => [
                'type' => 'damage',
                'category' => 'physical',
                'potency_bps' => 10_000,
                'stat_coefficients' => ['might' => 10_000],
                'weapon_coefficient_bps' => 10_000,
                'fixed' => 0,
                'target_max_hp_bps' => 0,
                'can_crit' => false,
                'dodgeable' => false,
                'hits' => 1,
            ],
            'skills' => $enemyAoe ? ['party_wave'] : [],
            'ai_rules' => [[
                'conditions' => [['type' => 'always']],
                'action' => $enemyAoe ? 'skill:party_wave' : 'normal_attack',
            ]],
            'modifiers' => [],
        ];
        if ($enemyAoe) {
            $manifest['skills']['party_wave'] = [
                'label' => '全体攻撃',
                'mp_cost' => 0,
                'cooldown' => 0,
                'effects' => [[
                    'type' => 'damage',
                    'target_scope' => 'all_enemies',
                    'category' => 'physical',
                    'potency_bps' => 10_000,
                    'stat_coefficients' => ['might' => 10_000],
                    'weapon_coefficient_bps' => 10_000,
                    'fixed' => 0,
                    'target_max_hp_bps' => 0,
                    'can_crit' => false,
                    'dodgeable' => false,
                    'hits' => 1,
                ]],
            ];
        }

        return new AlphaV1BuildCatalog($manifest);
    }

    /** @return array<string, mixed> */
    private function player(
        string $combatantId,
        int $currentHp = 1,
        bool $awakening = false,
        bool $defend = false,
    ): array {
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $rules = $awakening
            ? [
                ['conditions' => [['type' => 'own_hp_lte', 'percent' => 20]], 'action' => 'awakening'],
                ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
            ]
            : [['conditions' => [['type' => 'always']], 'action' => $defend ? 'defend' : 'normal_attack']];

        return [
            'combatant_id' => $combatantId,
            'key' => 'party_secretary',
            'label' => $combatantId,
            'stats' => ['vitality' => 100, 'might' => 40, 'finesse' => 1, 'spirit' => 40, 'agility' => 200],
            'active_skills' => [],
            'ai_rules' => $rules,
            'modifiers' => [],
            'equipment' => $configuration['exploration']['starter_weapon'],
            'current_hp' => $currentHp,
            'natural_recovery' => 0,
            'awakening' => [
                'unlocked' => $awakening,
                'gauge' => $awakening ? UndergroundAwakening::GAUGE_MAX : 0,
                'message' => '覚醒する。',
                'growth_path' => 'martial_red',
                'technique_key' => 'decisive_heavenrend',
            ],
        ];
    }

    private function model(): AlphaV1CombatModel
    {
        $rules = new AlphaV1CombatRules;

        return new AlphaV1CombatModel(
            $rules,
            new UndergroundBuildValidator($rules),
            new DeterministicEquipmentGenerator($rules),
            new PriorityCombatAi,
            new CanonicalCombatOrchestrator,
            new UndergroundAwakening,
        );
    }
}
