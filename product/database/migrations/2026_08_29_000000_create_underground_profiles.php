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
        Schema::create('underground_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->unique()->constrained('secretaries')->cascadeOnDelete();
            $table->unsignedInteger('unlocked_area_layers')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_unlocked_area_layers_non_negative
  CHECK (unlocked_area_layers >= 0)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground profile persistence is forward-only.');
    }
};
