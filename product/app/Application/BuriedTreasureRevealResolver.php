<?php

namespace App\Application;

use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\BuriedTreasure;
use App\Models\TurnRun;
use App\Models\World;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class BuriedTreasureRevealResolver
{
    /**
     * @param  Collection<int, BuriedTreasure>  $treasures
     * @param  array<string, mixed>  $settings
     * @return array<int, true>
     */
    public function remoteVisibleCellIds(
        World $world,
        int $viewerNationId,
        Collection $treasures,
        array $settings,
    ): array {
        if ($viewerNationId < 1 || $treasures->isEmpty()) {
            return [];
        }
        $probability = $settings['remote_reveal_probability'] ?? null;
        $streamVersion = $settings['stream_version'] ?? null;
        if (! is_array($probability)
            || ! is_int($probability['numerator'] ?? null)
            || ! is_int($probability['denominator'] ?? null)
            || $probability['numerator'] < 0
            || $probability['denominator'] < 1
            || $probability['numerator'] > $probability['denominator']
            || ! is_int($streamVersion)
            || $streamVersion < 1) {
            throw new DomainException('The active Ruleset has an invalid remote Buried Treasure reveal contract.');
        }

        $run = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('target_turn', $world->current_turn)
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('is_dry_run', false)
            ->where('status', TurnRun::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->first();
        if (! $run instanceof TurnRun) {
            return [];
        }

        $random = new TurnRandomStreamFactory($run->random_seed);
        $visible = [];
        $cellIds = $treasures->pluck('map_cell_id')
            ->map(static fn ($cellId): int => (int) $cellId)
            ->unique()
            ->sort()
            ->values();
        foreach ($cellIds as $cellId) {
            $draw = $random->stream(TurnRandomStreamFactory::treasureCellReveal(
                $cellId,
                $viewerNationId,
                $streamVersion,
            ))->integer(0, $probability['denominator'] - 1);
            if ($draw < $probability['numerator']) {
                $visible[$cellId] = true;
            }
        }

        return $visible;
    }
}
