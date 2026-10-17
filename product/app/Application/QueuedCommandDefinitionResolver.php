<?php

namespace App\Application;

use App\Domain\Underground\Facility\UndergroundCommandCatalog;
use App\Domain\Underground\Facility\UndergroundCommandDefinition;
use App\Models\CommandDefinition;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use DomainException;

final class QueuedCommandDefinitionResolver
{
    /** @var array<int, array<string, CommandDefinition>> */
    private array $definitions = [];

    public function __construct(
        private readonly UndergroundCommandCatalog $undergroundCommands,
    ) {}

    public function resolve(
        NationCommandQueueItem $item,
        ?RulesetVersion $currentRuleset = null,
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

            return $this->undergroundCommands->get(
                ($currentRuleset ?? $this->currentRulesetForItem($item))->settings,
                $item->underground_command_key,
            );
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

        $ruleset = $currentRuleset ?? $this->currentRulesetForItem($item);
        if ((int) $definition->ruleset_version_id === (int) $ruleset->id) {
            return $definition;
        }

        return $this->definitions[(int) $ruleset->id][$definition->key] ??= CommandDefinition::query()
            ->where('ruleset_version_id', $ruleset->id)
            ->where('key', $definition->key)
            ->first()
            ?? throw new DomainException("Current World Ruleset command {$definition->key} is missing.");
    }

    private function currentRulesetForItem(NationCommandQueueItem $item): RulesetVersion
    {
        $ruleset = RulesetVersion::query()
            ->join('worlds', 'worlds.ruleset_version_id', '=', 'ruleset_versions.id')
            ->join('nations', 'nations.world_id', '=', 'worlds.id')
            ->join('nation_command_queues', 'nation_command_queues.nation_id', '=', 'nations.id')
            ->where('nation_command_queues.id', $item->nation_command_queue_id)
            ->select('ruleset_versions.*')
            ->first();

        return $ruleset ?? throw new DomainException('Queue item current World Ruleset is missing.');
    }
}
