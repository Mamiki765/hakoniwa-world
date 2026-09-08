<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundPartyBattleProjector;
use PHPUnit\Framework\TestCase;
use Tests\Underground\Fixtures\PartyPresentationFixture;

final class UndergroundPartyBattleProjectorTest extends TestCase
{
    public function test_real_engine_states_and_actions_reach_the_presentation_without_reinterpretation(): void
    {
        $fixture = PartyPresentationFixture::create();
        $result = $fixture['result'];
        $projected = (new UndergroundPartyBattleProjector)->project(
            $result, $fixture['member_snapshots'], $fixture['catalog'],
        );

        self::assertSame(3, $projected['version']);
        foreach ($result->initialStates as $id => $state) {
            self::assertSame($state['hp'], $projected['initial_state'][$id]['hp']);
            self::assertSame($state['hp'], $projected['rounds'][0]['start_state'][$id]['hp']);
            self::assertSame($result->finalStates[$id]['hp'], $projected['summary']['final_state'][$id]['hp']);
        }
        self::assertNotSame($result->initialStates['secretary:1']['hp'], $result->finalStates['secretary:1']['hp']);
        $actions = array_merge(...array_column($projected['rounds'], 'actions'));
        $decisions = array_values(array_filter($actions, static fn (array $row): bool => $row['kind'] === 'decision'));
        self::assertNotEmpty($decisions);
        foreach ($decisions as $decision) {
            self::assertNotSame('decision', $decision['label']);
            self::assertNotSame($decision['action_key'], $decision['label']);
            self::assertNotEmpty($decision['actor_id']);
            self::assertNotEmpty($decision['action_id']);
        }
        $costs = array_values(array_filter($actions, static fn (array $row): bool => $row['type'] === 'mp_cost'));
        self::assertNotEmpty($costs);
        foreach ($costs as $cost) {
            self::assertContains($cost['action_id'], array_column($decisions, 'action_id'));
        }
        $revivals = array_values(array_filter($actions, static fn (array $row): bool => $row['kind'] === 'revival'));
        self::assertNotEmpty($revivals);
        self::assertSame('borrowed:2', $revivals[0]['actor_id']);
        self::assertSame('secretary:1', $revivals[0]['target_id']);
        self::assertSame('Leader', $revivals[0]['target_name']);
        self::assertTrue($revivals[0]['important']);
        self::assertSame(['Leaderが復活した！'], $revivals[0]['lines']);

        $portraits = array_values(array_filter($projected['portrait_events'], static fn (array $event): bool => $event['combatant_id'] === 'borrowed:2'));
        self::assertSame(['start', 'awakening', 'final'], array_column($portraits, 'type'));
        self::assertFalse($portraits[0]['state']['awakened']);
        self::assertTrue($portraits[1]['state']['awakened']);
        self::assertSame($result->finalStates['borrowed:2'], $portraits[2]['state']);
        $awakenings = array_values(array_filter($actions, static fn (array $row): bool => $row['kind'] === 'awakening'));
        self::assertSame(['覚醒する。'], $awakenings[0]['lines']);
    }
}
