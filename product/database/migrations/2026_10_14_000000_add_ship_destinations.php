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
        Schema::table('ships', function (Blueprint $table): void {
            $table->integer('destination_x')->nullable();
            $table->integer('destination_y')->nullable();
        });
        DB::statement('ALTER TABLE ships ADD CONSTRAINT ships_course_check CHECK (
            (destination_x IS NULL AND destination_y IS NULL)
            OR (destination_x IS NOT NULL AND destination_y IS NOT NULL AND heading IS NULL)
        )');
    }

    public function down(): void
    {
        throw new RuntimeException('Ship destinations are forward-only. Restore a reviewed backup instead.');
    }
};
