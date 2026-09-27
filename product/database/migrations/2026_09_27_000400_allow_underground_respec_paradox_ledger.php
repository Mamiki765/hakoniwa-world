<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement('ALTER TABLE user_paradox_ledger DROP CONSTRAINT user_paradox_ledger_source_check');
        DB::statement("ALTER TABLE user_paradox_ledger ADD CONSTRAINT user_paradox_ledger_source_check CHECK (source_kind IN ('daily_login', 'daily_quest', 'command', 'compensation', 'underground_respec'))");
    }

    public function down(): void
    {
        throw new RuntimeException('The Paradox ledger source extension is forward-only; restore the verified pre-migration backup.');
    }
};
