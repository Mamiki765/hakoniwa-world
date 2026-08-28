<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundProfileService;
use App\Models\Secretary;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UndergroundPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_has_one_lazy_profile_with_zero_default_and_no_surface_identity(): void
    {
        $secretary = $this->secretary();
        $service = app(UndergroundProfileService::class);

        $first = $service->ensureForSecretary($secretary)->refresh();
        $second = $service->ensureForSecretary($secretary);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(0, $first->unlocked_area_layers);
        $this->assertSame($first->id, $secretary->undergroundProfile()->sole()->id);
        $this->assertSame(1, UndergroundProfile::query()->where('secretary_id', $secretary->id)->count());

        $columns = Schema::getColumnListing('underground_profiles');
        sort($columns);
        $this->assertSame([
            'created_at',
            'id',
            'secretary_id',
            'unlocked_area_layers',
            'updated_at',
        ], $columns);
    }

    public function test_unlocked_layers_persist_and_derive_four_facility_slots_per_layer(): void
    {
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($this->secretary());

        $profile->update(['unlocked_area_layers' => 3]);
        $persisted = $profile->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame(3, $persisted->unlocked_area_layers);
        $this->assertSame(12, $persisted->facilitySlotCapacity());
        $this->assertSame(0, DB::table('underground_profiles')->whereNotNull('secretary_id')
            ->where('unlocked_area_layers', '<', 0)->count());
    }

    public function test_database_enforces_one_nonnegative_profile_and_secretary_delete_cascades(): void
    {
        $secretary = $this->secretary();
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);

        foreach ([
            static fn () => UndergroundProfile::query()->create(['secretary_id' => $secretary->id]),
            static fn () => $profile->update(['unlocked_area_layers' => -1]),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected the Underground profile database constraint to reject the mutation.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $profile->fresh()?->unlocked_area_layers);
        $secretary->delete();
        $this->assertDatabaseMissing('underground_profiles', ['id' => $profile->id]);
    }

    private function secretary(): Secretary
    {
        return Secretary::query()->create([
            'user_id' => User::factory()->create()->id,
        ]);
    }
}
