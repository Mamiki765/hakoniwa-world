<?php

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundIntroCatalog;
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
        // These identifiers become provenance, not links to live receipts.
        // Keep the existing exclusive source, reward index and uniqueness rules.
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            foreach (['source_battle_id', 'source_skip_settlement_id', 'source_skip_batch_id'] as $column) {
                $table->dropForeign([$column]);
            }
        });
        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment ADD CONSTRAINT underground_equipment_provenance_check CHECK (
    (source_battle_id IS NULL OR source_battle_id > 0)
    AND (source_skip_settlement_id IS NULL OR source_skip_settlement_id > 0)
    AND (source_skip_batch_id IS NULL OR source_skip_batch_id > 0)
    AND (instance_kind <> 'generated' OR source_battle_id IS NOT NULL OR source_reward_index IS NOT NULL)
)
SQL);

        Schema::table('user_skip_ticket_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('lifetime_participation_count')->default(0);
        });
        DB::statement('ALTER TABLE user_skip_ticket_balances ADD CONSTRAINT lending_lifetime_count_check CHECK (lifetime_participation_count >= 0)');
        // ALTER TABLE holds the same owner serialization table through backfill.
        DB::statement(<<<'SQL'
INSERT INTO user_skip_ticket_balances (user_id, balance, lifetime_participation_count, created_at, updated_at)
SELECT owner_user_id, 0, COUNT(*), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM secretary_lending_participations GROUP BY owner_user_id
ON CONFLICT (user_id) DO UPDATE SET lifetime_participation_count = EXCLUDED.lifetime_participation_count
SQL);
        Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
            foreach (['underground_skip_settlement_id' => 'underground_skip_settlements', 'underground_skip_batch_id' => 'underground_skip_batches'] as $column => $source) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on($source)->nullOnDelete();
            }
        });

        Schema::table('underground_intro_progress', function (Blueprint $table): void {
            // Existing non-null IDs and stage/branch remain the experience facts.
            $table->dropForeign(['tutorial_battle_id']);
            $table->dropForeign(['scripted_loss_battle_id']);
            $table->string('tutorial_encounter_key', 100)->nullable();
            $table->string('initial_growth_path_key', 64)->nullable();
        });
        DB::statement(<<<'SQL'
UPDATE underground_intro_progress AS p SET tutorial_encounter_key = b.encounter_key
FROM underground_battles AS b WHERE b.id = p.tutorial_battle_id
SQL);
        // Resolve only the recorded first choice. A later respec is not evidence.
        $storyIdentity = app(UndergroundIntroCatalog::class)->identity();
        foreach (app(UndergroundAlphaV1PlayerCatalog::class)->growthPaths() as $path) {
            $fingerprint = hash('sha256', json_encode([
                'story_identity' => $storyIdentity, 'operation' => 'growth_path',
                'payload' => ['growth_path_key' => $path['key']],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            DB::update(<<<'SQL'
UPDATE underground_intro_progress AS p SET initial_growth_path_key = ?
WHERE (SELECT r.request_fingerprint FROM underground_intro_requests AS r
       WHERE r.underground_profile_id = p.underground_profile_id AND r.operation = 'growth_path'
       ORDER BY r.id LIMIT 1) = ?
SQL, [$path['key'], $fingerprint]);
        }

        Schema::table('underground_trial_progress', function (Blueprint $table): void {
            $table->timestampTz('first_challenged_at')->nullable();
            $table->text('first_challenge_intro')->nullable();
            $table->jsonb('first_clear_story')->nullable();
        });
        DB::statement(<<<'SQL'
UPDATE underground_trial_progress AS p SET
 first_challenged_at = (SELECT b.started_at FROM underground_battles b
   WHERE b.underground_profile_id = p.underground_profile_id AND b.activity_type = 'trial'
     AND b.activity_key = p.trial_key ORDER BY b.id LIMIT 1),
 first_challenge_intro = (SELECT b.snapshot->>'challenge_intro' FROM underground_battles b
   WHERE b.underground_profile_id = p.underground_profile_id AND b.activity_type = 'trial'
     AND b.activity_key = p.trial_key AND b.trial_battle_index = 1
     AND jsonb_typeof(b.snapshot->'challenge_intro') = 'string' ORDER BY b.id LIMIT 1),
 first_clear_story = (SELECT b.snapshot->'first_clear_story' FROM underground_battles b
   WHERE b.underground_profile_id = p.underground_profile_id AND b.activity_type = 'trial'
     AND b.activity_key = p.trial_key AND jsonb_typeof(b.snapshot->'first_clear_story') = 'object'
     ORDER BY b.id LIMIT 1)
SQL);

        Schema::table('underground_receipt_rollups', function (Blueprint $table): void {
            $table->jsonb('lifetime_statistics')->nullable();
        });
        // D1 has not deleted any raw receipts. Extend each existing verified
        // prefix once; neither its journal totals nor its boundary are reset.
        $statistics = app(UndergroundLifetimeStatistics::class);
        foreach (DB::table('underground_receipt_rollups')->where('stream', 'battle')->orderBy('underground_profile_id')->cursor() as $row) {
            $totals = $statistics->range((int) $row->underground_profile_id, 0, (int) $row->verified_through_id);
            DB::table('underground_receipt_rollups')->where('underground_profile_id', $row->underground_profile_id)
                ->where('stream', 'battle')->update(['lifetime_statistics' => json_encode($totals, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Durable assets and first facts cannot be relinked to purged receipts; use a reviewed forward migration.');
    }
};
