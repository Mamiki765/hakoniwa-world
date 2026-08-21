<?php

namespace Tests\Unit;

use App\Domain\Monster\MonsterSpawnSource;
use App\Domain\Turn\LaunchIntent;
use App\Domain\Turn\TurnState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TurnStateTest extends TestCase
{
    public function test_launch_intents_are_registered_per_nation_and_remaining_shots_are_consumed(): void
    {
        $state = new TurnState;
        $first = $state->registerLaunchIntent(10, 'missile_normal', 12, -3, 5);
        $second = $state->registerLaunchIntent(10, 'missile_pp', 9, 8, 2);
        $third = $state->registerLaunchIntent(11, 'missile_normal', 1, 2, 0);

        $this->assertSame([$first, $second, $third], $state->launchIntents());
        $this->assertSame([$first, $second], $state->launchIntentsForNation(10));
        $this->assertSame(5, $first->requestedShots);
        $this->assertSame(5, $first->remainingShots());

        $state->consumeLaunchIntentShots($first, 2);
        $this->assertSame(3, $first->remainingShots());
        $state->consumeLaunchIntentShots($first, 3);
        $this->assertSame(0, $first->remainingShots());
    }

    #[DataProvider('invalidIntentProvider')]
    public function test_invalid_launch_intent_coordinates_and_counts_are_rejected(
        mixed $nationId,
        mixed $definitionKey,
        mixed $targetX,
        mixed $targetY,
        mixed $requestedShots,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        (new TurnState)->registerLaunchIntent(
            $nationId,
            $definitionKey,
            $targetX,
            $targetY,
            $requestedShots,
        );
    }

    /** @return array<string, array{mixed, mixed, mixed, mixed, mixed}> */
    public static function invalidIntentProvider(): array
    {
        return [
            'zero Nation ID' => [0, 'missile', 1, 2, 3],
            'empty definition key' => [1, '', 1, 2, 3],
            'string x' => [1, 'missile', '1', 2, 3],
            'float y' => [1, 'missile', 1, 2.0, 3],
            'negative requested shots' => [1, 'missile', 1, 2, -1],
            'string requested shots' => [1, 'missile', 1, 2, '3'],
        ];
    }

    public function test_invalid_remaining_shot_updates_are_rejected(): void
    {
        $state = new TurnState;
        $intent = $state->registerLaunchIntent(1, 'missile', 1, 2, 3);

        foreach ([-1, 4, '1'] as $invalid) {
            try {
                $state->consumeLaunchIntentShots($intent, $invalid);
                $this->fail('Expected invalid consumed shot count.');
            } catch (InvalidArgumentException) {
                $this->assertSame(3, $intent->remainingShots());
            }
        }

        $foreign = new LaunchIntent(1, 'missile', 1, 2, 1);
        $this->expectException(InvalidArgumentException::class);
        $state->consumeLaunchIntentShots($foreign, 1);
    }

    public function test_spawn_turn_movement_deferral_is_explicit_per_spawn_source(): void
    {
        $state = new TurnState;
        $state->recordMonsterSpawned(9, MonsterSpawnSource::MonsterDispatchCommand);
        $state->recordMonsterSpawned(10, MonsterSpawnSource::WorldAoiDisaster);

        $this->assertSame([9, 10], $state->monsterIdsDeferredFromSpawnTurnMovement());
        $this->assertSame('world_aoi_disaster', MonsterSpawnSource::WorldAoiDisaster->value);
        $this->assertFalse(MonsterSpawnSource::MonsterDispatchCommand->canActOnSpawnTurn());
        $this->assertFalse(MonsterSpawnSource::Natural->canActOnSpawnTurn());
        $this->assertFalse(MonsterSpawnSource::WorldAoiDisaster->canActOnSpawnTurn());
    }

    public function test_turn_local_nation_activity_aggregates_missile_results_until_idle_finalization(): void
    {
        $state = new TurnState;
        $state->recordFinanceSucceeded(10);
        $state->recordImmediateNormalCommandSucceeded(10);
        $state->registerLaunchIntent(10, 'missile', 1, 2, 3);
        $state->recordMissileShotsFired(10, 1);
        $state->recordMissileShotsFired(10, 2);

        $this->assertSame([
            'finance_succeeded' => true,
            'immediate_normal_command_succeeded' => true,
            'missile_intent_pending' => true,
            'missile_shots_fired' => 3,
            'idle_counter_finalized' => false,
        ], $state->nationActivity(10));

        $state->markIdleCounterFinalized(10);
        $finalized = $state->nationActivity(10);
        $this->assertTrue($finalized['idle_counter_finalized']);

        $state->recordFinanceSucceeded(10);
        $state->recordMissileShotsFired(10, 1);
        $state->markIdleCounterFinalized(10);
        $this->assertSame($finalized, $state->nationActivity(10));
    }
}
