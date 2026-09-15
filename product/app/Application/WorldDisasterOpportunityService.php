<?php

namespace App\Application;

use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapSpace;
use DomainException;

final class WorldDisasterOpportunityService
{
    /**
     * @param  array<string, mixed>|null  $scale
     * @return array{count:int,full:int,chunk_count:int,scale_numerator:int,fractional_numerator:int,fractional_draw:int|null}
     */
    public function resolve(TurnContext $context, MapSpace $space, string $key, ?array $scale = null): array
    {
        $baseChunks = $scale['base_chunks'] ?? 225;
        $opportunitiesPerBase = $scale['opportunities_per_base'] ?? 16;
        if (! is_int($baseChunks) || $baseChunks < 1
            || ! is_int($opportunitiesPerBase) || $opportunitiesPerBase < 1) {
            throw new DomainException('World disaster area scale is invalid.');
        }
        $chunkCount = $space->currentBounds()->chunkCount();
        $scaleNumerator = $opportunitiesPerBase * $chunkCount;
        $full = intdiv($scaleNumerator, $baseChunks);
        $fractionalNumerator = $scaleNumerator % $baseChunks;
        $count = $full;
        $draw = null;
        if ($fractionalNumerator > 0) {
            $draw = $context->random->stream(TurnRandomStreamFactory::worldDisasterAreaFraction($key))
                ->integer(0, $baseChunks - 1);
            if ($draw < $fractionalNumerator) {
                $count++;
            }
        }
        if ($count < 0) {
            throw new DomainException('World disaster opportunity count is invalid.');
        }

        return [
            'count' => $count,
            'full' => $full,
            'chunk_count' => $chunkCount,
            'scale_numerator' => $scaleNumerator,
            'fractional_numerator' => $fractionalNumerator,
            'fractional_draw' => $draw,
        ];
    }
}
