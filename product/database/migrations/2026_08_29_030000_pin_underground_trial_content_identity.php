<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('underground_trial_runs', function (Blueprint $table): void {
            $table->string('trial_content_identity', 128)->nullable()->after('trial_key');
        });

        DB::statement(<<<'SQL'
UPDATE underground_trial_runs
   SET trial_content_identity = CASE trial_key
       WHEN 'trial_01' THEN 'trial-01-v1'
       WHEN 'trial_02' THEN 'trial-02-v1'
   END
 WHERE trial_key IN ('trial_01', 'trial_02')
SQL);

        if (DB::table('underground_trial_runs')->whereNull('trial_content_identity')->exists()) {
            throw new RuntimeException('An Underground trial run has no supported content identity.');
        }

        DB::statement(<<<'SQL'
ALTER TABLE underground_trial_runs
  ALTER COLUMN trial_content_identity SET NOT NULL,
  ADD CONSTRAINT underground_trial_runs_content_identity_not_empty
  CHECK (char_length(trial_content_identity) > 0)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground Trial content identity persistence is forward-only.');
    }
};
