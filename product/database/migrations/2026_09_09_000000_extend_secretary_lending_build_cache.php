<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('secretary_lending_build_snapshots', function (Blueprint $table): void {
            $table->jsonb('projection_cache')->nullable();
        });
        DB::statement(<<<'SQL'
ALTER TABLE secretary_lending_build_snapshots
  ADD CONSTRAINT secretary_lending_build_snapshots_projection_cache_check
  CHECK (projection_cache IS NULL OR jsonb_typeof(projection_cache) = 'object')
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The 3.8.0 Secretary lending projection cache migration is forward-only.');
    }
};
