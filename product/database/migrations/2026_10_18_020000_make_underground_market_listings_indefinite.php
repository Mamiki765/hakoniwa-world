<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL retains the DDL's table lock until this migration commits.
        // Only the deadline changes; terminal history and item ownership stay intact.
        DB::statement('ALTER TABLE underground_market_listings ALTER COLUMN expires_at DROP NOT NULL');
        DB::table('underground_market_listings')->where('status', 'active')->update(['expires_at' => null]);
    }

    public function down(): void
    {
        throw new RuntimeException('Indefinite listings cannot regain an invented deadline. Restore a reviewed backup instead.');
    }
};
