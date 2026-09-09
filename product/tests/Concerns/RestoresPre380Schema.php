<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared reversal of post-source additions for supported upgrade fixtures only. */
trait RestoresPre380Schema
{
    private function return390PersistenceToPre390Source(): void
    {
        Schema::dropIfExists('secretary_guide_conversation_totals');
        Schema::dropIfExists('guide_conversation_topics');
        Schema::dropIfExists('compensation_grant_claims');
        Schema::dropIfExists('compensation_grant_items');
        Schema::dropIfExists('compensation_grants');
        Schema::dropIfExists('user_daily_quest_activities');
        Schema::dropIfExists('user_daily_quest_progress');
        Schema::dropIfExists('user_daily_login_claims');
        Schema::dropIfExists('user_paradox_ledger');
        Schema::dropIfExists('user_paradox_balances');
        if (Schema::hasColumn('nation_command_queue_items', 'paradox_execution_count')) {
            Schema::table('nation_command_queue_items', function (Blueprint $table): void {
                $table->dropColumn('paradox_execution_count');
            });
        }

        $v22 = DB::table('ruleset_versions')->where('key', 'hakoniwa-2s-plus-v22')->first(['id']);
        $v23 = DB::table('ruleset_versions')->where('key', 'hakoniwa-2s-plus-v23')->first(['id']);
        if ($v22 !== null && $v23 !== null) {
            DB::table('worlds')->where('ruleset_version_id', $v23->id)->update([
                'ruleset_version_id' => $v22->id,
                'updated_at' => now(),
            ]);
            DB::table('ruleset_versions')->where('id', $v23->id)->delete();
        }
        DB::table('facility_definitions')
            ->whereIn('key', ['central_bank', 'central_granary'])
            ->delete();
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v22.php');
        config([
            'hakoniwa.ruleset' => $settings,
            'hakoniwa.published_rulesets' => [$settings['key'] => $settings],
        ]);
        DB::table('migrations')->whereIn('migration', [
            '2026_09_09_030000_add_surface_paradox_and_daily_rewards',
            '2026_09_09_040000_add_compensation_warehouse',
            '2026_09_09_050000_add_guide_conversation_topics',
        ])->delete();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_monster_instance_world_ruleset()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    world_ruleset bigint;
    definition_ruleset bigint;
    definition_min_hp integer;
    definition_max_hp integer;
BEGIN
    SELECT ruleset_version_id INTO world_ruleset FROM worlds WHERE id = NEW.world_id;
    SELECT ruleset_version_id, base_hp, base_hp + hp_variation
      INTO definition_ruleset, definition_min_hp, definition_max_hp
      FROM monster_definitions WHERE id = NEW.monster_definition_id;
    IF world_ruleset IS NULL OR definition_ruleset IS NULL OR world_ruleset <> definition_ruleset THEN
        RAISE EXCEPTION 'monster definition must belong to the World current ruleset';
    END IF;
    IF NEW.spawned_max_hp < definition_min_hp OR NEW.spawned_max_hp > definition_max_hp THEN
        RAISE EXCEPTION 'spawned monster HP is outside its definition range';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    private function returnPartyPersistenceToPre380Source(): void
    {
        Schema::dropIfExists('underground_battle_image_references');
        if (Schema::hasColumn('underground_owned_equipment', 'source_skip_settlement_id')) {
            DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
            if (Schema::hasColumn('underground_owned_equipment', 'source_skip_batch_id')) {
                Schema::table('underground_owned_equipment', function (Blueprint $table): void {
                    $table->dropForeign(['source_skip_batch_id']);
                    $table->dropUnique('underground_equipment_source_skip_batch_reward_unique');
                    $table->dropColumn('source_skip_batch_id');
                });
            }
            Schema::table('underground_owned_equipment', function (Blueprint $table): void {
                $table->dropForeign(['source_skip_settlement_id']);
                $table->dropUnique('underground_equipment_source_skip_reward_unique');
                $table->dropColumn(['source_skip_settlement_id', 'source_reward_index']);
            });
            DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  ADD CONSTRAINT underground_owned_equipment_instance_check
  CHECK (
    (
      instance_kind = 'fixed'
      AND instance_identity IS NULL
      AND generator_identity IS NULL
      AND generated_payload IS NULL
      AND source_battle_id IS NULL
    )
    OR
    (
      instance_kind = 'generated'
      AND instance_identity IS NOT NULL
      AND generator_identity IS NOT NULL
      AND generated_payload IS NOT NULL
      AND source_battle_id IS NOT NULL
      AND grant_key IS NOT NULL
    )
  )
SQL);
        }
        if (Schema::hasColumn('user_skip_ticket_ledger', 'underground_skip_batch_id')) {
            DB::statement('ALTER TABLE user_skip_ticket_ledger DROP CONSTRAINT IF EXISTS user_skip_ticket_ledger_skip_source_check');
            Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
                $table->dropForeign(['underground_skip_batch_id']);
                $table->dropUnique('user_skip_ticket_ledger_skip_batch_unique');
                $table->dropColumn('underground_skip_batch_id');
            });
        }
        if (Schema::hasColumn('user_skip_ticket_ledger', 'underground_skip_settlement_id')) {
            Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
                $table->dropForeign(['underground_skip_settlement_id']);
                $table->dropUnique('user_skip_ticket_ledger_skip_unique');
                $table->dropColumn('underground_skip_settlement_id');
            });
        }
        foreach ([
            'underground_skip_batches',
            'secretary_lending_build_snapshots',
            'underground_content_clear_progress',
            'underground_skip_settlements',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasColumn('underground_battles', 'underground_party_id')) {
            Schema::table('underground_battles', function (Blueprint $table): void {
                $table->dropForeign(['underground_party_id']);
                $table->dropUnique(['underground_party_id']);
                $table->dropColumn('underground_party_id');
            });
        }
        foreach ([
            'user_skip_ticket_ledger',
            'secretary_lending_participations',
            'secretary_lending_daily_rewards',
            'user_skip_ticket_balances',
            'underground_party_members',
            'secretary_lending_settings',
            'underground_parties',
            'secretary_images',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasColumn('secretaries', 'nickname')) {
            DB::statement('ALTER TABLE secretaries DROP CONSTRAINT secretaries_nickname_check');
            DB::statement('ALTER TABLE secretaries DROP CONSTRAINT secretaries_portrait_preference_check');
            Schema::table('secretaries', function (Blueprint $table): void {
                $table->dropColumn(['nickname', 'portrait_preference']);
            });
        }
        $v21 = DB::table('ruleset_versions')->where('key', 'hakoniwa-2s-plus-v21')->first(['id']);
        $v22 = DB::table('ruleset_versions')->where('key', 'hakoniwa-2s-plus-v22')->first(['id']);
        if ($v21 !== null && $v22 !== null) {
            DB::table('worlds')->where('ruleset_version_id', $v22->id)->update([
                'ruleset_version_id' => $v21->id,
                'updated_at' => now(),
            ]);
            DB::table('ruleset_versions')->where('id', $v22->id)->delete();
            $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v21.php');
            config([
                'hakoniwa.ruleset' => $settings,
                'hakoniwa.published_rulesets' => [$settings['key'] => $settings],
            ]);
        }
        DB::table('migrations')->whereIn('migration', [
            '2026_09_09_000000_extend_secretary_lending_build_cache',
            '2026_09_09_010000_add_underground_battle_image_references',
            '2026_09_09_020000_prepare_3_8_1_ui_and_bulk_skip',
            '2026_09_08_010000_add_secretary_nickname_and_image_slots',
            '2026_09_08_100000_add_underground_party_lending_persistence',
            '2026_09_08_110000_add_underground_skip_consumption',
            '2026_09_08_120000_add_secretary_lending_build_cache',
        ])->delete();
    }

    private function returnAuctionItemHistoryToPre380Source(): void
    {
        if (! Schema::hasColumn('auction_listings', 'original_secretary_item_instance_id')) {
            return;
        }
        DB::statement(<<<'SQL'
ALTER TABLE auction_listings
  DROP CONSTRAINT auction_listings_secretary_item_instance_id_foreign,
  DROP CONSTRAINT auction_listings_product_check,
  ADD CONSTRAINT auction_listings_product_check CHECK (
    (
      product_type = 'resource'
      AND resource_definition_id IS NOT NULL
      AND secretary_item_instance_id IS NULL
      AND item_key IS NULL
      AND item_level IS NULL
      AND quantity IS NOT NULL
      AND quantity > 0
    )
    OR
    (
      product_type = 'item'
      AND resource_definition_id IS NULL
      AND quantity IS NULL
      AND item_key IS NOT NULL
      AND item_level IS NOT NULL
      AND item_level > 0
      AND (
        (seller_type = 'nation' AND secretary_item_instance_id IS NOT NULL)
        OR (seller_type = 'hakoniwa_federation' AND secretary_item_instance_id IS NULL)
      )
    )
  ),
  ADD CONSTRAINT auction_listings_secretary_item_instance_id_foreign
    FOREIGN KEY (secretary_item_instance_id)
    REFERENCES secretary_item_instances(id)
    ON DELETE RESTRICT
SQL);
        Schema::table('auction_listings', function (Blueprint $table): void {
            $table->dropColumn('original_secretary_item_instance_id');
        });
        DB::table('migrations')->where('migration', '2026_09_08_000000_preserve_completed_auction_item_history')->delete();
    }
}
