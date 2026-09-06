<?php

namespace App\Application;

use App\Domain\Facility\FacilityRankPolicy;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use DomainException;

final readonly class FacilityScaleDamageService
{
    public function __construct(
        private FacilityRankPolicy $ranks,
        private TurnEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{facility_key: string, before_scale: int, after_scale: int, scale_loss: int, rank_before: 2, rank_after: 1|2}|null
     */
    public function apply(
        TurnContext $context,
        MapCell $cell,
        string $damageKind,
        string $sourceKey,
        array $metadata = [],
    ): ?array {
        $facility = $cell->facility;
        if ($facility === null) {
            return null;
        }
        $before = $cell->facility_scale;
        $loss = $this->ranks->damageScaleLoss(
            $context->ruleset->settings,
            $facility->key,
            $before,
            $damageKind,
        );
        if ($loss === null) {
            return null;
        }
        if (! is_int($before)) {
            throw new DomainException('Rank-two facility damage requires an authored current scale.');
        }
        $after = max(0, $before - $loss);
        $result = [
            'facility_key' => $facility->key,
            'before_scale' => $before,
            'after_scale' => $after,
            'scale_loss' => $loss,
            'rank_before' => 2,
            'rank_after' => $this->ranks->rank($context->ruleset->settings, $facility->key, $after),
        ];
        if ($loss === 0) {
            return $result;
        }

        $cell->facility_scale = $after;
        $cell->version++;
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, 'facility.partially_damaged', $cell, [
            'nation_id' => $cell->owner_nation_id,
            'x' => $cell->x,
            'y' => $cell->y,
            'damage_kind' => $damageKind,
            'source_key' => $sourceKey,
            ...$result,
            ...$metadata,
        ], 'public');

        return $result;
    }
}
