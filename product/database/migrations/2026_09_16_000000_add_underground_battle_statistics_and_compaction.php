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
        Schema::table('underground_battles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('statistics_version')->nullable()->after('healing_done');
            $table->jsonb('statistics')->nullable()->after('statistics_version');
            $table->unsignedSmallInteger('compaction_version')->nullable()->after('snapshot');
            $table->timestampTz('compacted_at')->nullable()->after('compaction_version');
            $table->index(
                ['activity_type', 'statistics_version', 'finished_at'],
                'underground_battles_statistics_range_index',
            );
        });
        Schema::table('underground_battle_logs', function (Blueprint $table): void {
            $table->jsonb('presentation')->nullable()->after('actions');
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_battles
  ADD CONSTRAINT underground_battles_statistics_pair_check
  CHECK ((statistics_version IS NULL) = (statistics IS NULL)),
  ADD CONSTRAINT underground_battles_statistics_version_positive
  CHECK (statistics_version IS NULL OR statistics_version >= 1),
  ADD CONSTRAINT underground_battles_compaction_pair_check
  CHECK ((compaction_version IS NULL) = (compacted_at IS NULL)),
  ADD CONSTRAINT underground_battles_compaction_version_positive
  CHECK (compaction_version IS NULL OR compaction_version >= 1)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The 4.2.1 Underground statistics and compaction migration is forward-only.');
    }
};
