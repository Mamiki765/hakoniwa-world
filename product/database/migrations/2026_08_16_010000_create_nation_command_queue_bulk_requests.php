<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_command_queue_bulk_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_command_queue_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('action');
            $table->unsignedInteger('position');
            $table->unsignedInteger('candidate_count');
            $table->unsignedInteger('inserted_count');
            $table->unsignedInteger('truncated_count');
            $table->timestampTz('created_at');
            $table->unique(
                ['nation_command_queue_id', 'request_key'],
                'nation_command_queue_bulk_request_unique',
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Command queue bulk request idempotency records are forward-only production state.',
        );
    }
};
