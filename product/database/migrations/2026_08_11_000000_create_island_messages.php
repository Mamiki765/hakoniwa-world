<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('visitor_code', 8)->nullable();
            $table->timestampTz('message_board_last_posted_at')->nullable();
            $table->unique('visitor_code', 'users_visitor_code_unique');
        });

        Schema::table('nations', function (Blueprint $table): void {
            $table->unique(['world_id', 'id'], 'nations_world_id_id_unique');
        });

        Schema::create('island_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('target_nation_id');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('author_kind', 16);
            $table->unsignedBigInteger('author_nation_id')->nullable();
            $table->unsignedBigInteger('secret_sender_nation_id')->nullable();
            $table->string('message_type', 16);
            $table->text('body');
            $table->timestampsTz();

            $table->foreign(['world_id', 'target_nation_id'], 'island_messages_target_world_fk')
                ->references(['world_id', 'id'])->on('nations')->restrictOnDelete();
            $table->foreign(['world_id', 'author_nation_id'], 'island_messages_author_world_fk')
                ->references(['world_id', 'id'])->on('nations')->restrictOnDelete();
            $table->foreign(['world_id', 'secret_sender_nation_id'], 'island_messages_sender_world_fk')
                ->references(['world_id', 'id'])->on('nations')->restrictOnDelete();

            $table->index(
                ['target_nation_id', 'created_at', 'id'],
                'island_messages_target_timeline_idx',
            );
            $table->index(
                ['secret_sender_nation_id', 'created_at', 'id'],
                'island_messages_sender_timeline_idx',
            );
            $table->index(['author_user_id', 'created_at'], 'island_messages_author_cooldown_audit_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE users
ADD CONSTRAINT users_visitor_code_format_check
CHECK (visitor_code IS NULL OR visitor_code ~ '^[A-Za-z0-9]{8}$')
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE island_messages
ADD CONSTRAINT island_messages_body_length_check
CHECK (char_length(body) BETWEEN 1 AND 140)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE island_messages
ADD CONSTRAINT island_messages_type_shape_check
CHECK (
    (
        message_type = 'public'
        AND secret_sender_nation_id IS NULL
        AND (
            (author_kind = 'visitor' AND author_nation_id IS NULL)
            OR (author_kind = 'nation' AND author_nation_id IS NOT NULL)
        )
    )
    OR
    (
        message_type = 'secret'
        AND author_kind = 'nation'
        AND author_nation_id IS NOT NULL
        AND secret_sender_nation_id IS NOT NULL
        AND secret_sender_nation_id = author_nation_id
        AND secret_sender_nation_id <> target_nation_id
    )
)
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('island_messages');

        Schema::table('nations', function (Blueprint $table): void {
            $table->dropUnique('nations_world_id_id_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_visitor_code_unique');
            $table->dropColumn(['visitor_code', 'message_board_last_posted_at']);
        });
    }
};
