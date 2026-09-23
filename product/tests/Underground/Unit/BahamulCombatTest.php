<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\UndergroundAwakening;
use Tests\TestCase;

final class BahamulCombatTest extends TestCase
{
    public function test_beginner_uses_claw_breath_wing_debuff_and_telegraphed_flare_without_roar(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class)->otherworldCatalog('bahamul_beginner_1');
        $party = $this->observationParty($catalog, 'bahamul_beginner_1');
        $result = app(AlphaV1CombatModel::class)->fightPartySnapshots($catalog, $party, ['bahamul_beginner_1'], 4405, 24, 0);
        $rows = collect($result->actionLog);
        $damage = $rows->where('actor_id', 'enemy:1')->where('effect_type', 'damage');
        $claw = $damage->where('action', 'bahamul_black_claw')->first();
        self::assertNotNull($claw);
        self::assertCount(1, $damage->where('action_id', $claw['action_id']));
        $clawWarning = $damage->where('action', 'bahamul_claw_telegraph')->first();
        self::assertNotNull($clawWarning);
        self::assertSame($claw['round'] - 1, $clawWarning['round']);
        self::assertTrue($rows->where('actor_id', 'enemy:1')->where('kind', 'decision')->where('action_key', 'bahamul_claw_telegraph')->where('round', $clawWarning['round'])->isNotEmpty());
        foreach (['bahamul_breath', 'bahamul_dragon_wing', 'bahamul_megaflare'] as $skill) {
            self::assertSame(array_column($party, 'combatant_id'), $damage->where('action', $skill)->pluck('target_id')->unique()->values()->all(), $skill);
        }
        self::assertSame(array_column($party, 'combatant_id'), $rows->where('action', 'status:weakened_strike')->pluck('target_id')->unique()->values()->all());
        $flare = $damage->where('action', 'bahamul_megaflare')->first();
        self::assertTrue($rows->where('actor_id', 'enemy:1')->where('kind', 'decision')->where('action_key', 'enemy_telegraph')->where('round', '<', $flare['round'])->isNotEmpty());
        self::assertTrue($rows->where('action', 'charged_attack_roar')->isEmpty());
        self::assertTrue($rows->where('action', 'charged_attack_countdown')->isEmpty());
    }

    public function test_middle_roar_fills_the_party_and_leaves_a_round_for_guard_before_the_shared_flare(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class)->otherworldCatalog('bahamul_intermediate_1');
        $party = $this->observationParty($catalog, 'bahamul_intermediate_1');
        $party[1]['awakening']['growth_path'] = 'guardianship_blue';
        $party[1]['awakening']['technique_key'] = 'absolute_aegis';
        $party[1]['ai_rules'] = [
            ['conditions' => [['type' => 'enemy_major_telegraph']], 'action' => 'awakening'],
            ['conditions' => [['type' => 'enemy_major_telegraph']], 'action' => 'awakening_technique'],
            ['conditions' => [['type' => 'always']], 'action' => 'defend'],
        ];
        $result = app(AlphaV1CombatModel::class)->fightPartySnapshots($catalog, $party, ['bahamul_intermediate_1'], 4405, 24, 0);
        $rows = collect($result->actionLog);
        foreach (['bahamul_black_claw', 'bahamul_breath', 'bahamul_dragon_wing'] as $skill) {
            self::assertTrue($rows->where('actor_id', 'enemy:1')->where('action', $skill)->where('effect_type', 'damage')->isNotEmpty(), $skill);
        }
        self::assertTrue($rows->where('action', 'status:weakened_strike')->isNotEmpty());
        self::assertCount(1, $rows->where('action', 'charged_attack_roar'));
        $countdown = $rows->where('action', 'charged_attack_countdown');
        self::assertSame([5, 4, 3, 2, 1], $countdown->pluck('countdown')->all());
        self::assertSame([1], $countdown->where('major_telegraph', true)->pluck('countdown')->all());
        $majorRound = $countdown->where('countdown', 1)->first()['round'];
        self::assertSame([$majorRound], $rows->where('kind', 'awakening_technique')->where('action', 'absolute_aegis')->pluck('round')->all());
        $flare = $rows->where('action', 'bahamul_megaflare')->where('effect_type', 'damage');
        self::assertCount(4, $flare);
        self::assertSame([$majorRound + 1], $flare->pluck('round')->unique()->values()->all());
        foreach ($party as $index => $player) {
            $state = $result->finalStates[$player['combatant_id']];
            if ($index === 1) {
                self::assertTrue($state['awakened']);
            } else {
                self::assertFalse($state['awakened']);
                self::assertSame(UndergroundAwakening::GAUGE_MAX, $state['awakening_gauge']);
            }
        }
        self::assertNotContains('abyssal_roar', array_column($result->finalStates['enemy:1']['statuses'], 'key'));
    }

    /**
     * Durable observers stop attacking below the ultimate threshold so the real boss can complete its rotation.
     * This fixture checks mechanics, not the recommended-level balance or a particular HP/attack value.
     *
     * @return list<array<string,mixed>>
     */
    private function observationParty(AlphaV1BuildCatalog $catalog, string $stage): array
    {
        $equipment = config('underground-alpha-v1.exploration.starter_weapon');
        $equipment['weapon_power'] = intdiv($catalog->enemy($stage)['max_hp'], 5);
        $party = [];
        for ($i = 1; $i <= 4; $i++) {
            $party[] = [
                'combatant_id' => 'secretary:'.$i, 'key' => 'observer-'.$i, 'label' => 'observer-'.$i,
                'stats' => ['vitality' => 100_000, 'might' => 1, 'finesse' => 1, 'spirit' => 1, 'agility' => 1],
                'equipment' => $equipment, 'active_skills' => [], 'modifiers' => [], 'natural_recovery' => 0,
                'ai_rules' => $i === 1 ? [
                    ['conditions' => [['type' => 'enemy_hp_lte', 'percent' => 35]], 'action' => 'defend'],
                    ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
                ] : [['conditions' => [['type' => 'always']], 'action' => 'defend']],
                'awakening' => ['unlocked' => true, 'gauge' => 0, 'message' => '覚醒',
                    'growth_path' => 'martial_red', 'technique_key' => 'decisive_heavenrend'],
            ];
        }

        return $party;
    }
}
