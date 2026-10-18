<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secretary_surface_states', function (Blueprint $table): void {
            $table->unsignedSmallInteger('item_capacity_expansions')->default(0);
        });
        DB::statement('ALTER TABLE secretary_surface_states ADD CONSTRAINT secretary_warehouse_expansions_check CHECK (item_capacity_expansions BETWEEN 0 AND 4)');
        Schema::create('secretary_synthesis_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained()->cascadeOnDelete();
            $table->string('request_id', 64);
            $table->string('recipe_key', 128);
            $table->char('request_fingerprint', 64);
            $table->jsonb('material_ids');
            $table->unsignedBigInteger('result_item_instance_id')->nullable();
            $table->jsonb('result_snapshot');
            $table->timestampsTz();
            $table->unique(['secretary_id', 'request_id']);
        });
        Schema::create('secretary_warehouse_expansion_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained()->cascadeOnDelete();
            $table->string('request_id', 64);
            $table->unsignedSmallInteger('expected_expansions');
            $table->jsonb('result_snapshot');
            $table->timestampsTz();
            $table->unique(['secretary_id', 'request_id'], 'secretary_warehouse_request_unique');
        });
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->boolean('is_listed')->default(false);
            // Durable provenance, independent from the lifetime of market history.
            $table->unsignedBigInteger('source_market_listing_id')->nullable();
        });
        Schema::create('underground_market_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained('underground_profiles')->restrictOnDelete();
            $table->unsignedBigInteger('equipment_id');
            $table->foreignId('buyer_profile_id')->nullable()->constrained('underground_profiles')->restrictOnDelete();
            $table->bigInteger('price');
            $table->bigInteger('fee');
            $table->bigInteger('proceeds');
            $table->string('status', 16);
            $table->jsonb('item_snapshot');
            $table->jsonb('seller_snapshot');
            $table->timestampTz('listed_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('sold_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('proceeds_claimed_at')->nullable();
            $table->timestampTz('seen_by_seller_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'expires_at']);
            $table->index(['seller_profile_id', 'status']);
        });
        DB::statement("ALTER TABLE underground_market_listings ADD CONSTRAINT underground_market_listing_status_check CHECK (status IN ('active','sold','cancelled','expired'))");
        DB::statement('ALTER TABLE underground_market_listings ADD CONSTRAINT underground_market_money_check CHECK (price > 0 AND proceeds >= 0 AND fee >= 0 AND proceeds + fee = price)');
        DB::statement("CREATE UNIQUE INDEX underground_market_active_equipment_unique ON underground_market_listings (equipment_id) WHERE status = 'active'");
        Schema::create('underground_market_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->jsonb('result_snapshot');
            $table->timestampsTz();
            $table->unique(['underground_profile_id', 'request_id']);
        });
        DB::statement('ALTER TABLE user_paradox_ledger DROP CONSTRAINT user_paradox_ledger_source_check');
        DB::statement("ALTER TABLE user_paradox_ledger ADD CONSTRAINT user_paradox_ledger_source_check CHECK (source_kind IN ('daily_login','daily_quest','command','compensation','underground_respec','secretary_warehouse_expansion'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Asset and receipt migrations are forward-only. Restore a reviewed backup instead.');
    }
};
