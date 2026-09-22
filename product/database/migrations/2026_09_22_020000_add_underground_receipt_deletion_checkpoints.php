<?php

use App\Application\Underground\UndergroundLifetimeStatistics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('underground_receipt_rollups', function (Blueprint $table): void {
            $table->unsignedBigInteger('deleted_through_id')->default(0);
            $table->unsignedBigInteger('deleted_receipt_count')->default(0);
            $table->jsonb('last_deletion')->nullable();
        });
        DB::statement(<<<'SQL'
ALTER TABLE underground_receipt_rollups
 DROP CONSTRAINT underground_receipt_rollups_stream_check,
 ADD CONSTRAINT underground_receipt_rollups_stream_check CHECK (stream IN ('battle', 'skip', 'bulk_skip', 'intro_request')),
 ADD CONSTRAINT underground_receipt_rollups_deletion_check CHECK (
   deleted_through_id BETWEEN 0 AND verified_through_id
   AND deleted_receipt_count BETWEEN 0 AND receipt_count
 )
SQL);
        Schema::table('underground_intro_requests', function (Blueprint $table): void {
            // Requests have their own verified/deleted prefix. Deleting a battle
            // must not silently delete an unverified request in another stream.
            $table->dropForeign(['underground_battle_id']);
            $table->foreign('underground_battle_id')->references('id')->on('underground_battles')->nullOnDelete();
            $table->index(['underground_profile_id', 'id'], 'underground_intro_requests_rollup_cursor_index');
        });
        // D4 review added the existing party totals alongside self attribution.
        // An intermediate D2 install has never purged receipts; extend it once.
        foreach (DB::table('underground_receipt_rollups')->where('stream', 'battle')
            ->whereRaw("lifetime_statistics->'party' IS NULL")
            ->orderBy('underground_profile_id')->cursor() as $row) {
            $statistics = app(UndergroundLifetimeStatistics::class)->range((int) $row->underground_profile_id, 0, (int) $row->verified_through_id);
            DB::table('underground_receipt_rollups')->where('underground_profile_id', $row->underground_profile_id)->where('stream', 'battle')
                ->update(['lifetime_statistics' => json_encode($statistics, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Deleted receipts cannot be restored by a schema rollback.');
    }
};
