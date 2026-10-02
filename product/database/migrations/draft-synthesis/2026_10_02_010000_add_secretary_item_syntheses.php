<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secretary_item_syntheses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ruleset_version_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('recipe_key');
            // IDs are receipts, not FKs to consumed or subsequently sold instances.
            $table->jsonb('ingredient_ids');
            $table->jsonb('result_snapshot');
            $table->timestamps();
            $table->unique(['secretary_id', 'request_key']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Item synthesis receipts are forward-only; restore the approved backup instead.');
    }
};
