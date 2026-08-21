<?php

namespace App\Application;

final readonly class RulesetV11MigrationResult
{
    public function __construct(
        public int $rulesetVersionId,
        public string $checksum,
        public bool $published,
        public bool $alreadyCompleted,
        public int $requestProvenanceBackfilled,
        public int $queuedCommandsRebound,
        public int $aliveMonstersRebound,
        public int $killStatsRebound,
        public int $worldsActivated,
    ) {}
}
