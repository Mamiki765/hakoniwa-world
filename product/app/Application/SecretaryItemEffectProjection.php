<?php

namespace App\Application;

final readonly class SecretaryItemEffectProjection
{
    /**
     * @param  array{source: string, world_id: int, ruleset_version_id: int, ruleset_key: string, ruleset_version: int}  $context
     * @param  array<string, mixed>  $rulesetSettings
     */
    public function __construct(
        public array $context,
        public array $rulesetSettings,
    ) {}
}
