<?php

use App\Application\Ver280UnderseaCityRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    /** @var list<string> */
    private const UNDERGROUND_TABLES = [
        'underground_profiles',
        'underground_trial_progress',
        'underground_trial_runs',
        'underground_battles',
        'underground_battle_logs',
        'underground_intro_progress',
        'underground_intro_requests',
        'underground_skill_allocations',
        'underground_owned_equipment',
    ];

    /** @var list<string> */
    private const EXACT_280_MIGRATION_LEDGER = [
        '0001_01_01_000000_create_users_table',
        '0001_01_01_000001_create_cache_table',
        '2026_07_26_000000_create_hakoniwa_schema',
        '2026_07_26_010000_add_roadmap_pr2_systems',
        '2026_07_26_020000_replace_axial_coordinates_with_staggered_xy',
        '2026_07_27_000000_add_command_parameter_metadata',
        '2026_07_27_010000_publish_roadmap_pr6_ruleset',
        '2026_07_28_000000_add_universal_quantity_to_command_queue_items',
        '2026_07_28_010000_normalize_food_resources_to_tons',
        '2026_07_28_999999_enforce_queue_item_ruleset_consistency',
        '2026_07_29_000000_publish_roadmap_pr7_ruleset',
        '2026_07_29_010000_create_turn_runs',
        '2026_07_30_000000_publish_roadmap_pr11_ruleset',
        '2026_08_01_000000_start_world_turns_at_one',
        '2026_08_02_000000_publish_roadmap_pr14_ruleset',
        '2026_08_02_010000_publish_roadmap_pr15_ruleset',
        '2026_08_02_020000_add_per_world_nation_numbers',
        '2026_08_04_000000_publish_roadmap_pr18_ruleset',
        '2026_08_04_010000_add_nation_profiles',
        '2026_08_04_020000_publish_roadmap_pr19_ruleset',
        '2026_08_05_000000_create_monster_system_and_publish_roadmap_pr21_ruleset',
        '2026_08_05_010000_add_pr22_command_event_state_and_publish_ruleset',
        '2026_08_05_020000_prepare_first_production_release',
        '2026_08_09_000000_publish_hakoniwa_2s_plus_v2',
        '2026_08_09_010000_create_announcements',
        '2026_08_09_020000_repair_hakoniwa_2s_plus_v2_live_monster_references',
        '2026_08_09_030000_repair_deterministic_application_timestamps',
        '2026_08_09_040000_create_nation_awards_and_monster_cycles',
        '2026_08_10_000000_publish_hakoniwa_2s_plus_v3',
        '2026_08_11_000000_create_island_messages',
        '2026_08_13_000000_publish_hakoniwa_2s_plus_v4',
        '2026_08_14_000000_publish_hakoniwa_2s_plus_v5',
        '2026_08_15_000000_enable_nation_reregistration',
        '2026_08_16_000000_publish_hakoniwa_2s_plus_v6',
        '2026_08_16_010000_create_nation_command_queue_bulk_requests',
        '2026_08_16_020000_create_secretary_system',
        '2026_08_16_030000_publish_hakoniwa_2s_plus_v7',
        '2026_08_16_040000_publish_hakoniwa_2s_plus_v8',
        '2026_08_17_000000_publish_hakoniwa_2s_plus_v9',
        '2026_08_17_010000_create_secretary_items_and_inquiries',
        '2026_08_19_000000_add_command_request_fingerprint',
        '2026_08_19_010000_publish_hakoniwa_2s_plus_v10',
        '2026_08_20_000000_add_secretary_equipment_version',
        '2026_08_20_010000_add_monster_definition_display_order',
        '2026_08_21_000000_add_command_request_ruleset_provenance',
        '2026_08_21_010000_publish_hakoniwa_2s_plus_v11',
        '2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade',
        '2026_08_23_000000_add_nation_dormancy_and_publish_v12',
        '2026_08_23_010000_add_nation_karma_and_publish_v13',
        '2026_08_24_000000_add_secretary_profiles_and_publish_v14',
        '2026_08_24_010000_add_monster_experience_and_publish_v15',
        '2026_08_25_000000_add_oil_resource_and_publish_v16',
        '2026_08_26_000000_publish_v17_secretary_item_foundation',
        '2026_08_27_000000_publish_v18_undersea_city',
    ];

    public function up(): void
    {
        $sourceLedger = DB::table('migrations')
            ->orderBy('migration')
            ->pluck('migration')
            ->map(static fn (mixed $migration): string => (string) $migration)
            ->all();
        if ($sourceLedger !== self::EXACT_280_MIGRATION_LEDGER) {
            throw new RuntimeException(
                'The 3.0.0 migration requires the exact 2.8.0 migration ledger.',
            );
        }

        foreach (self::UNDERGROUND_TABLES as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    'The 3.0.0 migration requires an exact 2.8.0 source without Underground alpha tables.',
                );
            }
        }

        $surfaceState = app(Ver280UnderseaCityRulesetUpgrade::class)->run();
        if (! in_array($surfaceState, ['already_current_v18', 'fresh_install_current_v18'], true)) {
            throw new RuntimeException(
                'The 3.0.0 migration requires the exact immutable 2.8.0 / v18 source state.',
            );
        }

        $this->createProfiles();
        $this->createRuntime();
        $this->createIntro();
        $this->createSkillsAndEquipment();
    }

    private function createProfiles(): void
    {
        Schema::create('underground_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->unique()->constrained('secretaries')->cascadeOnDelete();
            $table->unsignedInteger('unlocked_area_layers')->default(0);
            $table->integer('combat_level')->default(1);
            $table->bigInteger('combat_xp')->default(0);
            $table->bigInteger('shard_balance')->default(0);
            $table->bigInteger('banked_shard_balance')->default(0);
            $table->unsignedInteger('current_hp')->nullable();
            $table->timestampTz('next_battle_at')->nullable();
            $table->timestampTz('underground_contract_completed_at')->nullable();
            $table->string('growth_path_key', 32)->nullable();
            $table->string('growth_path_identity', 64)->nullable();
            $table->timestampTz('growth_path_selected_at')->nullable();
            $table->unsignedInteger('unspent_stp')->default(0);
            $table->unsignedInteger('allocated_vitality_stp')->default(0);
            $table->unsignedInteger('allocated_might_stp')->default(0);
            $table->unsignedInteger('allocated_finesse_stp')->default(0);
            $table->unsignedInteger('allocated_spirit_stp')->default(0);
            $table->unsignedInteger('allocated_agility_stp')->default(0);
            $table->unsignedInteger('skill_points_total')->default(0);
            $table->unsignedInteger('skill_points_unspent')->default(0);
            $table->string('skill_tree_identity', 100)->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_unlocked_area_layers_non_negative
  CHECK (unlocked_area_layers >= 0),
  ADD CONSTRAINT underground_profiles_combat_level_positive
  CHECK (combat_level >= 1),
  ADD CONSTRAINT underground_profiles_combat_xp_non_negative
  CHECK (combat_xp >= 0),
  ADD CONSTRAINT underground_profiles_shard_balance_non_negative
  CHECK (shard_balance >= 0),
  ADD CONSTRAINT underground_profiles_banked_shard_balance_non_negative
  CHECK (banked_shard_balance >= 0),
  ADD CONSTRAINT underground_profiles_current_hp_positive
  CHECK (current_hp IS NULL OR current_hp >= 1),
  ADD CONSTRAINT underground_profiles_growth_path_check
  CHECK (
    (growth_path_key IS NULL AND growth_path_identity IS NULL AND growth_path_selected_at IS NULL)
    OR
    (
      underground_contract_completed_at IS NOT NULL
      AND growth_path_key IN ('martial_red', 'guardianship_blue', 'blessing_green', 'free_black')
      AND growth_path_identity = 'secretary-underground-growth-alpha-v1'
      AND growth_path_selected_at IS NOT NULL
      AND growth_path_selected_at >= underground_contract_completed_at
    )
  ),
  ADD CONSTRAINT underground_profiles_stp_non_negative
  CHECK (
    unspent_stp >= 0
    AND allocated_vitality_stp >= 0
    AND allocated_might_stp >= 0
    AND allocated_finesse_stp >= 0
    AND allocated_spirit_stp >= 0
    AND allocated_agility_stp >= 0
  ),
  ADD CONSTRAINT underground_profiles_stp_entitlement_check
  CHECK (
    (
      growth_path_key IS NULL
      AND unspent_stp + allocated_vitality_stp + allocated_might_stp
        + allocated_finesse_stp + allocated_spirit_stp + allocated_agility_stp = 0
    )
    OR
    (
      growth_path_key IS NOT NULL
      AND unspent_stp + allocated_vitality_stp + allocated_might_stp
        + allocated_finesse_stp + allocated_spirit_stp + allocated_agility_stp
        = (combat_level - 1) * CASE growth_path_key
          WHEN 'free_black' THEN 6
          WHEN 'martial_red' THEN 5
          WHEN 'guardianship_blue' THEN 5
          WHEN 'blessing_green' THEN 5
          ELSE 0
        END
    )
  ),
  ADD CONSTRAINT underground_profiles_skill_points_check
  CHECK (
    skill_points_total >= 0
    AND skill_points_unspent >= 0
    AND skill_points_unspent <= skill_points_total
    AND (
      (
        growth_path_key IS NULL
        AND skill_points_total = 0
        AND skill_points_unspent = 0
        AND skill_tree_identity IS NULL
      )
      OR
      (
        growth_path_key IS NOT NULL
        AND skill_points_total >= 20
        AND skill_tree_identity IS NOT NULL
      )
    )
  )
SQL);
    }

    private function createRuntime(): void
    {
        Schema::create('underground_trial_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->string('trial_key', 64);
            $table->timestampTz('unlocked_at');
            $table->timestampTz('first_cleared_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'trial_key'],
                'underground_trial_progress_profile_trial_unique',
            );
        });

        Schema::create('underground_trial_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->unique()
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->uuid('run_key')->unique();
            $table->string('trial_key', 64);
            $table->string('trial_content_identity', 128);
            $table->unsignedSmallInteger('next_battle_index')->default(1);
            $table->string('status', 16);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();
            $table->index(
                ['underground_profile_id', 'status'],
                'underground_trial_runs_profile_status_index',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_trial_runs
  ADD CONSTRAINT underground_trial_runs_status_check
  CHECK (status IN ('active', 'withdrawn', 'defeated', 'cleared')),
  ADD CONSTRAINT underground_trial_runs_next_battle_index_positive
  CHECK (next_battle_index >= 1),
  ADD CONSTRAINT underground_trial_runs_content_identity_not_empty
  CHECK (char_length(trial_content_identity) > 0)
SQL);

        Schema::create('underground_battles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('runtime_identity', 64);
            $table->string('activity_type', 16);
            $table->string('activity_key', 64);
            $table->string('encounter_key', 64);
            $table->uuid('trial_run_key')->nullable();
            $table->unsignedSmallInteger('trial_battle_index')->nullable();
            $table->string('result', 16);
            $table->unsignedSmallInteger('rounds');
            $table->bigInteger('damage_dealt');
            $table->bigInteger('damage_received');
            $table->bigInteger('healing_done');
            $table->unsignedInteger('xp_awarded')->default(0);
            $table->bigInteger('shard_delta')->default(0);
            $table->integer('combat_level_before');
            $table->integer('combat_level_after');
            $table->bigInteger('combat_xp_before');
            $table->bigInteger('combat_xp_after');
            $table->bigInteger('shard_balance_before');
            $table->bigInteger('shard_balance_after');
            $table->unsignedInteger('private_seed');
            $table->jsonb('snapshot');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'request_id'],
                'underground_battles_profile_request_unique',
            );
            $table->index(
                ['underground_profile_id', 'finished_at'],
                'underground_battles_profile_finished_at_index',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_battles
  ADD CONSTRAINT underground_battles_request_fingerprint_check
  CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT underground_battles_activity_type_check
  CHECK (activity_type IN ('exploration', 'trial', 'tutorial', 'story', 'playtest')),
  ADD CONSTRAINT underground_battles_result_check
  CHECK (result IN ('victory', 'defeat', 'withdrawal')),
  ADD CONSTRAINT underground_battles_rounds_range
  CHECK (rounds >= 1 AND rounds <= 100),
  ADD CONSTRAINT underground_battles_damage_dealt_non_negative
  CHECK (damage_dealt >= 0),
  ADD CONSTRAINT underground_battles_damage_received_non_negative
  CHECK (damage_received >= 0),
  ADD CONSTRAINT underground_battles_healing_done_non_negative
  CHECK (healing_done >= 0),
  ADD CONSTRAINT underground_battles_xp_awarded_non_negative
  CHECK (xp_awarded >= 0),
  ADD CONSTRAINT underground_battles_private_seed_range
  CHECK (private_seed >= 0 AND private_seed <= 2147483647),
  ADD CONSTRAINT underground_battles_trial_battle_index_positive
  CHECK (trial_battle_index IS NULL OR trial_battle_index >= 1),
  ADD CONSTRAINT underground_battles_trial_context_check
  CHECK (
    (activity_type = 'trial' AND trial_run_key IS NOT NULL AND trial_battle_index IS NOT NULL)
    OR
    (activity_type <> 'trial' AND trial_run_key IS NULL AND trial_battle_index IS NULL)
  ),
  ADD CONSTRAINT underground_battles_combat_level_before_positive
  CHECK (combat_level_before >= 1),
  ADD CONSTRAINT underground_battles_combat_level_after_positive
  CHECK (combat_level_after >= 1),
  ADD CONSTRAINT underground_battles_combat_xp_before_non_negative
  CHECK (combat_xp_before >= 0),
  ADD CONSTRAINT underground_battles_combat_xp_after_non_negative
  CHECK (combat_xp_after >= 0),
  ADD CONSTRAINT underground_battles_shard_balance_before_non_negative
  CHECK (shard_balance_before >= 0),
  ADD CONSTRAINT underground_battles_shard_balance_after_non_negative
  CHECK (shard_balance_after >= 0)
SQL);

        Schema::create('underground_battle_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_battle_id')
                ->unique()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->jsonb('actions');
            $table->timestampTz('expires_at')->index();
            $table->timestamps();
        });
    }

    private function createIntro(): void
    {
        Schema::create('underground_intro_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->unique()
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->string('stage', 32)->default('not_started');
            $table->string('shopkeeper_name', 255)->nullable();
            $table->boolean('special_loss_required')->nullable();
            $table->string('branch_identity', 32)->nullable();
            $table->foreignId('tutorial_battle_id')
                ->nullable()
                ->unique()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->foreignId('scripted_loss_battle_id')
                ->nullable()
                ->unique()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_progress
  ADD CONSTRAINT underground_intro_progress_stage_check
  CHECK (stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation',
    'contract_ready', 'crystal_selection', 'growth_path_selected', 'underground_open'
  )),
  ADD CONSTRAINT underground_intro_progress_tutorial_check
  CHECK (
    (stage IN ('not_started', 'initial_descent', 'tutorial_ready') AND tutorial_battle_id IS NULL)
    OR
    (stage NOT IN ('not_started', 'initial_descent', 'tutorial_ready') AND tutorial_battle_id IS NOT NULL)
  ),
  ADD CONSTRAINT underground_intro_progress_branch_identity_check
  CHECK (branch_identity IS NULL OR branch_identity IN ('normal', 'legacy_temporary', 'true_name')),
  ADD CONSTRAINT underground_intro_progress_naming_check
  CHECK (
    (stage IN (
      'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
      'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming'
    ) AND shopkeeper_name IS NULL AND special_loss_required IS NULL AND branch_identity IS NULL)
    OR
    (stage NOT IN (
      'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
      'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming'
    ) AND shopkeeper_name IS NOT NULL AND special_loss_required IS NOT NULL AND branch_identity IS NOT NULL)
  ),
  ADD CONSTRAINT underground_intro_progress_special_loss_check
  CHECK (
    (branch_identity = 'normal' AND special_loss_required = FALSE AND scripted_loss_battle_id IS NULL)
    OR
    (
      branch_identity IN ('legacy_temporary', 'true_name')
      AND special_loss_required = TRUE
      AND (
        (stage = 'special_loss_pending' AND scripted_loss_battle_id IS NULL)
        OR
        (stage IN (
          'special_loss_complete', 'shop_explanation', 'contract_ready',
          'crystal_selection', 'growth_path_selected', 'underground_open'
        ) AND scripted_loss_battle_id IS NOT NULL)
      )
    )
    OR
    (branch_identity IS NULL AND special_loss_required IS NULL AND scripted_loss_battle_id IS NULL)
  )
SQL);

        Schema::create('underground_intro_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('operation', 32);
            $table->string('resulting_stage', 32);
            $table->foreignId('underground_battle_id')
                ->nullable()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'request_id'],
                'underground_intro_requests_profile_request_unique',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  ADD CONSTRAINT underground_intro_requests_fingerprint_check
  CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer', 'playtest',
    'stp_allocate', 'skill_acquire', 'active_loadout',
    'equipment_purchase', 'equipment_sell', 'equipment_equip', 'equipment_unequip'
  )),
  ADD CONSTRAINT underground_intro_requests_stage_check
  CHECK (resulting_stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation',
    'contract_ready', 'crystal_selection', 'growth_path_selected', 'underground_open'
  ))
