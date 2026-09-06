<?php

use App\Application\Ver370RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Append-only 3.7.0 release migration from the immutable v20 snapshot to v21. */
return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE monster_definitions
  DROP CONSTRAINT monster_definitions_spawn_tier_check,
  ADD CONSTRAINT monster_definitions_spawn_tier_check
    CHECK (natural_spawn_tier IS NULL OR natural_spawn_tier BETWEEN 1 AND 4)
SQL);

        app(Ver370RulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.7.0 Ruleset migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
