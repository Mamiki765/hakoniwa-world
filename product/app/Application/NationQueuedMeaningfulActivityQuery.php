<?php

namespace App\Application;

use App\Domain\Underground\Facility\UndergroundCommandCatalog;
use App\Models\CommandDefinition;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;

final class NationQueuedMeaningfulActivityQuery
{
    public function __construct(
        private readonly UndergroundCommandCatalog $undergroundCommands,
    ) {}

    public function exists(Nation $nation, string $financeKey): bool
    {
        $items = NationCommandQueueItem::query()
            ->whereHas('queue', fn ($query) => $query->where('nation_id', $nation->id))
            ->where('status', 'queued')
            ->whereIn('target_context', ['surface_cell', 'underground_slot'])
            ->with(['definition', 'requestRulesetVersion'])
            ->get();

        foreach ($items as $item) {
            if ($item->target_context === 'surface_cell') {
                if ($item->command_definition_id !== null
                    && $item->definition instanceof CommandDefinition
                    && $item->definition->key !== $financeKey) {
                    return true;
                }

                continue;
            }

            if ($item->command_definition_id !== null
                || ! is_string($item->underground_command_key)
                || ! $item->requestRulesetVersion instanceof RulesetVersion) {
                continue;
            }
            if ($this->undergroundCommands->find(
                $item->requestRulesetVersion->settings,
                $item->underground_command_key,
            ) !== null) {
                return true;
            }
        }

        return false;
    }
}
