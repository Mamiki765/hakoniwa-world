<?php

namespace Tests\Underground\Unit;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatState;
use App\Domain\Underground\Combat\CanonicalCombatOrchestrator;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\PriorityCombatAi;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Domain\Underground\Combat\UndergroundBuildValidator;
use PHPUnit\Framework\TestCase;

final class AlphaV1PartyCombatTest extends TestCase
{
    public function test_guide_duel_forces_every_member_to_full_awakening_before_the_opening_ultimate(): void
    {
        $manifest = $this->catalog(1, 1, 1)->manifest();
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $manifest['enemies']['dream_queen'] = $configuration['guide_duel']['enemy'];
        $players = [$this->player('secretary:1'), $this->player('borrowed:2', currentHp: 0)];
        foreach ($players as &$player) {
            $player['stats']['vitality'] = 100_000;
            $player['stats']['agility'] = 1_000;
            $player['stats']['might'] = 3_000;
        }
        unset($player);
        $result = $this->model()->fightPartySnapshots(new AlphaV1BuildCatalog($manifest), $players, ['dream_queen'], 4100, 2, 0);
        $rows = collect($result->actionLog);
        $awakenings = $rows->where('kind', 'awakening');
        self::assertCount(2, $awakenings);
        foreach ($awakenings as $row) {
            self::assertSame($row['state']['max_hp'], $row['state']['hp']);
            self::assertSame(AlphaV1CombatRules::MAX_MP, $row['state']['mp']);
            self::assertSame(
                $row['state']['hp'] - $result->initialStates[$row['actor_id']]['hp'],
                $row['effective_healing'],
            );
        }
        $opening = $rows->where('action_id', 'guide-duel:1:opening')->where('effect_type', 'damage');
        self::assertGreaterThanOrEqual(30, $opening->count());
        self::assertLessThanOrEqual(50, $opening->count());
        self::assertCount(1, $opening->pluck('target_id')->unique());
        self::assertLessThan($opening->keys()->first(), $awakenings->keys()->last());
        self::assertGreaterThan($opening->keys()->last(), $rows->where('kind', 'awakening_technique')->keys()->first());
        $regeneration = $rows->firstWhere('action', 'guide_regeneration');
        self::assertSame('enemy:1', $regeneration['actor_id']);
        self::assertSame('enemy:1', $regeneration['target_id']);
        self::assertSame(-1254, $regeneration['amount']);
    }

