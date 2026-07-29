<?php

namespace Tests\Unit;

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
}
