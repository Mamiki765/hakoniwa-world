<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('display_name')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('ruleset_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('version');
            $table->jsonb('settings');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('worlds', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->foreignId('ruleset_version_id')->constrained();
            $table->unsignedBigInteger('current_turn')->default(0);
            $table->timestamps();
        });

        Schema::create('map_spaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('coordinate_system')->default('pointy_top_axial');
            $table->integer('min_q');
            $table->integer('max_q');
            $table->integer('min_r');
            $table->integer('max_r');
            $table->timestamps();
            $table->unique(['world_id', 'key']);
        });

        Schema::create('terrain_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('asset_key')->unique();
            $table->boolean('is_water')->default(false);
            $table->boolean('is_buildable')->default(false);
            $table->timestamps();
        });

        Schema::create('facility_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('asset_key')->unique();
            $table->timestamps();
        });

        Schema::create('resource_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category')->index();
            $table->string('unit');
            $table->decimal('nutrition_per_unit', 12, 4)->nullable();
            $table->boolean('storable')->default(true);
            $table->boolean('tradable')->default(false);
            $table->string('sale_price_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('nations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('money')->default(100);
            $table->string('state')->default('active');
            $table->timestamps();
            $table->unique(['world_id', 'name']);
        });

        Schema::create('nation_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();
            $table->unique(['nation_id', 'resource_definition_id']);
        });

        Schema::create('nation_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();
            $table->unique(['user_id', 'world_id']);
            $table->unique(['nation_id', 'user_id']);
        });

        Schema::create('map_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_space_id')->constrained()->cascadeOnDelete();
            $table->integer('chunk_q');
            $table->integer('chunk_r');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('generated_at')->nullable();
            $table->string('generator_id')->nullable();
            $table->string('generator_version')->nullable();
            $table->string('generation_seed')->nullable();
            $table->timestamps();
            $table->unique(['map_space_id', 'chunk_q', 'chunk_r']);
        });

        Schema::create('map_cells', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_chunk_id')->constrained()->cascadeOnDelete();
            $table->integer('q');
            $table->integer('r');
            $table->integer('chunk_q');
            $table->integer('chunk_r');
            $table->unsignedSmallInteger('local_q');
            $table->unsignedSmallInteger('local_r');
            $table->foreignId('terrain_definition_id')->constrained();
            $table->foreignId('facility_definition_id')->nullable()->constrained();
            $table->foreignId('owner_nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->unsignedBigInteger('population')->default(0);
            $table->string('state')->default('generated');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['map_space_id', 'q', 'r']);
            $table->index(['map_space_id', 'chunk_q', 'chunk_r']);
            $table->index(['owner_nation_id', 'map_space_id']);
        });

        Schema::create('nation_capitals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('map_cell_id')->unique()->constrained('map_cells')->cascadeOnDelete();
            $table->integer('q');
            $table->integer('r');
            $table->timestamps();
        });

        Schema::create('world_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_space_id')->constrained()->cascadeOnDelete();
            $table->string('generator_id');
            $table->string('generator_version');
            $table->string('seed');
            $table->string('status');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['map_space_id', 'generator_id', 'generator_version', 'seed']);
        });

        Schema::create('nation_creation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->integer('reserved_q')->nullable();
            $table->integer('reserved_r')->nullable();
            $table->string('generation_seed');
            $table->timestamps();
            $table->unique(['user_id', 'world_id']);
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('nation_creation_requests');
        Schema::dropIfExists('world_generation_runs');
        Schema::dropIfExists('nation_capitals');
        Schema::dropIfExists('map_cells');
        Schema::dropIfExists('map_chunks');
        Schema::dropIfExists('nation_memberships');
        Schema::dropIfExists('nation_resources');
        Schema::dropIfExists('nations');
        Schema::dropIfExists('resource_definitions');
        Schema::dropIfExists('facility_definitions');
        Schema::dropIfExists('terrain_definitions');
        Schema::dropIfExists('map_spaces');
        Schema::dropIfExists('worlds');
        Schema::dropIfExists('ruleset_versions');
        Schema::dropIfExists('auth_identities');
    }
};
