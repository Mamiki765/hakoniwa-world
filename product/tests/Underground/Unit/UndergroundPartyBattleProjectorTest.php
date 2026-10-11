<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundBattleStatisticsProjector;
use App\Application\Underground\UndergroundPartyBattleProjector;
use App\Domain\Underground\Combat\PartyCombatResult;
use PHPUnit\Framework\TestCase;
use Tests\Underground\Fixtures\PartyPresentationFixture;

final class UndergroundPartyBattleProjectorTest extends TestCase
{
    public function test_party_boundary_states_use_the_existing_status_labels(): void
    {
        $fixture = PartyPresentationFixture::create();
        $state = ['hp' => 100, 'max_hp' => 100, 'mp' => 500, 'barrier' => 0,
            'statuses' => [['key' => 'bleed', 'remaining' => 2, 'stacks' => 1]],
            'role_stacks' => ['fighting_spirit' => 0, 'grace' => 0]];
        $result = new PartyCombatResult('player', 0, [], ['secretary:1' => $state], ['secretary:1' => $state]);
        $projected = (new UndergroundPartyBattleProjector)->project(
            $result, $fixture['member_snapshots'], $fixture['catalog'],
        );

        self::assertSame([['label' => '出血', 'remaining' => 2, 'stacks' => 1]],
            $projected['initial_state']['secretary:1']['statuses']);
        self::assertSame($projected['initial_state']['secretary:1']['statuses'],
            $projected['summary']['final_state']['secretary:1']['statuses']);
    }

    public function test_barrier_gain_is_positive_for_display_without_changing_the_internal_log(): void
    {
        $fixture = PartyPresentationFixture::create();
        $row = ['round' => 1, 'effect_type' => 'barrier', 'action' => 'crystal_aegis',
            'amount' => -922, 'team' => 'player', 'actor_id' => 'secretary:1', 'target_id' => 'secretary:1'];
        $result = new PartyCombatResult('player', 1, [$row], [], []);
        $members = $fixture['member_snapshots'];
        $members['secretary:1']['display_name'] = 'レイ';
        $projected = (new UndergroundPartyBattleProjector)->project($result, $members, $fixture['catalog']);
        $action = $projected['rounds'][0]['actions'][0];

        self::assertSame(-922, $result->actionLog[0]['amount']);
        self::assertSame('barrier', $action['type']);
        self::assertSame(922, $action['amount']);
        self::assertSame('プロテクション', $action['label']);
        self::assertSame('レイ', $action['actor_name']);
    }

    public function test_real_engine_states_and_actions_reach_the_presentation_without_reinterpretation(): void
    {
        $fixture = PartyPresentationFixture::create();
        $result = $fixture['result'];
        $projected = (new UndergroundPartyBattleProjector)->project(
            $result, $fixture['member_snapshots'], $fixture['catalog'],
        );
        $statistics = (new UndergroundBattleStatisticsProjector)->fromParty($result, 'secretary:1');

        self::assertSame(3, $projected['version']);
        self::assertSame(
            ['complete' => true, 'issue_count' => 0, 'reasons' => []],
            $statistics['completeness'],
        );
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
        $rawCost = collect($result->actionLog)->firstWhere('effect_type', 'mp_cost');
        self::assertIsArray($rawCost);
        self::assertSame(['skill_cost', $rawCost['amount'], $rawCost['actor_mp']],
            [$costs[0]['mp_source'], $costs[0]['amount'], $costs[0]['actor_mp']]);
        $rawRecovery = collect($result->actionLog)->firstWhere('effect_type', 'mp_recovery');
        self::assertIsArray($rawRecovery);
        $recovery = collect($actions)->firstWhere('type', 'mp_recovery');
        self::assertIsArray($recovery);
        self::assertSame(['skill_recovery', $rawRecovery['amount'], $rawRecovery['actor_mp']],
            [$recovery['mp_source'], $recovery['amount'], $recovery['actor_mp']]);
        $revivals = array_values(array_filter($actions, static fn (array $row): bool => $row['kind'] === 'revival'));
        self::assertNotEmpty($revivals);
        self::assertSame('borrowed:2', $revivals[0]['actor_id']);
        $rawRevival = array_values(array_filter($result->actionLog, static fn (array $row): bool => ($row['kind'] ?? null) === 'revival'))[0];
        self::assertSame($rawRevival['target_id'], $revivals[0]['target_id']);
        self::assertSame($fixture['member_snapshots'][$rawRevival['target_id']]['display_name'], $revivals[0]['target_name']);
        self::assertTrue($revivals[0]['important']);
        self::assertSame([$revivals[0]['target_name'].'が復活した！'], $revivals[0]['lines']);

        $portraits = array_values(array_filter($projected['portrait_events'], static fn (array $event): bool => $event['combatant_id'] === 'borrowed:2'));
        self::assertSame(['start', 'awakening', 'final'], array_column($portraits, 'type'));
        self::assertFalse($portraits[0]['state']['awakened']);
        self::assertTrue($portraits[1]['state']['awakened']);
        self::assertSame($result->finalStates['borrowed:2'], $portraits[2]['state']);
        $awakenings = array_values(array_filter($actions, static fn (array $row): bool => $row['kind'] === 'awakening'));
        self::assertSame(['覚醒する。'], $awakenings[0]['lines']);
        self::assertSame(['awakening', 0, 10_000], [$awakenings[0]['mp_source'], $awakenings[0]['mp_recovered'], $awakenings[0]['actor_mp']]);
    }

    public function test_legacy_mp_rows_do_not_infer_missing_source_or_awakening_recovery(): void
    {
        $fixture = PartyPresentationFixture::create();
        $rows = [
            ['round' => 1, 'effect_type' => 'mp_recovery', 'amount' => 300, 'actor_mp' => 9400, 'message' => 'MP回復'],
            ['round' => 1, 'kind' => 'awakening', 'amount' => 0, 'message' => 'HP/MPが全回復した！'],
        ];
        $party = new PartyCombatResult('player', 1, $rows, [], []);
        $partyProjection = (new UndergroundPartyBattleProjector)->project($party, $fixture['member_snapshots'], $fixture['catalog']);
        $actions = $partyProjection['rounds'][0]['actions'];
        self::assertSame(9400, $actions[0]['actor_mp']);
        self::assertArrayNotHasKey('mp_source', $actions[0]);
        self::assertArrayNotHasKey('mp_source', $actions[1]);
        self::assertArrayNotHasKey('mp_recovered', $actions[1]);
        self::assertSame($rows, $party->actionLog);
    }
}
