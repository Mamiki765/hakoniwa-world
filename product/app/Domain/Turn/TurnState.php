<?php

namespace App\Domain\Turn;

use InvalidArgumentException;

final class TurnState
{
    /** @var list<LaunchIntent> */
    private array $launchIntents = [];

    public function registerLaunchIntent(
        mixed $nationId,
        mixed $definitionKey,
        mixed $targetX,
        mixed $targetY,
        mixed $requestedShots,
    ): LaunchIntent {
        $intent = new LaunchIntent($nationId, $definitionKey, $targetX, $targetY, $requestedShots);
        $this->launchIntents[] = $intent;

        return $intent;
    }

    /** @return list<LaunchIntent> */
    public function launchIntents(): array
    {
        return $this->launchIntents;
    }

    /** @return list<LaunchIntent> */
    public function launchIntentsForNation(mixed $nationId): array
    {
        if (! is_int($nationId) || $nationId < 1) {
            throw new InvalidArgumentException('Launch intent Nation ID must be a positive integer.');
        }

        return array_values(array_filter(
            $this->launchIntents,
            static fn (LaunchIntent $intent): bool => $intent->nationId === $nationId,
        ));
    }

    public function consumeLaunchIntentShots(LaunchIntent $intent, mixed $shots): void
    {
        if (! in_array($intent, $this->launchIntents, true)) {
            throw new InvalidArgumentException('Launch intent does not belong to this turn state.');
        }

        $intent->consumeShots($shots);
    }
}
