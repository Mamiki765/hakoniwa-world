<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1BattleProjector;
use App\Application\Underground\UndergroundPartyBattleProjector;
use App\Domain\Underground\Combat\PartyCombatResult;
use PHPUnit\Framework\TestCase;

final class UndergroundPartyBattleProjectorTest extends TestCase
{
    public function test_v3_groups_rounds_preserves_actor_identity_and_emits_compact_portrait_events(): void
    {
        $result = new PartyCombatResult(
            'player',
            1,
            [
                ['round' => 1, 'kind' => 'action', 'actor_id' => 'secretary:1', 'target_id' => 'enemy:1', 'target_ids' => ['enemy:1'], 'team' => 'player', 'type' => 'damage', 'amount' => 10],
                ['round' => 1, 'kind' => 'awakening', 'actor_id' => 'secretary:1', 'target_id' => null, 'target_ids' => [], 'team' => 'player'],
                ['round' => 1, 'kind' => 'round_end', 'team' => 'system', 'combatants' => [
                    'secretary:1' => ['combatant_id' => 'secretary:1', 'team' => 'player', 'hp' => 90],
                    'enemy:1' => ['combatant_id' => 'enemy:1', 'team' => 'enemy', 'hp' => 0],
                ]],
            ],
            ['secretary:1' => ['combatant_id' => 'secretary:1', 'team' => 'player', 'hp' => 100]],
            ['secretary:1' => ['combatant_id' => 'secretary:1', 'team' => 'player', 'hp' => 90]],
            ['damage_dealt' => 10],
        );
        $projected = (new UndergroundPartyBattleProjector)->project($result, [
            'secretary:1' => ['team' => 'player', 'display_name' => '秘書', 'image_references' => ['compact' => 'c.webp', 'normal' => 'n.webp', 'awakening' => 'a.webp']],
            'enemy:1' => ['team' => 'enemy', 'label' => '敵', 'image_references' => []],
        ]);

        self::assertSame(3, $projected['version']);
        self::assertSame('secretary:1', $projected['rounds'][0]['actions'][0]['actor_id']);
        self::assertSame(['enemy:1'], $projected['rounds'][0]['actions'][0]['target_ids']);
        self::assertTrue($projected['rounds'][0]['actions'][1]['important']);
        self::assertCount(3, $projected['portrait_events']);
        self::assertSame(['compact' => 'c.webp', 'normal' => 'n.webp', 'awakening' => 'a.webp'], $projected['portrait_events'][1]['image_refs']);
        self::assertSame('a.webp', $projected['portrait_events'][1]['image_ref']);
        self::assertSame(1, $projected['portrait_events'][1]['round']);
        self::assertSame('a.webp', $projected['portrait_events'][2]['image_ref']);
        self::assertSame(['start', 'awakening', 'final'], array_column(
            array_values(array_filter($projected['portrait_events'], static fn (array $event): bool => $event['combatant_id'] === 'secretary:1')),
            'type',
        ));
    }

    public function test_v3_addition_does_not_reinterpret_legacy_presentation_versions(): void
    {
        self::assertSame(2, UndergroundAlphaV1BattleProjector::PRESENTATION_LOG_VERSION);
        self::assertSame(3, UndergroundPartyBattleProjector::PRESENTATION_LOG_VERSION);
    }
}
