<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('yunagi_harbor_key_balance')->default(0);
        });
        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_yunagi_harbor_key_non_negative
  CHECK (yunagi_harbor_key_balance >= 0)
SQL);
    }

    public function down(): void
    {
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->dropColumn('yunagi_harbor_key_balance');
        });
    }
};