SQL);
    }

    private function createSkillsAndEquipment(): void
    {
        Schema::create('underground_skill_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->string('node_key', 100);
            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('active_slot')->nullable();
            $table->timestamps();
            $table->unique(['underground_profile_id', 'node_key'], 'underground_skill_profile_node_unique');
            $table->unique(['underground_profile_id', 'active_slot'], 'underground_skill_profile_slot_unique');
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_skill_allocations
  ADD CONSTRAINT underground_skill_allocations_rank_positive
  CHECK (rank >= 1),
  ADD CONSTRAINT underground_skill_allocations_active_slot_range
  CHECK (active_slot IS NULL OR active_slot BETWEEN 1 AND 5)
SQL);

        Schema::create('underground_owned_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->string('definition_key', 100);
            $table->string('catalog_identity', 100);
            $table->string('equipped_slot', 16)->nullable();
            $table->string('grant_key', 100)->nullable();
            $table->timestampTz('acquired_at');
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'equipped_slot'],
                'underground_equipment_profile_slot_unique',
            );
            $table->unique(
                ['underground_profile_id', 'grant_key'],
                'underground_equipment_profile_grant_unique',
            );
            $table->index(
                ['underground_profile_id', 'acquired_at', 'id'],
                'underground_equipment_vault_page_index',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  ADD CONSTRAINT underground_owned_equipment_slot_check
  CHECK (equipped_slot IS NULL OR equipped_slot IN ('weapon', 'armor', 'accessory')),
  ADD CONSTRAINT underground_owned_equipment_identity_check
  CHECK (definition_key <> '' AND catalog_identity <> '')
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.0.0 Underground release migration is forward-only; restore the verified 2.8.0 backup.',
        );
    }
};
