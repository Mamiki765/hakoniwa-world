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
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->bigInteger('banked_shard_balance')->default(0);
            $table->unsignedInteger('current_hp')->nullable();
            $table->unsignedInteger('unspent_stp')->default(0);
            $table->unsignedInteger('allocated_vitality_stp')->default(0);
            $table->unsignedInteger('allocated_might_stp')->default(0);
            $table->unsignedInteger('allocated_finesse_stp')->default(0);
            $table->unsignedInteger('allocated_spirit_stp')->default(0);
            $table->unsignedInteger('allocated_agility_stp')->default(0);
        });

        DB::statement(<<<'SQL'
UPDATE underground_profiles
   SET unspent_stp = (combat_level - 1) * CASE growth_path_key
       WHEN 'free_black' THEN 6
       WHEN 'martial_red' THEN 5
       WHEN 'guardianship_blue' THEN 5
       WHEN 'blessing_green' THEN 5
       ELSE 0
   END
 WHERE growth_path_key IS NOT NULL
SQL);

        DB::statement(<<<'SQL'
UPDATE underground_profiles
   SET current_hp = 500 + 8 * (
       CASE growth_path_key
         WHEN 'martial_red' THEN 18 + (combat_level - 1)
         WHEN 'guardianship_blue' THEN 40 + 2 * (combat_level - 1)
         WHEN 'blessing_green' THEN 22 + (combat_level - 1)
         WHEN 'free_black' THEN 26 + (combat_level - 1)
       END
       + 1 - 20
   )
 WHERE growth_path_key IS NOT NULL
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_combat_level_max_check
  CHECK (combat_level <= 100),
  ADD CONSTRAINT underground_profiles_banked_shard_balance_non_negative
  CHECK (banked_shard_balance >= 0),
  ADD CONSTRAINT underground_profiles_current_hp_positive
  CHECK (current_hp IS NULL OR current_hp >= 1),
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
  )
SQL);

        DB::statement('ALTER TABLE underground_intro_requests DROP CONSTRAINT underground_intro_requests_operation_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground growth STP persistence is forward-only.');
    }
};
