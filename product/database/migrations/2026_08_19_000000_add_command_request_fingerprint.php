<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->char('request_fingerprint', 64)->nullable()->after('request_key');
        });
        DB::statement(
            'ALTER TABLE nation_command_queue_items ADD CONSTRAINT nation_command_queue_items_request_fingerprint_check '
            ."CHECK (request_fingerprint IS NULL OR request_fingerprint ~ '^[0-9a-f]{64}$')",
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The command request fingerprint migration is forward-only; historical proof must not be discarded.',
        );
    }
};
