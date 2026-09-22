<?php

namespace App\Application\Underground;

use App\Models\Secretary;
use App\Models\UndergroundProfile;
use Illuminate\Support\Facades\DB;

final class UndergroundProfileService
{
    public function ensureForSecretary(Secretary $secretary): UndergroundProfile
    {
        return DB::transaction(function () use ($secretary): UndergroundProfile {
            return UndergroundProfile::query()->firstOrCreate([
                'secretary_id' => $secretary->id,
            ]);
        }, 3);
    }

    /** The caller holds the transaction through its entire underground mutation. */
    public function lockForSecretary(Secretary $secretary): UndergroundProfile
    {
        $profile = $this->ensureForSecretary($secretary);
        $locked = UndergroundProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
        app(UndergroundRequestAdmission::class)->assertLockedProfile($locked);
        $locked->setRelation('secretary', $secretary);

        return $locked;
    }
}
