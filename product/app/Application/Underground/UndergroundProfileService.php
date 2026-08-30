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
            $lockedSecretary = Secretary::query()->whereKey($secretary->id)->lockForUpdate()->firstOrFail();

            return UndergroundProfile::query()->firstOrCreate([
                'secretary_id' => $lockedSecretary->id,
            ]);
        }, 3);
    }
}
