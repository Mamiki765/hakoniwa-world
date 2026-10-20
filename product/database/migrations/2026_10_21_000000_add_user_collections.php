<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user's collection survives island rebuilds; one row per discovered entry.
        Schema::create('user_collection_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('entry_key', 64);
            $table->string('source', 16);
            $table->bigInteger('first_turn')->nullable();
            $table->timestampTz('registered_at');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'kind', 'entry_key']);
        });
        DB::statement("ALTER TABLE user_collection_entries ADD CONSTRAINT user_collection_entries_kind_check CHECK (kind IN ('monster','item'))");
        DB::statement("ALTER TABLE user_collection_entries ADD CONSTRAINT user_collection_entries_source_check CHECK (source IN ('live','backfill'))");
        Schema::create('user_collection_bonus_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('milestone');
            $table->timestampTz('claimed_at');
            $table->timestampsTz();
            $table->unique(['user_id', 'milestone']);
        });
        DB::statement('ALTER TABLE user_collection_bonus_claims ADD CONSTRAINT user_collection_bonus_claims_milestone_check CHECK (milestone > 0)');
        DB::statement('ALTER TABLE user_paradox_ledger DROP CONSTRAINT user_paradox_ledger_source_check');
        DB::statement("ALTER TABLE user_paradox_ledger ADD CONSTRAINT user_paradox_ledger_source_check CHECK (source_kind IN ('daily_login','daily_quest','command','compensation','underground_respec','secretary_warehouse_expansion','collection'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Collection records are forward-only. Restore a reviewed backup instead.');
    }
};
