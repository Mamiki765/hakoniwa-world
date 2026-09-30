<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('underground_owned_equipment')
            ->where('definition_key', 'excalibur')
            ->where('instance_kind', 'fixed')
            ->where('catalog_identity', 'secretary-underground-shop-equipment-alpha-v4')
            ->update(['catalog_identity' => 'secretary-underground-shop-equipment-alpha-v5']);
    }

    public function down(): void
    {
        throw new RuntimeException('The Excalibur catalog upgrade is forward-only; restore the verified pre-migration backup.');
    }
};
