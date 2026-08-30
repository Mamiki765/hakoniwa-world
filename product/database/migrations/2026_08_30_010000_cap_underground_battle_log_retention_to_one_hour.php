<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement(<<<'SQL'
UPDATE underground_battle_logs AS logs
   SET expires_at = battles.finished_at + INTERVAL '1 hour'
  FROM underground_battles AS battles
 WHERE battles.id = logs.underground_battle_id
   AND logs.expires_at > battles.finished_at + INTERVAL '1 hour'
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground battle log retention is forward-only.');
    }
};
