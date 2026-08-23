<?php

namespace Tests\Feature;

use App\Models\Secretary;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecretaryEquipmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_equipment_versions_below_one(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);

        try {
            DB::table('secretaries')->where('id', $secretary->id)->update(['equipment_version' => 0]);
            $this->fail('Expected the database equipment version check to reject zero.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

    }
}
