<?php

use App\Application\Ver240DormancyRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (! Schema::hasColumn('nations', 'state_reason')) {
            Schema::table('nations', function (Blueprint $table): void {
                $table->string('state_reason')->nullable()->after('state');
                $table->unsignedBigInteger('state_started_turn')->nullable()->after('state_reason');
                $table->unsignedBigInteger('resume_at_turn')->nullable()->after('state_started_turn');
            });
            DB::statement(<<<'SQL'
ALTER TABLE nations
  ADD CONSTRAINT nations_lifecycle_state_check
    CHECK (state IN ('active', 'dormant', 'recovery', 'abandoned')),
  ADD CONSTRAINT nations_lifecycle_context_check
    CHECK (
      (state = 'active' AND state_reason IS NULL AND state_started_turn IS NULL AND resume_at_turn IS NULL)
      OR (state = 'dormant' AND state_reason IN ('idle', 'collapse', 'manual') AND state_started_turn IS NOT NULL
          AND ((state_reason = 'manual' AND resume_at_turn IS NOT NULL AND resume_at_turn > state_started_turn)
               OR (state_reason <> 'manual' AND resume_at_turn IS NULL)))
      OR (state = 'recovery' AND state_reason IS NULL AND state_started_turn IS NOT NULL AND resume_at_turn IS NOT NULL
          AND resume_at_turn > state_started_turn)
      OR (state = 'abandoned' AND state_reason IS NULL AND state_started_turn IS NULL AND resume_at_turn IS NULL)
    )
SQL);
        }
        DB::statement('ALTER TABLE nations ALTER COLUMN idle_counter SET DEFAULT 2000');

        app(Ver240DormancyRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The v11 to v12 Nation dormancy migration is forward-only; restore the exact supported v11 backup and re-upgrade.',
        );
    }
};
