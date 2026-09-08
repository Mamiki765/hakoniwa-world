<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared reversal of unreleased 3.8 additions for supported-source fixtures only. */
trait RestoresPre380Schema
{
    private function returnPartyPersistenceToPre380Source(): void
    {
        Schema::dropIfExists('underground_battle_image_references');
        if (Schema::hasColumn('underground_owned_equipment', 'source_skip_settlement_id')) {
            DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
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
        if (Schema::hasColumn('user_skip_ticket_ledger', 'underground_skip_settlement_id')) {
            Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
                $table->dropForeign(['underground_skip_settlement_id']);
                $table->dropUnique('user_skip_ticket_ledger_skip_unique');
                $table->dropColumn('underground_skip_settlement_id');
            });
        }
        foreach ([
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
        DB::table('migrations')->whereIn('migration', [
            '2026_09_09_000000_extend_secretary_lending_build_cache',
            '2026_09_09_010000_add_underground_battle_image_references',
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
