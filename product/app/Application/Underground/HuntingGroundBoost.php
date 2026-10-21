<?php

namespace App\Application\Underground;

use App\Models\BoostOccurrence;
use App\Models\World;
use Carbon\CarbonImmutable;

/** One immutable acceptance-time effect. Never re-query during combat, bulk execution or transaction retry. */
final readonly class HuntingGroundBoost
{
    /** @param list<int> $occurrenceIds */
    public function __construct(public array $occurrenceIds = [], public ?string $acceptedAt = null) {}

    /** @param array<string,mixed> $ground */
    public static function capture(array $ground): self
    {
        $now = CarbonImmutable::now();
        if (($ground['kind'] ?? 'hunting_ground') !== 'hunting_ground') {
            return new self([], $now->toIso8601String());
        }
        $worldId = World::query()->where('key', config('hakoniwa.world.key'))->value('id');
        $ids = BoostOccurrence::query()->where('world_id', $worldId)->where('kind', 'hunting_ground')
            ->whereNull('cancelled_at')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->orderBy('id')->pluck('id')->all();

        return new self($ids, $now->toIso8601String());
    }

    public function multiplier(): int
    {
        return $this->occurrenceIds === [] ? 1 : 2;
    }

    public function chance(int $basisPoints): int
    {
        return min(10_000, $basisPoints * $this->multiplier());
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return ['accepted_at' => $this->acceptedAt, 'occurrence_ids' => $this->occurrenceIds,
            'xp_multiplier' => $this->multiplier(), 'rare_encounter_multiplier' => $this->multiplier(),
            'normal_key_chance_multiplier' => $this->multiplier()];
    }
}
