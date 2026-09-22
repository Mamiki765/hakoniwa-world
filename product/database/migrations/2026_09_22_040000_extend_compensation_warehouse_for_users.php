<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement('ALTER TABLE compensation_grants ALTER COLUMN nation_id DROP NOT NULL');
        DB::statement('ALTER TABLE compensation_grants ADD COLUMN expires_at timestamptz');
        // Existing receipts keep their amounts and claim history; the new deadline starts at upgrade.
        DB::statement("UPDATE compensation_grants SET expires_at = CURRENT_TIMESTAMP + INTERVAL '365 days'");
        DB::statement('ALTER TABLE compensation_grants ALTER COLUMN expires_at SET NOT NULL');
        DB::statement('ALTER TABLE compensation_grants DROP CONSTRAINT compensation_grants_status_check');
        DB::statement("ALTER TABLE compensation_grants ADD CONSTRAINT compensation_grants_status_check CHECK (status IN ('pending', 'partial', 'claimed', 'expired'))");
        DB::statement('CREATE INDEX compensation_grants_expiry_index ON compensation_grants (recipient_user_id, expires_at)');
    }

    public function down(): void
    {
        throw new RuntimeException('User compensation grants and their deadlines are forward-only.');
    }
};
