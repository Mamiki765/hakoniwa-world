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
        Schema::create('compensation_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained('worlds')->restrictOnDelete();
            $table->foreignId('nation_id')->constrained('nations')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('grant_key', 180)->unique();
            $table->string('operator_identifier', 120);
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestamps();
            $table->index(['nation_id', 'status']);
            $table->index(['recipient_user_id', 'status']);
        });

        Schema::create('compensation_grant_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('compensation_grant_id')->constrained('compensation_grants')->cascadeOnDelete();
            $table->string('asset_key', 32);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('claimed_amount')->default(0);
            $table->timestamps();
            $table->unique(['compensation_grant_id', 'asset_key'], 'compensation_grant_item_asset_unique');
        });

        Schema::create('compensation_grant_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('compensation_grant_id')->constrained('compensation_grants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->jsonb('result');
            $table->timestampTz('created_at');
            $table->index(['compensation_grant_id', 'created_at']);
        });

        DB::statement("ALTER TABLE compensation_grants ADD CONSTRAINT compensation_grants_status_check CHECK (status IN ('pending', 'partial', 'claimed'))");
        DB::statement("ALTER TABLE compensation_grant_items ADD CONSTRAINT compensation_grant_items_asset_check CHECK (asset_key IN ('money', 'wheat', 'fish', 'meat', 'oil', 'paradox', 'skip_ticket', 'underground_g')), ADD CONSTRAINT compensation_grant_items_amount_check CHECK (amount > 0 AND claimed_amount >= 0 AND claimed_amount <= amount)");
        DB::statement('ALTER TABLE user_paradox_ledger DROP CONSTRAINT user_paradox_ledger_source_check');
        DB::statement("ALTER TABLE user_paradox_ledger ADD CONSTRAINT user_paradox_ledger_source_check CHECK (source_kind IN ('daily_login', 'daily_quest', 'command', 'compensation'))");
    }

    public function down(): void
    {
        throw new RuntimeException('The v3.9.0 compensation warehouse migration is forward-only.');
    }
};
