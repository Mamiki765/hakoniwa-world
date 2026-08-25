<?php

use App\Application\Ver260OilResourceRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (! Schema::hasColumn('secretary_item_instances', 'is_escrowed')) {
            Schema::table('secretary_item_instances', function (Blueprint $table): void {
                $table->boolean('is_escrowed')->default(false);
            });
            DB::statement(<<<'SQL'
ALTER TABLE secretary_item_instances
  ADD CONSTRAINT secretary_item_instances_escrow_equipment_check
    CHECK (NOT is_escrowed OR equipped_slot IS NULL)
SQL);
        }

        if (! Schema::hasTable('auction_listings')) {
            Schema::create('auction_listings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('world_id')->constrained('worlds')->restrictOnDelete();
                $table->string('seller_type', 32);
                $table->foreignId('seller_nation_id')->nullable()->constrained('nations')->restrictOnDelete();
                $table->string('product_type', 16);
                $table->foreignId('resource_definition_id')->nullable()->constrained('resource_definitions')->restrictOnDelete();
                $table->foreignId('secretary_item_instance_id')->nullable()->constrained('secretary_item_instances')->restrictOnDelete();
                $table->string('item_key', 64)->nullable();
                $table->integer('item_level')->nullable();
                $table->unsignedBigInteger('quantity')->nullable();
                $table->unsignedBigInteger('start_price');
                $table->unsignedBigInteger('current_price')->nullable();
                $table->foreignId('highest_bidder_nation_id')->nullable()->constrained('nations')->restrictOnDelete();
                $table->unsignedInteger('bid_count')->default(0);
                $table->unsignedSmallInteger('duration_turns');
                $table->unsignedBigInteger('started_turn');
                $table->unsignedBigInteger('ends_turn');
                $table->boolean('auto_relist')->default(false);
                $table->unsignedInteger('relist_count')->default(0);
                $table->string('status', 16)->default('active');
                $table->unsignedBigInteger('completed_turn')->nullable();
                $table->timestampsTz();
            });
            DB::statement(<<<'SQL'
ALTER TABLE auction_listings
  ADD CONSTRAINT auction_listings_seller_check CHECK (
    (seller_type = 'nation' AND seller_nation_id IS NOT NULL)
    OR (seller_type = 'hakoniwa_federation' AND seller_nation_id IS NULL)
  ),
  ADD CONSTRAINT auction_listings_product_check CHECK (
    (product_type = 'resource' AND resource_definition_id IS NOT NULL
      AND secretary_item_instance_id IS NULL AND item_key IS NULL AND item_level IS NULL
      AND quantity IS NOT NULL AND quantity > 0)
    OR
    (product_type = 'item' AND resource_definition_id IS NULL AND quantity IS NULL
      AND item_key IS NOT NULL AND item_level IS NOT NULL AND item_level > 0
      AND ((seller_type = 'nation' AND secretary_item_instance_id IS NOT NULL)
        OR (seller_type = 'hakoniwa_federation' AND secretary_item_instance_id IS NULL)))
  ),
  ADD CONSTRAINT auction_listings_price_check CHECK (
    start_price > 0 AND (current_price IS NULL OR current_price >= start_price)
  ),
  ADD CONSTRAINT auction_listings_bid_state_check CHECK (
    (bid_count = 0 AND current_price IS NULL AND highest_bidder_nation_id IS NULL)
    OR (bid_count > 0 AND current_price IS NOT NULL AND highest_bidder_nation_id IS NOT NULL)
  ),
  ADD CONSTRAINT auction_listings_turn_check CHECK (
    duration_turns BETWEEN 3 AND 84 AND ends_turn = started_turn + duration_turns
  ),
  ADD CONSTRAINT auction_listings_status_check CHECK (
    status IN ('active', 'cancelled', 'sold', 'expired')
      AND ((status = 'active' AND completed_turn IS NULL)
        OR (status <> 'active' AND completed_turn IS NOT NULL))
  ),
  ADD CONSTRAINT auction_listings_npc_relist_check CHECK (
    seller_type = 'nation' OR auto_relist = false
  )
SQL);
            DB::statement(
                'CREATE UNIQUE INDEX auction_listings_active_item_unique '
                .'ON auction_listings (secretary_item_instance_id) '
                ."WHERE status = 'active' AND secretary_item_instance_id IS NOT NULL",
            );
            DB::statement(
                'CREATE INDEX auction_listings_active_world_end_index '
                ."ON auction_listings (world_id, ends_turn, id) WHERE status = 'active'",
            );
            DB::statement(
                'CREATE INDEX auction_listings_active_seller_index '
                ."ON auction_listings (seller_nation_id, id) WHERE status = 'active'",
            );
        }

        if (! Schema::hasTable('auction_bids')) {
            Schema::create('auction_bids', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('auction_listing_id')->constrained('auction_listings')->restrictOnDelete();
                $table->foreignId('bidder_nation_id')->constrained('nations')->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->string('status', 16)->default('highest');
                $table->unsignedBigInteger('placed_turn');
                $table->timestampTz('refunded_at')->nullable();
                $table->timestampsTz();
            });
            DB::statement(<<<'SQL'
ALTER TABLE auction_bids
  ADD CONSTRAINT auction_bids_amount_check CHECK (amount > 0),
  ADD CONSTRAINT auction_bids_status_check CHECK (
    status IN ('highest', 'refunded', 'won')
      AND ((status = 'refunded' AND refunded_at IS NOT NULL)
        OR (status <> 'refunded' AND refunded_at IS NULL))
  )
SQL);
            DB::statement(
                'CREATE UNIQUE INDEX auction_bids_one_highest_per_listing '
                ."ON auction_bids (auction_listing_id) WHERE status = 'highest'",
            );
            DB::statement('CREATE INDEX auction_bids_bidder_index ON auction_bids (bidder_nation_id, id)');
        }

        DB::statement('ALTER TABLE nations DROP CONSTRAINT IF EXISTS nations_karma_range_check');
        DB::statement(<<<'SQL'
ALTER TABLE nations
  ADD CONSTRAINT nations_karma_range_check CHECK (karma BETWEEN -30 AND 100)
SQL);

        app(Ver260OilResourceRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The v15 to final v16 migration is forward-only; restore the exact supported v15 backup and re-upgrade.',
        );
    }
};
