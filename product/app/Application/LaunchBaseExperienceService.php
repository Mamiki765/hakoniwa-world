<?php

namespace App\Application;

use App\Domain\Facility\MissileBaseRules;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use App\Models\Nation;
use DomainException;

final class LaunchBaseExperienceService
{
    public function __construct(private readonly MissileBaseRules $rules) {}

    public function credit(
        MapCell $firingBase,
        Nation $owner,
        int $experience,
        TurnContext $context,
    ): int {
        if ($experience < 0) {
            throw new DomainException('Launch-base experience credit must not be negative.');
        }
        if ($experience === 0) {
            return 0;
        }

        $base = MapCell::query()->whereKey($firingBase->id)
            ->with('facility')->lockForUpdate()->firstOrFail();
        $experienceContract = $context->ruleset->settings['military']['launch_base_experience'] ?? null;
        $experiencedKeys = is_array($experienceContract)
            ? ($experienceContract['facility_keys'] ?? [])
            : ['missile_base'];
        if ($base->owner_nation_id !== $owner->id
            || ! in_array($base->facility?->key, $experiencedKeys, true)
            || $base->facility_experience === null) {
            throw new DomainException('Firing base must be an experienced launch base owned by the firing Nation.');
        }

        $maximum = $this->rules->maximumExperience($base->facility);
        $before = (int) $base->facility_experience;
        $after = min($maximum, $before + $experience);
        $applied = $after - $before;
        if ($applied > 0) {
            $base->facility_experience = $after;
            $base->version++;
            $base->save();
            $context->state->markMapChunkChanged($base->map_chunk_id);
        }

        return $applied;
    }
}
