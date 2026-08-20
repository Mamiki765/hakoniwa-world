<?php

namespace App\Domain\Command;

use App\Models\NationCommandQueueItem;

final class HistoricalMonsterDispatchRequestInspector
{
    public function inspect(NationCommandQueueItem $item): HistoricalMonsterDispatchRequestInspection
    {
        $definition = $item->relationLoaded('definition') ? $item->definition : $item->definition()->first();
        $ruleset = $definition?->relationLoaded('rulesetVersion')
            ? $definition->rulesetVersion
            : $definition?->rulesetVersion()->first();
        $historicalTarget = $item->parameters;
        $valid = $definition !== null
            && $ruleset !== null
            && $definition->key === 'monster_dispatch'
            && $definition->target_type === 'nation'
            && ($definition->metadata['monster_key'] ?? null) === 'mecha_inora'
            && $ruleset->key === 'hakoniwa-2s-plus-v10'
            && $ruleset->version === 10
            && $item->quantity === 1
            && in_array($item->status, ['queued', 'completed', 'failed', 'cancelled'], true)
            && array_keys($historicalTarget) === ['target_nation_id']
            && is_int($historicalTarget['target_nation_id'])
            && is_int($item->target_x)
            && is_int($item->target_y)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $item->request_key) === 1
            && ($item->request_fingerprint === null
                || preg_match('/^[0-9a-f]{64}$/', $item->request_fingerprint) === 1);

        return new HistoricalMonsterDispatchRequestInspection(
            proven: $valid,
            requestRulesetVersionId: $valid ? (int) $ruleset->id : null,
            selector: $valid ? 1 : null,
            reason: $valid ? 'exact_v10_mecha_dispatch_request' : 'ambiguous_or_non_historical_request',
        );
    }
}
