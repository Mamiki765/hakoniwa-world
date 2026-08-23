<?php

use App\Application\Ver240KarmaRecoveryRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (! Schema::hasColumn('nations', 'karma')) {
            Schema::table('nations', function (Blueprint $table): void {
                $table->integer('karma')->default(0)->after('idle_counter');
            });
            DB::statement(<<<'SQL'
ALTER TABLE nations
  ADD CONSTRAINT nations_karma_range_check CHECK (karma BETWEEN -10 AND 100)
SQL);
        }

        app(Ver240KarmaRecoveryRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The v12 to v13 KARMA/recovery migration is forward-only; restore the exact supported v12 backup and re-upgrade.',
        );
    }
};
