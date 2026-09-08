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
        Schema::create('underground_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leader_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('leader_secretary_id')->constrained('secretaries')->restrictOnDelete();
            $table->string('content_type', 32);
            $table->string('content_key', 100);
            $table->string('content_identity', 128);
            $table->unsignedSmallInteger('party_size');
            $table->unsignedInteger('leader_combat_level');
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->index(['leader_user_id', 'created_at']);
        });

        Schema::create('underground_party_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_party_id')->constrained('underground_parties')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->foreignId('secretary_id')->nullable()->constrained('secretaries')->restrictOnDelete();
            $table->foreignId('source_owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('combatant_id', 100);
            $table->unsignedInteger('original_level');
            $table->unsignedInteger('effective_level');
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->unique(['underground_party_id', 'combatant_id']);
            $table->unique(['underground_party_id', 'secretary_id']);
            $table->index(['source_owner_user_id', 'source_type']);
        });

        Schema::create('secretary_lending_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->unique()->constrained('secretaries')->cascadeOnDelete();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        Schema::create('secretary_lending_participations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_battle_id')->constrained('underground_battles')->restrictOnDelete();
            $table->foreignId('underground_party_member_id')->constrained('underground_party_members')->restrictOnDelete();
            $table->foreignId('secretary_id')->constrained('secretaries')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->date('canonical_day');
            $table->string('result', 16);
            $table->unsignedInteger('ticket_delta')->default(0);
            $table->timestampTz('settled_at');
            $table->timestamps();
            $table->unique(['underground_battle_id', 'underground_party_member_id'], 'lending_participation_battle_member_unique');
            $table->index(['owner_user_id', 'canonical_day']);
        });

        Schema::create('secretary_lending_daily_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->date('canonical_day');
            $table->unsignedInteger('participation_count')->default(0);
            $table->unsignedInteger('tickets_awarded')->default(0);
            $table->timestamps();
            $table->unique(['owner_user_id', 'canonical_day']);
        });

        Schema::create('user_skip_ticket_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('user_skip_ticket_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('underground_battle_id')->nullable()->constrained('underground_battles')->nullOnDelete();
            $table->foreignId('underground_party_member_id')->nullable()->constrained('underground_party_members')->nullOnDelete();
            $table->string('entry_key', 180)->unique();
            $table->integer('delta');
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->date('canonical_day');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'canonical_day']);
        });

        Schema::table('underground_battles', function (Blueprint $table): void {
            $table->foreignId('underground_party_id')->nullable()->unique()->after('underground_profile_id')
                ->constrained('underground_parties')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE underground_parties ADD CONSTRAINT underground_parties_size_check CHECK (party_size BETWEEN 1 AND 4), ADD CONSTRAINT underground_parties_level_check CHECK (leader_combat_level >= 1)');
        DB::statement("ALTER TABLE underground_party_members ADD CONSTRAINT underground_party_members_source_check CHECK (source_type IN ('self', 'borrowed_secretary', 'companion')), ADD CONSTRAINT underground_party_members_level_check CHECK (original_level >= 1 AND effective_level >= 1 AND effective_level <= original_level)");
        DB::statement("ALTER TABLE secretary_lending_participations ADD CONSTRAINT secretary_lending_participation_result_check CHECK (result IN ('victory', 'defeat', 'withdrawal'))");
        DB::statement('ALTER TABLE secretary_lending_participations ADD CONSTRAINT secretary_lending_participation_ticket_check CHECK (ticket_delta IN (0, 1))');
        DB::statement('ALTER TABLE secretary_lending_daily_rewards ADD CONSTRAINT secretary_lending_daily_ticket_cap_check CHECK (tickets_awarded BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE user_skip_ticket_ledger ADD CONSTRAINT user_skip_ticket_ledger_delta_check CHECK (delta <> 0 AND balance_before >= 0 AND balance_after >= 0 AND balance_after = balance_before + delta)');
    }

    public function down(): void
    {
        throw new RuntimeException('The 3.8.0 party lending migration is forward-only.');
    }
};
