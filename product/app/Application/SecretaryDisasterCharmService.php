<?php

namespace App\Application;

use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use DomainException;

final class SecretaryDisasterCharmService
{
    public function __construct(private readonly TurnEventRecorder $events) {}

    public function protect(TurnContext $context, MapCell $cell, string $disasterKey): bool
    {
        $nationId = $cell->owner_nation_id;
        if ($nationId === null || ! $context->state->hasSecretaryItemEffectSnapshot($nationId)) {
            return false;
        }
        $snapshot = $context->state->secretaryItemEffectSnapshot($nationId);
        foreach ($snapshot['items'] as $item) {
            $used = $context->state->secretaryCharmChargesUsed($item['item_instance_id']);
            if ($used >= $item['level']) {
                continue;
            }
            foreach ($item['effects'] as $effect) {
                if ($effect['type'] !== 'disaster_guard'
                    || ($effect['parameters']['disaster_key'] ?? null) !== $disasterKey) {
                    continue;
                }
                if (($effect['parameters']['cells_per_charge'] ?? null) !== 1) {
                    throw new DomainException('Secretary disaster charm snapshot is invalid.');
                }
                $context->state->recordSecretaryCharmCharge($item['item_instance_id']);
                $this->events->record($context, 'secretary.disaster_charm_protected', $cell, [
                    'nation_id' => $nationId,
                    'disaster_key' => $disasterKey,
                    'item_key' => $item['item_key'],
                    'x' => $cell->x,
                    'y' => $cell->y,
                    'remaining_level' => $item['level'] - $used - 1,
                ], 'nation');

                return true;
            }
        }

        return false;
    }
}
