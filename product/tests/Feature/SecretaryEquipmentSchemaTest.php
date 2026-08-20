<?php

namespace Tests\Feature;

use App\Application\SecretaryItemGrantService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Secretary;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecretaryEquipmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_migration_backfills_existing_secretaries_and_defaults_future_secretaries_to_version_one(): void
    {
        $existing = Secretary::query()->create(['user_id' => User::factory()->create()->id]);

        DB::statement('ALTER TABLE secretaries DROP COLUMN equipment_version');
        $this->equipmentMigration()->up();

        $this->assertSame(1, $existing->fresh()->equipment_version);
        $future = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        $this->assertSame(1, $future->refresh()->equipment_version);
    }

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

    public function test_historical_item_migration_rerun_keeps_the_exact_starter_bow_without_ring_or_equipment_service_dependency(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        $historical = require database_path('migrations/2026_08_17_010000_create_secretary_items_and_inquiries.php');

        $historical->up();
        $historical->up();

        $this->assertSame(1, $secretary->itemInstances()->count());
        $this->assertDatabaseHas('secretary_item_instances', [
            'secretary_id' => $secretary->id,
            'item_key' => SecretaryItemCatalog::OLD_BOW,
            'level' => 1,
            'equipped_slot' => 1,
            'grant_key' => SecretaryItemGrantService::STARTER_OLD_BOW_GRANT,
        ]);
        $this->assertDatabaseMissing('secretary_item_instances', ['item_key' => 'ring']);
        $this->assertSame(1, $secretary->fresh()->equipment_version);
    }

    private function equipmentMigration(): Migration
    {
        return require database_path('migrations/2026_08_20_000000_add_secretary_equipment_version.php');
    }
}
