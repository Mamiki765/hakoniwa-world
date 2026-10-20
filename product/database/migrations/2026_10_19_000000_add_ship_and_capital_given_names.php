<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        // Appended nullable names: unnamed rows keep every existing display and log.
        DB::statement('ALTER TABLE ships ADD COLUMN custom_name varchar(16)');
        DB::statement('ALTER TABLE nations ADD COLUMN capital_name varchar(16)');
    }

    public function down(): void
    {
        throw new RuntimeException('Given names are forward-only. Restore a reviewed backup instead.');
    }
};
