<?php

namespace App\Application;

use App\Application\Underground\UndergroundFacilityBenefits;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretaryDemographicPolicy;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;

final class NationRefugeeReceptionService
{
    public function __construct(
        private readonly SecretaryDemographicPolicy $demographics,
        private readonly UndergroundFacilityBenefits $undergroundBenefits,
        private readonly MapCellStateService $cells,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function receive(
        TurnContext $context,
        Nation $recipient,
        MapCell $source,
        int $generated,
        string $sourceKey,
        array $metadata = [],
    ): int {
        if ($generated < 1) {
            return 0;
        }
        $settlementKeys = $context->ruleset->settings['military']['refugees']['settlement_facility_keys'] ?? [];
        $attractionMaximum = $context->ruleset->settings['turn_processing']['settlement']['attraction_maximum_population'];
        if ($this->demographics->enabled($context->ruleset->settings)
            && $context->state->hasSecretarySnapshot($recipient->id)) {
            $attractionMaximum = $this->demographics->attractionMaximum(
                $context->ruleset->settings,
                $attractionMaximum,
                $context->state->secretarySkillLevel($recipient->id, SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY),
            );
        }
        $cells = MapCell::query()->where('owner_nation_id', $recipient->id)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $settlementKeys))
            ->with(['terrain', 'facility'])->orderBy('id')->lockForUpdate()->get()
            ->sortByDesc(fn (MapCell $cell): bool => $cell->facility?->key === 'capital');
        $remaining = $generated;
        foreach ($cells as $cell) {
            $maximum = $cell->facility?->key === 'capital'
                ? $context->ruleset->settings['capital_growth_maximum_population']
                    + $this->undergroundBenefits->capitalMaximumBonusForTurn($context->state, $recipient->id)
                : $attractionMaximum;
            $applied = min($remaining, max(0, $maximum - $cell->population));
            if ($applied < 1) {
                continue;
            }
            $cell->population += $applied;
            $this->syncSettlementFacility($context, $cell);
            $cell->version++;
            $cell->save();
            $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
            $remaining -= $applied;
            if ($remaining === 0) {
                break;
            }
        }
        $received = $generated - $remaining;
        $context->state->addRefugeesReceived((int) $recipient->id, $received);
        $this->events->record($context, 'refugee_received', $recipient, [
            'nation_id' => (int) $recipient->id,
            'source_key' => $sourceKey,
            'source_x' => (int) $source->x,
            'source_y' => (int) $source->y,
            'generated_population' => $generated,
            'received_population' => $received,
            'unreceived_population' => $remaining,
            ...$metadata,
        ], 'nation');

        return $received;
    }

    private function syncSettlementFacility(TurnContext $context, MapCell $cell): void
    {
        if ($cell->facility?->key === 'capital') {
            return;
        }
        foreach ($context->ruleset->settings['turn_processing']['settlement']['stages'] as $stage) {
            if ($cell->population >= $stage['minimum_population'] && $cell->population <= $stage['maximum_population']) {
                $this->cells->setFacility(
                    $cell,
                    FacilityDefinition::query()->where('key', $stage['facility_key'])->firstOrFail(),
                );

                return;
            }
        }
        $this->cells->setFacility($cell, FacilityDefinition::query()->where('key', 'city')->firstOrFail());
    }
}
