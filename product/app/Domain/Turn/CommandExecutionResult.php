<?php

namespace App\Domain\Turn;

use DomainException;

final readonly class CommandExecutionResult
{
    /** @param array<string, int|string|bool|null> $metadata */
    public function __construct(
        public bool $consumesTurn,
        public string $queueDisposition,
        public ?int $remainingQuantity,
        public array $metadata = [],
    ) {
        if (in_array($queueDisposition, ['remove', 'retain_head', 'return_head'], true) === false) {
            throw new DomainException('Command result has an invalid queue disposition.');
        }
        if ($remainingQuantity !== null && $remainingQuantity < 0) {
            throw new DomainException('Command result remaining quantity cannot be negative.');
        }
    }
}
