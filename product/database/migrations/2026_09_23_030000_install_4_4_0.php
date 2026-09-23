<?php

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundIntroCatalog;
use App\Application\Ver440RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        // Only the pre-4.4.0 schema is supported. Never infer or silently reset
        // data from a database that already applied the unreleased split drafts.
        if (Schema::hasTable('secretary_surface_states')) {
            throw new RuntimeException('A 4.4.0 draft schema is already installed. Do not replay the consolidated migration; use a reviewed conversion or the verified pre-4.4.0 backup.');
        }

        $this->isolateSecretarySurfaceState();
        $this->createReceiptRollups();
        $this->detachDurableReceiptDependencies();
        $this->extendUndergroundRequests();
        $this->extendAdministration();
        $this->extendUndergroundEquipment();
        $this->extendUndergroundProfiles();
        $this->createMonumentDesigns();
        $this->createGachaDraws();

        // Retain the exact-v26 guard, definition rebinds, provenance checks and
        // existing v27 activation. A failure rolls back the complete release.
        app(Ver440RulesetUpgrade::class)->run();
    }

    private function isolateSecretarySurfaceState(): void
    {
        DB::statement('LOCK TABLE secretaries IN ACCESS EXCLUSIVE MODE');
        Schema::create('secretary_surface_states', function (Blueprint $table): void {
            $table->foreignId('secretary_id')->primary()->constrained('secretaries')->cascadeOnDelete();
            $table->bigInteger('monster_experience')->default(0);
            $table->bigInteger('equipment_version')->default(1);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE secretary_surface_states ADD CONSTRAINT secretary_surface_experience_check CHECK (monster_experience >= 0), ADD CONSTRAINT secretary_surface_equipment_version_check CHECK (equipment_version >= 1)');
        DB::statement('INSERT INTO secretary_surface_states (secretary_id, monster_experience, equipment_version, created_at, updated_at) SELECT id, monster_experience, equipment_version, created_at, updated_at FROM secretaries');
        Schema::table('secretaries', function (Blueprint $table): void {
            $table->dropColumn(['monster_experience', 'equipment_version']);
        });
    }

    private function createReceiptRollups(): void
    {
        // D1/D2/D4 are one unpublished release: create the final shape directly.
        // There is no pre-existing rollup prefix to backfill on this entry path.
        Schema::create('underground_receipt_rollups', function (Blueprint $table): void {
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->string('stream', 16);
            $table->unsignedBigInteger('verified_through_id');
            $table->unsignedSmallInteger('aggregation_version');
            foreach ([
                'receipt_count', 'battle_count', 'victory_count',
                'damage_dealt_sum', 'damage_dealt_known_count',
                'damage_received_sum', 'damage_received_known_count', 'skip_tickets_used',
            ] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->jsonb('last_batch');
            $table->timestampTz('verified_at');
            $table->timestampsTz();
            $table->primary(['underground_profile_id', 'stream'], 'underground_receipt_rollups_profile_stream_primary');
            $table->jsonb('lifetime_statistics')->nullable();
            $table->unsignedBigInteger('deleted_through_id')->default(0);
            $table->unsignedBigInteger('deleted_receipt_count')->default(0);
            $table->jsonb('last_deletion')->nullable();
        });
        DB::statement(<<<'SQL'
ALTER TABLE underground_receipt_rollups
  ADD CONSTRAINT underground_receipt_rollups_stream_check CHECK (stream IN ('battle', 'skip', 'bulk_skip', 'intro_request')),
  ADD CONSTRAINT underground_receipt_rollups_counts_check CHECK (
    verified_through_id > 0 AND aggregation_version > 0
    AND receipt_count > 0 AND battle_count BETWEEN 0 AND receipt_count
    AND victory_count BETWEEN 0 AND battle_count
    AND damage_dealt_known_count BETWEEN 0 AND battle_count
    AND damage_received_known_count BETWEEN 0 AND battle_count
    AND damage_dealt_sum >= 0 AND damage_received_sum >= 0 AND skip_tickets_used >= 0
  ),
  ADD CONSTRAINT underground_receipt_rollups_deletion_check CHECK (
    deleted_through_id BETWEEN 0 AND verified_through_id
    AND deleted_receipt_count BETWEEN 0 AND receipt_count
  )
SQL);
        foreach (['underground_battles', 'underground_skip_settlements', 'underground_skip_batches'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->index(['underground_profile_id', 'id'], $name.'_rollup_cursor_index');
            });
        }
    }

    private function detachDurableReceiptDependencies(): void
    {
        // Source identifiers remain durable provenance after a receipt is purged.
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
            $table->dropForeign(['tutorial_battle_id']);
            $table->dropForeign(['scripted_loss_battle_id']);
            $table->string('tutorial_encounter_key', 100)->nullable();
            $table->string('initial_growth_path_key', 64)->nullable();
        });
        DB::statement(<<<'SQL'
UPDATE underground_intro_progress AS p SET tutorial_encounter_key = b.encounter_key
FROM underground_battles AS b WHERE b.id = p.tutorial_battle_id
SQL);
        // Only the recorded first choice is evidence, not a later respec.
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
    }

    private function extendUndergroundRequests(): void
    {
        Schema::table('underground_intro_requests', function (Blueprint $table): void {
            $table->dropForeign(['underground_battle_id']);
            $table->foreign('underground_battle_id')->references('id')->on('underground_battles')->nullOnDelete();
            $table->index(['underground_profile_id', 'id'], 'underground_intro_requests_rollup_cursor_index');
            $table->jsonb('result_payload')->nullable();
        });
        // Install the final allowed operations once, rather than replacing this
        // constraint for guide conversation, stone purchase and polishing in turn.
        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  DROP CONSTRAINT underground_intro_requests_operation_check,
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer', 'playtest',
    'stp_allocate', 'skill_acquire', 'active_loadout', 'awakening_message',
    'equipment_purchase', 'equipment_sell', 'equipment_equip', 'equipment_unequip',
    'respec', 'equipment_bulk_sell', 'ai_configuration', 'awakening_technique',
    'recollection_read', 'rental_party', 'residence_purchase', 'lounge_event', 'home_background',
    'guide_conversation', 'distorted_stone_purchase', 'equipment_polish'
  ))
