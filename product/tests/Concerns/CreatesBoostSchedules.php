<?php

namespace Tests\Concerns;

use App\Models\BoostOccurrence;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesBoostSchedules
{
    protected function boostOccurrence(string $kind = 'hunting_ground', ?World $world = null): BoostOccurrence
    {
        $world ??= World::query()->firstOrCreate(['key' => config('hakoniwa.world.key')], [
            'name' => 'Boost test', 'ruleset_version_id' => RulesetVersion::query()->firstOrFail()->id, 'current_turn' => 1]);
        $actor = User::factory()->create();
        $id = DB::table('boost_series')->insertGetId(['world_id' => $world->id, 'created_by' => $actor->id,
            'request_id' => (string) Str::uuid(), 'kind' => $kind, 'title' => 'Boost test',
            'schedule' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return BoostOccurrence::query()->create(['world_id' => $world->id, 'boost_series_id' => $id,
            'occurrence_number' => 1, 'kind' => $kind, 'starts_at' => now(), 'ends_at' => now()->addHours(20)]);
    }
}