    public function test_guide_duel_survives_a_lethal_multihit_action_then_interrupts_once_and_can_die(): void
    {
        $manifest = $this->catalog(1, 1, 1)->manifest();
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $enemy = $configuration['guide_duel']['enemy'];
        $enemy['max_hp'] = 1000;
        $enemy['guide_duel']['heal_per_round'] = 0;
        $manifest['enemies']['dream_queen'] = $enemy;
        $player = $this->player('secretary:1');
        $player['stats']['vitality'] = 100_000;
        $player['stats']['might'] = 100_000;
        $player['stats']['agility'] = 10_000;
        $player['awakening']['technique_key'] = 'absolute_aegis';
        $player['awakening']['growth_path'] = 'guardianship_blue';
        $player['active_skills'] = ['dagger_flurry'];
        $player['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'skill:dagger_flurry']];
        $result = $this->model()->fightPartySnapshots(new AlphaV1BuildCatalog($manifest), [$player], ['dream_queen'], 4100, 4, 0);
        self::assertSame('player', $result->winner);
        $rows = collect($result->actionLog);
        $ultimateActions = $rows->where('action', 'guide_ultimate')->pluck('action_id')->unique()->values()->all();
        self::assertSame(['guide-duel:1:opening', 'guide-duel:2:threshold'], $ultimateActions);
        $trigger = $rows->where('round', 2)->where('action', 'dagger_flurry')->where('effect_type', 'damage');
        self::assertGreaterThan(1, $trigger->count());
        $second = $rows->where('action_id', 'guide-duel:2:threshold')->where('effect_type', 'damage');
        self::assertGreaterThan($trigger->keys()->last(), $second->keys()->first());
        self::assertSame(1, $second->first()['actor_hp']);
        self::assertSame(0, $result->finalStates['enemy:1']['hp']);
    }

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
        foreach ($damage as $targetId => $rows) {
            self::assertSame([[strval($targetId)]], $rows->pluck('target_ids')->unique()->values()->all());
        }
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
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 500_000, enemyAgility: 1_000, enemyBoss: true);
        $leader = $this->player('secretary:1', currentHp: 1);
        $leader['stats']['agility'] = 2_000;
        $leader['active_skills'] = ['bulwark_strike'];
        $leader['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:bulwark_strike',
        ]];
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [
                $leader,
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
                $leader,
                $this->player('borrowed:2', currentHp: 1, defend: true),
            ],
            ['party_target'],
            381,
            2,
            0,
        );
        self::assertSame('enemy', $defeat->winner);
    }

    public function test_untaunted_enemy_single_targets_are_retry_stable_and_can_reach_multiple_living_members(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 1, enemyAgility: 1_000);
        $fight = fn (int $seed) => $this->model()->fightPartySnapshots(
            $catalog,
            [
                $this->player('secretary:1', currentHp: 1_000, defend: true),
                $this->player('borrowed:2', currentHp: 1_000, defend: true),
            ],
            ['party_target'],
            $seed,
            8,
            0,
        );
        $targets = static fn ($result): array => collect($result->actionLog)
            ->filter(static fn (array $row): bool => ($row['actor_id'] ?? null) === 'enemy:1'
                && ($row['effect_type'] ?? null) === 'damage')
            ->pluck('target_id')
            ->all();

        $first = $targets($fight(383));
        self::assertSame($first, $targets($fight(383)));
        self::assertCount(2, array_unique($first));
    }

    public function test_content_authored_all_enemy_scope_hits_each_opposing_combatant_with_explicit_ids(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 500_000, enemyAgility: 1_000, enemyAoe: true);
        $taunter = $this->player('secretary:1', currentHp: 1);
        $taunter['stats']['agility'] = 2_000;
        $taunter['active_skills'] = ['bulwark_strike'];
        $taunter['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:bulwark_strike',
        ]];
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [
                $taunter,
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
            ['secretary:1'],
            ['borrowed:2'],
        ], $damage->pluck('target_ids')->all());
        self::assertSame(['all_enemies', 'all_enemies'], $damage->pluck('target_scope')->all());
        self::assertSame(0, $result->finalStates['secretary:1']['hp']);
        self::assertSame(0, $result->finalStates['borrowed:2']['hp']);
        self::assertSame('enemy', $result->winner);
    }

    public function test_mending_prayer_targets_the_lowest_hp_ally_while_renewing_guard_remains_self_only(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 1, enemyAgility: 1);
        $healer = $this->player('borrowed:2', currentHp: 100);
        $healer['active_skills'] = ['mending_prayer'];
        $healer['ai_rules'] = [[
            'conditions' => [['type' => 'ally_hp_lte', 'percent' => 50]],
            'action' => 'skill:mending_prayer',
            'target' => 'lowest_hp_ally',
        ], [
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
        ]];
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
        self::assertIsString($healerLog['action_id'] ?? null);
        $healerDecision = collect($healerResult->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['actor_id'] ?? null) === 'borrowed:2'
                && ($row['action_key'] ?? null) === 'mending_prayer',
        );
        self::assertIsArray($healerDecision);
        self::assertSame('secretary:1', $healerDecision['target_id']);
        self::assertSame($healerDecision['action_id'], $healerLog['action_id']);
        $healerCost = collect($healerResult->actionLog)->first(
            static fn (array $row): bool => ($row['effect_type'] ?? null) === 'mp_cost'
                && ($row['actor_id'] ?? null) === 'borrowed:2',
        );
        self::assertIsArray($healerCost);
        self::assertSame($healerDecision['action_id'], $healerCost['action_id']);

        $guard = $this->player('secretary:3', currentHp: 100);
        $guard['active_skills'] = ['renewing_guard'];
        $guard['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:renewing_guard',
        ]];
        $guardResult = $this->model()->fightPartySnapshots(
            $catalog,
            [$guard, $this->player('borrowed:4', currentHp: 1, defend: true)],
            ['party_target'],
            384,
            1,
            0,
        );
        $guardRecovery = collect($guardResult->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'renewing_guard'
                && ($row['effect_type'] ?? null) === 'recovery',
        );
        $guardBarrier = collect($guardResult->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'renewing_guard'
                && ($row['effect_type'] ?? null) === 'barrier',
        );
        self::assertIsArray($guardRecovery);
        self::assertIsArray($guardBarrier);
        self::assertSame('secretary:3', $guardRecovery['target_id']);
        self::assertSame('self', $guardRecovery['target_scope']);
        self::assertSame('secretary:3', $guardBarrier['target_id']);
        self::assertSame('self', $guardBarrier['target_scope']);
    }

    public function test_ally_targeting_keeps_enemy_conditions_bound_to_the_current_enemy(): void
    {
        $catalog = $this->catalog(enemyHp: 100, enemyPower: 1, enemyAgility: 1);
        $healer = $this->combatState(
            'player',
            'borrowed:2',
            100,
            ['mending_prayer'],
            [[
                'conditions' => [
                    ['type' => 'enemy_telegraph'],
                    ['type' => 'ally_hp_lte', 'percent' => 50],
                    ['type' => 'skill_ready', 'skill' => 'mending_prayer'],
                ],
                'action' => 'skill:mending_prayer',
                'target' => 'lowest_hp_ally',
            ], [
                'conditions' => [['type' => 'always']],
                'action' => 'normal_attack',
            ]],
        );
        $ally = $this->combatState('player', 'secretary:1', 10);
        $enemy = $this->combatState('enemy', 'enemy:1', 100);
        $enemy->statuses['telegraph'] = [
            'key' => 'telegraph',
            'disposition' => 'buff',
            'remaining' => 1,
            'applied_round' => 1,
            'stacks' => 1,
            'effects' => [],
            'control' => false,
            'dispellable' => true,
        ];
        $ai = new PriorityCombatAi;

        $decision = $ai->select($healer, $enemy, $catalog, 1, allies: [$healer, $ally], enemies: [$enemy]);
        self::assertSame('skill', $decision['type']);
        self::assertSame('mending_prayer', $decision['key']);
        self::assertSame('secretary:1', $decision['target_id']);

        $healer->mp = 0;
        $blockedDecision = $ai->select($healer, $enemy, $catalog, 1, allies: [$healer, $ally], enemies: [$enemy]);
        self::assertSame('normal_attack', $blockedDecision['type']);
        self::assertTrue($blockedDecision['mp_blocked']);
    }

    public function test_solo_ally_hp_condition_includes_the_actor_for_saved_party_healing_rules(): void
    {
        $catalog = $this->catalog(enemyHp: 100, enemyPower: 1, enemyAgility: 1);
        $healer = $this->combatState(
            'player',
            'secretary:1',
            10,
            ['mending_prayer'],
            [[
                'conditions' => [['type' => 'ally_hp_lte', 'percent' => 50]],
                'action' => 'skill:mending_prayer',
                'target' => 'lowest_hp_ally',
            ], [
                'conditions' => [['type' => 'always']],
                'action' => 'normal_attack',
            ]],
        );
        $enemy = $this->combatState('enemy', 'enemy:1', 100);
        $ai = new PriorityCombatAi;

        $lowHp = $ai->select($healer, $enemy, $catalog, 1);
        self::assertSame('mending_prayer', $lowHp['key']);
        self::assertSame('secretary:1', $lowHp['target_id']);

        $healer->hp = 60;
        $healthy = $ai->select($healer, $enemy, $catalog, 1);
        self::assertSame('normal_attack', $healthy['type']);
    }

    public function test_untaunted_target_rules_bind_taunts_and_enemy_attacks_and_skip_when_no_candidate_remains(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 1, enemyAgility: 1, enemyBoss: true);
        $firstTank = $this->player('secretary:1', currentHp: 1);
        $firstTank['stats']['agility'] = 400;
        $firstTank['active_skills'] = ['bulwark_strike'];
        $firstTank['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:bulwark_strike',
        ]];
        $secondTank = $this->player('borrowed:2', currentHp: 1_000);
        $secondTank['stats']['agility'] = 300;
        $secondTank['active_skills'] = ['bulwark_strike'];
        $secondTank['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:bulwark_strike',
            'target' => 'untaunted_enemy',
        ], [
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
            'target' => 'untaunted_enemy',
        ]];
        $fallback = $this->player('borrowed:3', currentHp: 1_000);
        $fallback['stats']['agility'] = 200;
        $fallback['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
            'target' => 'untaunted_enemy',
        ], [
            'conditions' => [['type' => 'always']],
            'action' => 'defend',
        ]];

        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$firstTank, $secondTank, $fallback],
            ['party_target', 'party_target'],
            390,
            2,
            0,
        );

        $taunts = collect($result->actionLog)
            ->filter(static fn (array $row): bool => ($row['effect_type'] ?? null) === 'taunt_applied');
        self::assertSame(['enemy:1'], $taunts->where('actor_id', 'secretary:1')->pluck('target_id')->all());
        self::assertSame(['enemy:2'], $taunts->where('actor_id', 'borrowed:2')->pluck('target_id')->all());
        $enemyDamage = collect($result->actionLog)
            ->filter(static fn (array $row): bool => ($row['effect_type'] ?? null) === 'damage'
                && ($row['round'] ?? null) === 1
                && str_starts_with((string) ($row['actor_id'] ?? ''), 'enemy:'))
            ->mapWithKeys(static fn (array $row): array => [$row['actor_id'] => $row['target_id']]);
        self::assertSame('secretary:1', $enemyDamage['enemy:1']);
        self::assertSame('borrowed:2', $enemyDamage['enemy:2']);
        $deadTaunterFallback = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['effect_type'] ?? null) === 'damage'
                && ($row['round'] ?? null) === 2
                && ($row['actor_id'] ?? null) === 'enemy:1',
        );
        self::assertIsArray($deadTaunterFallback);
        self::assertNotSame('secretary:1', $deadTaunterFallback['target_id']);
        self::assertContains($deadTaunterFallback['target_id'], ['borrowed:2', 'borrowed:3']);
        $secondRoundTarget = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['round'] ?? null) === 2
                && ($row['actor_id'] ?? null) === 'borrowed:2',
        );
        self::assertIsArray($secondRoundTarget);
        self::assertSame('normal_attack', $secondRoundTarget['action_key']);
        self::assertSame('enemy:1', $secondRoundTarget['target_id']);
        $fallbackDecision = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['actor_id'] ?? null) === 'borrowed:3',
        );
        self::assertIsArray($fallbackDecision);
        self::assertSame('defend', $fallbackDecision['action_key']);
    }

    public function test_standard_party_healer_uses_ally_hp_condition_when_own_hp_is_high(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 1, enemyAgility: 1);
        $healer = $this->player('borrowed:2', currentHp: 1_100);
        $healer['active_skills'] = ['mending_prayer'];
        $healer['ai_mode'] = 'default';
        $healer['ai_rules'] = [[
            'conditions' => [
                ['type' => 'own_hp_lte', 'percent' => 55],
                ['type' => 'skill_ready', 'skill' => 'mending_prayer'],
            ],
            'action' => 'skill:mending_prayer',
        ], [
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
        ]];
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$this->player('secretary:1', currentHp: 1), $healer],
            ['party_target'],
            387,
            1,
            0,
        );

        $decision = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['actor_id'] ?? null) === 'borrowed:2',
        );
        self::assertIsArray($decision);
        self::assertSame('mending_prayer', $decision['action_key']);
        self::assertSame('secretary:1', $decision['target_id']);
        $recovery = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'mending_prayer'
                && ($row['effect_type'] ?? null) === 'recovery',
        );
        self::assertIsArray($recovery);
        self::assertSame('secretary:1', $recovery['target_id']);
    }

    public function test_standard_party_healer_uses_ally_hp_condition_to_heal_self_when_lowest(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 1, enemyAgility: 1);
        $healer = $this->player('borrowed:2', currentHp: 1);
        $healer['active_skills'] = ['mending_prayer'];
        $healer['ai_mode'] = 'default';
        $healer['ai_rules'] = [[
            'conditions' => [
                ['type' => 'own_hp_lte', 'percent' => 55],
                ['type' => 'skill_ready', 'skill' => 'mending_prayer'],
            ],
            'action' => 'skill:mending_prayer',
        ], [
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
        ]];
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$this->player('secretary:1', currentHp: 1_100), $healer],
            ['party_target'],
            388,
            1,
            0,
        );

        $decision = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['actor_id'] ?? null) === 'borrowed:2',
        );
        self::assertIsArray($decision);
        self::assertSame('mending_prayer', $decision['action_key']);
        self::assertSame('borrowed:2', $decision['target_id']);
    }

    public function test_standard_party_healer_skips_ally_heal_when_everyone_is_above_threshold(): void
    {
        $catalog = $this->catalog(enemyHp: 1_000_000, enemyPower: 1, enemyAgility: 1);
        $healer = $this->player('borrowed:2', currentHp: 1_100);
        $healer['active_skills'] = ['mending_prayer'];
        $healer['ai_mode'] = 'default';
        $healer['ai_rules'] = [[
            'conditions' => [
                ['type' => 'own_hp_lte', 'percent' => 55],
                ['type' => 'skill_ready', 'skill' => 'mending_prayer'],
            ],
            'action' => 'skill:mending_prayer',
        ], [
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
        ]];
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$this->player('secretary:1', currentHp: 1_100), $healer],
            ['party_target'],
            389,
            1,
            0,
        );

        $decision = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['kind'] ?? null) === 'decision'
                && ($row['actor_id'] ?? null) === 'borrowed:2',
        );
        self::assertIsArray($decision);
        self::assertSame('normal_attack', $decision['action_key']);
    }

    public function test_healer_awakening_revives_every_defeated_ally_at_full_hp(): void
    {
        $catalog = $this->catalog(enemyHp: 10_000_000, enemyPower: 500_000, enemyAgility: 1_000, enemyBoss: true);
        $healer = $this->player('borrowed:2', currentHp: 1, awakening: true);
        $healer['awakening']['growth_path'] = 'blessing_green';
        $healer['awakening']['technique_key'] = 'life_requiem';
        $leader = $this->player('secretary:1', currentHp: 1);
        $leader['stats']['agility'] = 2_000;
        $leader['active_skills'] = ['bulwark_strike'];
        $leader['ai_rules'] = [[
            'conditions' => [['type' => 'always']],
            'action' => 'skill:bulwark_strike',
        ]];
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$leader, $healer],
            ['party_target'],
            385,
            1,
            0,
        );

        $revive = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'life_requiem'
                && ($row['kind'] ?? null) === 'revival'
                && ($row['effect_type'] ?? null) === 'revival'
                && ($row['target_id'] ?? null) === 'secretary:1',
        );
        self::assertIsArray($revive);
        self::assertSame('revival', $revive['kind']);
        self::assertTrue($revive['revived']);
        self::assertSame(10_000, $revive['revive_hp_bps']);
        self::assertSame(
            $result->finalStates['secretary:1']['max_hp'],
            $result->finalStates['secretary:1']['hp'],
        );
    }

    public function test_party_counter_and_lifesteal_keep_their_actual_target_identity_and_action_id(): void
    {
        $catalog = $this->catalog(
            enemyHp: 10_000_000,
            enemyPower: 1,
            enemyAgility: 1,
            enemyCounter: true,
        );
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$this->player('secretary:1', modifiers: ['lifesteal_bps' => 5_000])],
            ['party_target'],
            386,
            2,
            0,
        );

        $counter = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['effect_type'] ?? null) === 'counter',
        );
        self::assertIsArray($counter);
        self::assertSame('enemy:1', $counter['actor_id']);
        self::assertSame('secretary:1', $counter['target_id']);
        self::assertSame(['secretary:1'], $counter['target_ids']);
        self::assertIsString($counter['action_id'] ?? null);

        $lifesteal = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['action'] ?? null) === 'lifesteal',
        );
        self::assertIsArray($lifesteal);
        self::assertSame('secretary:1', $lifesteal['actor_id']);
        self::assertSame('secretary:1', $lifesteal['target_id']);
        self::assertSame(['secretary:1'], $lifesteal['target_ids']);
        self::assertIsString($lifesteal['action_id'] ?? null);
    }

    public function test_counter_stance_counters_each_attacker_once_per_round_even_against_multiple_hits(): void
    {
        $manifest = $this->catalog(10_000_000, 1, 1)->manifest();
        $manifest['enemies']['party_target']['normal_attack']['hits'] = 3;
        $manifest['enemies']['party_target']['normal_attack']['stat_coefficients'] = [];
        $manifest['enemies']['party_target']['normal_attack']['fixed'] = 1;
        $manifest['skills']['counter_stance']['effects'][1]['fixed'] = 100_000;
        $catalog = new AlphaV1BuildCatalog($manifest);
        $player = $this->player('secretary:1', currentHp: 1000);
        $player['active_skills'] = ['counter_stance'];
        $player['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'skill:counter_stance']];
        $result = $this->model()->fightPartySnapshots($catalog, [$player], array_fill(0, 4, 'party_target'), 3100, 2, 0);
        $counters = collect($result->actionLog)->where('actor_id', 'secretary:1')->where('effect_type', 'counter');
        foreach ([1, 2] as $round) {
            self::assertSame(4, $counters->where('round', $round)->count());
            self::assertSame(['enemy:1', 'enemy:2', 'enemy:3', 'enemy:4'], $counters->where('round', $round)->pluck('target_id')->sort()->values()->all());
        }
    }

    public function test_round_end_self_regeneration_keeps_the_acting_combatant_as_its_party_target(): void
    {
        $catalog = $this->catalog(enemyHp: 10_000_000, enemyPower: 1, enemyAgility: 1);
        $regenerator = $this->player(
            'secretary:1',
            currentHp: 100,
            defend: true,
            modifiers: ['self_regeneration_target_hp_bps' => 1_000],
        );
        $result = $this->model()->fightPartySnapshots(
            $catalog,
            [$regenerator, $this->player('borrowed:2', currentHp: 100, defend: true)],
            ['party_target'],
            390,
            1,
            0,
        );

        $regeneration = collect($result->actionLog)->first(
            static fn (array $row): bool => ($row['actor_id'] ?? null) === 'secretary:1'
                && ($row['action'] ?? null) === 'self_regeneration',
        );
        self::assertIsArray($regeneration);
        self::assertSame('secretary:1', $regeneration['target_id']);
        self::assertSame(['secretary:1'], $regeneration['target_ids']);
        self::assertGreaterThan(0, -$regeneration['amount']);
    }

    public function test_critical_scaling_uses_the_damage_category_primary_stat(): void
    {
        $catalog = $this->catalog(10_000_000, 1, 1);
        foreach (['precision_cut' => 'spirit', 'holy_bolt' => 'might'] as $skill => $unrelatedStat) {
            $samples = [];
            foreach ([40, 4000] as $unrelatedValue) {
                $player = $this->player('secretary:1', currentHp: 1000);
                $player['stats']['finesse'] = 40;
                $player['stats'][$unrelatedStat] = $unrelatedValue;
                $player['active_skills'] = [$skill];
                $player['ai_rules'] = [
                    ['conditions' => [['type' => 'always']], 'action' => 'skill:'.$skill],
                    ['conditions' => [['type' => 'always']], 'action' => 'defend'],
                ];
                $result = $this->model()->fightPartySnapshots($catalog, [$player], ['party_target'], 3100, 20, 1000);
                $samples[] = collect($result->actionLog)->where('actor_id', 'secretary:1')->where('action', $skill)->where('effect_type', 'damage')->pluck('amount')->all();
            }
            self::assertNotEmpty($samples[0]);
            self::assertSame($samples[0], $samples[1]);
        }
    }

    public function test_guardian_critical_scaling_uses_the_same_vitality_and_might_mix_as_the_attack(): void
    {
        $manifest = $this->catalog(10_000_000, 1, 1)->manifest();
        $manifest['enemies']['party_target']['normal_attack']['stat_coefficients'] = [];
        $manifest['enemies']['party_target']['normal_attack']['fixed'] = 1;
        $catalog = new AlphaV1BuildCatalog($manifest);
        $samples = [];
        foreach ([[100, 100], [40, 140]] as [$vitality, $might]) {
            $player = $this->player('secretary:1', currentHp: 100);
            $player['stats']['vitality'] = $vitality;
            $player['stats']['might'] = $might;
            $player['stats']['finesse'] = 40;
            $player['active_skills'] = ['shield_bash'];
            $player['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'skill:shield_bash']];
            $result = $this->model()->fightPartySnapshots($catalog, [$player], ['party_target'], 3100, 20, 0);
            $samples[] = collect($result->actionLog)->where('actor_id', 'secretary:1')->where('action', 'shield_bash')->where('effect_type', 'damage')->pluck('amount')->all();
        }
        self::assertCount(20, $samples[0]);
        self::assertSame($samples[0], $samples[1]);
    }

    public function test_additional_strike_uses_a_slot_and_cooldown_but_preserves_the_regular_action_with_any_weapon(): void
    {
        $catalog = $this->catalog(10_000_000, 1, 1);
        $player = $this->player('secretary:1', currentHp: 1000);
        $player['equipment']['weapon_style'] = 'crystal_staff';
        $player['active_skills'] = ['quick_stab'];
        $player['ai_rules'] = [
            ['conditions' => [['type' => 'always']], 'action' => 'skill:quick_stab'],
            ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
        ];
        $result = $this->model()->fightPartySnapshots($catalog, [$player], ['party_target'], 3100, 4, 0);
        $decisions = collect($result->actionLog)->where('kind', 'decision')->where('actor_id', 'secretary:1');
        self::assertSame(2, $decisions->where('action_key', 'quick_stab')->count());
        self::assertSame(4, $decisions->where('action_key', 'normal_attack')->count());
    }

    public function test_single_revival_selects_a_fallen_ally_and_does_not_repeat_on_a_living_target(): void
    {
        $catalog = $this->catalog(10_000_000, 1, 1);
        $player = $this->player('secretary:1', currentHp: 1000);
        $player['active_skills'] = ['resurrection', 'heart_of_mercy'];
        $player['ai_rules'] = [
            ['conditions' => [['type' => 'always']], 'action' => 'skill:resurrection'],
            ['conditions' => [['type' => 'always']], 'action' => 'defend'],
        ];
        $result = $this->model()->fightPartySnapshots($catalog,
            [$player, $this->player('borrowed:2', currentHp: 0, defend: true)], ['party_target'], 3100, 5, 0);
        self::assertSame(1, collect($result->actionLog)->where('kind', 'decision')->where('action_key', 'resurrection')->count());
        $revival = collect($result->actionLog)->firstWhere('kind', 'revival');
        self::assertSame('borrowed:2', $revival['target_id']);
        self::assertSame(3000, $revival['revive_hp_bps']);
        self::assertGreaterThan(0, $result->finalStates['borrowed:2']['hp']);
        self::assertSame(1, $result->finalStates['secretary:1']['role_stacks']['grace']);
    }

    public function test_party_aoe_grants_each_damaged_member_one_action_of_awakening_gain(): void
    {
        $manifest = $this->catalog(10_000_000, 1, 1, enemyAoe: true)->manifest();
        $manifest['skills']['party_wave']['effects'][0]['hits'] = 3;
        $catalog = new AlphaV1BuildCatalog($manifest);
        $players = [];
        foreach (['secretary:1', 'borrowed:2'] as $id) {
            $player = $this->player($id, currentHp: 1000, awakening: true, defend: true);
            $player['awakening']['gauge'] = 0;
            $player['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'defend']];
            if ($id === 'borrowed:2') {
                $player['active_skills'] = ['crystal_aegis'];
                $player['ai_rules'] = [['conditions' => [['type' => 'always']], 'action' => 'skill:crystal_aegis']];
            }
            $players[] = $player;
        }
        $result = $this->model()->fightPartySnapshots($catalog, $players, ['party_target'], 3100, 1, 0);
        self::assertGreaterThan(0, collect($result->actionLog)->where('actor_id', 'enemy:1')->sum('barrier_absorbed'));
        foreach (['secretary:1', 'borrowed:2'] as $id) {
            self::assertSame(UndergroundAwakening::ROUND_GAIN + UndergroundAwakening::DAMAGING_ENEMY_ACTION_GAIN,
                $result->awakening[$id]['gauge_after']);
        }
    }

    public function test_effective_healing_actions_charge_mercy_but_periodic_ticks_do_not(): void
    {
        $catalog = $this->catalog(10_000_000, 1, 1);
        $healer = $this->player('secretary:1', currentHp: 1000);
        $healer['active_skills'] = ['heart_of_mercy', 'resurrection', 'regeneration', 'mending_prayer', 'lucid_dream'];
        $healer['ai_rules'] = array_map(static fn (string $skill): array => [
            'conditions' => [['type' => 'always']], 'action' => 'skill:'.$skill,
        ], $healer['active_skills']);
        $result = $this->model()->fightPartySnapshots($catalog,
            [$healer, $this->player('borrowed:2', currentHp: 0, defend: true), $this->player('borrowed:3', currentHp: 500, defend: true)],
            ['party_target'], 3100, 8, 0);
        $actions = collect($result->actionLog)->where('actor_id', 'secretary:1');
        $effectiveActions = $actions->filter(static fn (array $row): bool => in_array($row['effect_type'] ?? null, ['recovery', 'revival'], true)
            && ($row['effect_source'] ?? null) !== 'periodic' && $row['amount'] < 0)
            ->pluck('action_id')->unique()->count();
        $spent = $actions->where('action', 'role_stack_spent:grace')->sum('amount');
        self::assertGreaterThan(0, $spent);
        self::assertSame($effectiveActions, $spent + $result->finalStates['secretary:1']['role_stacks']['grace']);
        $periodicHealing = collect($result->actionLog)->where('action', 'periodic_heal:regeneration');
        self::assertNotEmpty($periodicHealing->all());
        self::assertSame(['periodic', 'regeneration'], [
            $periodicHealing->first()['effect_source'] ?? null,
            $periodicHealing->first()['periodic_status_key'] ?? null,
        ]);
        $mercy = $actions->first(static fn (array $row): bool => ($row['kind'] ?? null) === 'decision' && ($row['action_key'] ?? null) === 'heart_of_mercy');
        self::assertIsArray($mercy);
        self::assertGreaterThanOrEqual(4, $mercy['round']);
    }

    private function catalog(
        int $enemyHp,
        int $enemyPower,
        int $enemyAgility,
        bool $enemyAoe = false,
        bool $enemyCounter = false,
        bool $enemyBoss = false,
    ): AlphaV1BuildCatalog {
        $contents = file_get_contents(dirname(__DIR__, 3).'/config/underground/balance/foundation-v1.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $manifest['enemies']['party_target'] = [
            'label' => 'PT試験体',
            'boss' => $enemyBoss,
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
            'skills' => $enemyCounter ? ['counter_stance'] : ($enemyAoe ? ['party_wave'] : []),
            'ai_rules' => [[
                'conditions' => [['type' => 'always']],
                'action' => $enemyCounter
                    ? 'skill:counter_stance'
                    : ($enemyAoe ? 'skill:party_wave' : 'normal_attack'),
            ]],
            'modifiers' => $enemyCounter ? ['counter_power_bps' => 5_000] : [],
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

    /**
     * @param  array<string, int>  $modifiers
     * @return array<string, mixed>
     */
    private function player(
        string $combatantId,
        int $currentHp = 1,
        bool $awakening = false,
        bool $defend = false,
        array $modifiers = [],
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
            'modifiers' => $modifiers,
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

    /**
     * @param  'player'|'enemy'  $side
     * @param  list<string>  $skills
     * @param  list<array<string, mixed>>  $aiRules
     */
    private function combatState(
        string $side,
        string $combatantId,
        int $hp,
        array $skills = [],
        array $aiRules = [],
    ): BuildCombatState {
        $state = new BuildCombatState(
            $side,
            $combatantId,
            $combatantId,
            false,
            100,
            ['vitality' => 1, 'might' => 1, 'finesse' => 1, 'spirit' => 1, 'agility' => 1],
            0,
            0,
            1,
            1,
            $skills,
            $aiRules,
            [],
            null,
            [],
        );
        $state->hp = $hp;

        return $state;
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
