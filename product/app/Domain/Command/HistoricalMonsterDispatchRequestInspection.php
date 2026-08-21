<?php

namespace App\Domain\Command;

final readonly class HistoricalMonsterDispatchRequestInspection
{
    public function __construct(
        public bool $proven,
        public ?int $requestRulesetVersionId,
        public ?int $requestCommandDefinitionId,
        public ?int $selector,
        public string $reason,
    ) {}
}
