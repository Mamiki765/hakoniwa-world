<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            // Existing rows deliberately remain null. C5 owns any reviewed historical backfill.
            $table->foreignId('request_ruleset_version_id')
                ->nullable()
                ->after('command_definition_id')
                ->constrained('ruleset_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('request_ruleset_version_id');
        });
    }
};