SQL);
        DB::statement("ALTER TABLE underground_intro_requests ADD CONSTRAINT underground_intro_requests_result_check CHECK ((operation = 'guide_conversation') = (result_payload IS NOT NULL))");
    }

    private function extendAdministration(): void
    {
        DB::statement('ALTER TABLE compensation_grants ALTER COLUMN nation_id DROP NOT NULL');
        DB::statement('ALTER TABLE compensation_grants ADD COLUMN expires_at timestamptz');
        DB::statement("UPDATE compensation_grants SET expires_at = CURRENT_TIMESTAMP + INTERVAL '365 days'");
        DB::statement('ALTER TABLE compensation_grants ALTER COLUMN expires_at SET NOT NULL');
        DB::statement('ALTER TABLE compensation_grants DROP CONSTRAINT compensation_grants_status_check');
        DB::statement("ALTER TABLE compensation_grants ADD CONSTRAINT compensation_grants_status_check CHECK (status IN ('pending', 'partial', 'claimed', 'expired'))");
        DB::statement('CREATE INDEX compensation_grants_expiry_index ON compensation_grants (recipient_user_id, expires_at)');
        Schema::table('worlds', function (Blueprint $table): void {
            // Do not infer a schedule origin from delayed completion times.
            $table->timestampTz('turn_schedule_origin_at')->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX audit_events_admin_request_unique ON audit_events ((metadata->>'request_id')) WHERE event_type = 'admin.operation_completed'");
    }

    private function extendUndergroundEquipment(): void
    {
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->integer('polish_level')->default(0);
        });
        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  DROP CONSTRAINT underground_owned_equipment_slot_check,
  ADD CONSTRAINT underground_owned_equipment_slot_check CHECK (
    equipped_slot IS NULL OR equipped_slot IN (
      'weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3', 'resonance'
    )
  )
SQL);
        DB::statement("ALTER TABLE underground_owned_equipment ADD CONSTRAINT underground_equipment_polish_check
            CHECK (polish_level >= 0 AND (polish_level = 0 OR (instance_kind = 'generated' AND generated_payload->>'category' = 'resonance')))");
        DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_equipment_source_battle_unique');
        DB::statement('CREATE UNIQUE INDEX underground_equipment_battle_reward_unique ON underground_owned_equipment (source_battle_id, COALESCE(source_reward_index, 1)) WHERE source_battle_id IS NOT NULL');
        DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment ADD CONSTRAINT underground_owned_equipment_instance_check CHECK (
    (instance_kind = 'fixed'
     AND instance_identity IS NULL AND generator_identity IS NULL AND generated_payload IS NULL
     AND source_battle_id IS NULL AND source_skip_settlement_id IS NULL
     AND source_skip_batch_id IS NULL AND source_reward_index IS NULL)
    OR
    (instance_kind = 'generated'
     AND instance_identity IS NOT NULL AND generator_identity IS NOT NULL
     AND generated_payload IS NOT NULL AND grant_key IS NOT NULL
     AND (
        (source_battle_id IS NOT NULL AND source_skip_settlement_id IS NULL AND source_skip_batch_id IS NULL
         AND (source_reward_index IS NULL OR source_reward_index >= 1))
        OR
        (source_battle_id IS NULL AND source_skip_settlement_id IS NOT NULL AND source_skip_batch_id IS NULL AND source_reward_index >= 1)
        OR
        (source_battle_id IS NULL AND source_skip_settlement_id IS NULL AND source_skip_batch_id IS NOT NULL AND source_reward_index >= 1)
     ))
)
SQL);
    }

    private function extendUndergroundProfiles(): void
    {
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->timestampTz('vault_expansion_purchased_at')->nullable();
            $table->timestampTz('resonance_expansion_purchased_at')->nullable();
            $table->bigInteger('distorted_stone_balance')->default(0);
            $table->date('distorted_stone_purchase_day')->nullable();
            $table->integer('distorted_stone_purchase_count')->default(0);
            $table->timestampTz('polishing_tutorial_completed_at')->nullable();
            $table->timestampTz('otherworld_discovered_at')->nullable();
        });
        DB::statement('ALTER TABLE underground_profiles ADD CONSTRAINT underground_otherworld_balances_check
            CHECK (distorted_stone_balance >= 0 AND distorted_stone_purchase_count >= 0)');
    }

    private function createMonumentDesigns(): void
    {
        Schema::create('user_monument_designs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name', 40)->default('オリジナル記念碑');
            $table->string('image_path', 96)->nullable();
            $table->timestamps();
        });
        Schema::table('map_cells', function (Blueprint $table): void {
            $table->foreignId('monument_design_id')->nullable()
                ->constrained('user_monument_designs')->nullOnDelete();
        });
    }

    private function createGachaDraws(): void
    {
        Schema::create('secretary_gacha_draws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained('secretaries')->cascadeOnDelete();
            $table->uuid('request_key');
            $table->unsignedBigInteger('ticket_item_instance_id');
            $table->string('ticket_key', 64);
            $table->unsignedInteger('ticket_level');
            $table->jsonb('result_snapshot');
            $table->timestamps();
            $table->unique(['secretary_id', 'request_key']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The complete 4.4.0 release migration is forward-only; restore the verified pre-migration backup.');
    }
};
