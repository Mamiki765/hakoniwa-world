<?php

namespace App\Application;

use App\Domain\Underground\Facility\UndergroundCommandCatalog;
use App\Domain\Underground\Facility\UndergroundCommandDefinition;
use App\Models\CommandDefinition;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use DomainException;

final readonly class QueuedCommandDefinitionResolver
{
    public function __construct(
        private UndergroundCommandCatalog $undergroundCommands,
    ) {}

    public function resolve(
        NationCommandQueueItem $item,
    ): CommandDefinition|UndergroundCommandDefinition {
        if ($item->target_context === 'underground_slot') {
            if (! is_string($item->underground_command_key) || $item->command_definition_id !== null) {
                throw new DomainException('Underground queue item command identity is invalid.');
            }

            $ruleset = $item->relationLoaded('requestRulesetVersion')
                ? $item->requestRulesetVersion
                : $item->requestRulesetVersion()->first();
            if (! $ruleset instanceof RulesetVersion) {
                throw new DomainException('Underground queue item Ruleset provenance is missing.');
            }

            return $this->undergroundCommands->get($ruleset->settings, $item->underground_command_key);
        }

        if ($item->target_context !== 'surface_cell'
            || $item->command_definition_id === null
            || $item->underground_command_key !== null) {
            throw new DomainException('Surface queue item command identity is invalid.');
        }
        $definition = $item->relationLoaded('definition')
            ? $item->definition
            : $item->definition()->first();
        if (! $definition instanceof CommandDefinition) {
            throw new DomainException('Surface queue item command identity is invalid.');
        }

        return $definition;
    }
}
