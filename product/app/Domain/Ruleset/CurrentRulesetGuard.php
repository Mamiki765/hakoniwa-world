<?php

namespace App\Domain\Ruleset;

use App\Models\RulesetVersion;
use App\Models\World;
use DomainException;

final class CurrentRulesetGuard
{
    public function assertMutable(World $world, RulesetVersion $worldRuleset): void
    {
        $configuredKey = config('hakoniwa.ruleset.key');
        $configuredVersion = config('hakoniwa.ruleset.version');
        if (! is_string($configuredKey) || $configuredKey === ''
            || ! is_int($configuredVersion) || $configuredVersion < 1) {
            throw new DomainException('The configured current ruleset identity is invalid.');
        }

        if ((int) $world->ruleset_version_id !== (int) $worldRuleset->getKey()) {
            throw new DomainException('The loaded World ruleset does not match its ruleset_version_id.');
        }

        // Published keys are immutable and unique. Once the loaded relation matches the
        // configured identity, its already-loaded primary key is the current ruleset ID.
        $currentRulesetId = $worldRuleset->key === $configuredKey
            && $worldRuleset->version === $configuredVersion
                ? (int) $worldRuleset->getKey()
                : null;
        if ($currentRulesetId === null || (int) $world->ruleset_version_id !== $currentRulesetId) {
            throw new ResetRequiredException(
                ResetRequiredException::ERROR_CODE.": World {$world->key} uses historical ruleset "
                ."{$worldRuleset->key}; reset it to {$configuredKey} before mutation.",
            );
        }
    }
}
