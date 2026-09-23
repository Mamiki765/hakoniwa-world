<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_monument_designs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name', 40)->default('オリジナル記念碑');
            $table->string('image_path', 96)->nullable();
            $table->timestamps();
        });

        Schema::table('map_cells', function (Blueprint $table): void {
            $table->foreignId('monument_design_id')->nullable()
                ->constrained('user_monument_designs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Placed original monuments must be preserved; use a reviewed forward migration.');
    }
};
