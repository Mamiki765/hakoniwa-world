<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secretary_gacha_draws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained('secretaries')->cascadeOnDelete();
            $table->uuid('request_key');
            $table->unsignedBigInteger('ticket_item_instance_id');
            $table->string('ticket_key', 64);
            $table->unsignedInteger('ticket_level');
            $table->jsonb('result_snapshot');
            $table->timestamps();
            $table->unique(['secretary_id', 'request_key']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Completed gacha draw history must be preserved; use a reviewed forward migration.');
    }
};
