<?php

namespace App\Domain\Turn;

use InvalidArgumentException;

final class LaunchIntent
{
    public readonly int $nationId;

    public readonly string $definitionKey;

    public readonly int $targetX;

    public readonly int $targetY;

    public readonly int $requestedShots;

    public readonly ?int $queueItemId;

    private int $remainingShots;

    private ?bool $antiMonsterContext = null;

    public function __construct(
        mixed $nationId,
        mixed $definitionKey,
        mixed $targetX,
        mixed $targetY,
        mixed $requestedShots,
        mixed $queueItemId = null,
    ) {
        if (! is_int($nationId) || $nationId < 1) {
            throw new InvalidArgumentException('Launch intent Nation ID must be a positive integer.');
        }
        if (! is_string($definitionKey) || $definitionKey === '') {
            throw new InvalidArgumentException('Launch intent definition key must be a non-empty string.');
        }
        if (! is_int($targetX) || ! is_int($targetY)) {
            throw new InvalidArgumentException('Launch intent target must use integer canonical x/y coordinates.');
        }
        if (! is_int($requestedShots) || $requestedShots < 0) {
            throw new InvalidArgumentException('Launch intent requested shots must be a non-negative integer.');
        }
        if ($queueItemId !== null && (! is_int($queueItemId) || $queueItemId < 1)) {
            throw new InvalidArgumentException('Launch intent queue item ID must be null or a positive integer.');
        }

        $this->nationId = $nationId;
        $this->definitionKey = $definitionKey;
        $this->targetX = $targetX;
        $this->targetY = $targetY;
        $this->requestedShots = $requestedShots;
        $this->queueItemId = $queueItemId;
        $this->remainingShots = $requestedShots;
    }

    public function remainingShots(): int
    {
        return $this->remainingShots;
    }

    public function classifyAntiMonsterContext(bool $antiMonsterContext): void
    {
        if ($this->antiMonsterContext !== null) {
            throw new InvalidArgumentException('Launch intent anti-monster context is already frozen.');
        }
        $this->antiMonsterContext = $antiMonsterContext;
    }

    public function antiMonsterContext(): bool
    {
        if ($this->antiMonsterContext === null) {
            throw new InvalidArgumentException('Launch intent anti-monster context is not classified.');
        }

        return $this->antiMonsterContext;
    }

    public function consumeShots(mixed $shots): void
    {
        if (! is_int($shots) || $shots < 0) {
            throw new InvalidArgumentException('Consumed launch intent shots must be a non-negative integer.');
        }
        if ($shots > $this->remainingShots) {
            throw new InvalidArgumentException('Cannot consume more shots than the launch intent has remaining.');
        }

        $this->remainingShots -= $shots;
    }
}
