<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement('ALTER TABLE nations DROP CONSTRAINT nations_world_id_name_unique');
        DB::statement("CREATE UNIQUE INDEX nations_world_id_name_unique ON nations (world_id, name) WHERE state <> 'abandoned'");
    }

    public function down(): void
    {
        throw new RuntimeException('Nation name reuse is forward-only: rolling back could conflict with preserved historical names.');
    }
};
