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
        Schema::create('guide_conversation_topics', function (Blueprint $table): void {
            $table->id();
            $table->text('initial_line');
            $table->text('choice_1');
            $table->text('reply_1');
            $table->text('choice_2')->nullable();
            $table->text('reply_2')->nullable();
            $table->text('choice_3')->nullable();
            $table->text('reply_3')->nullable();
            $table->string('unlock_key', 80)->default('always');
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['enabled', 'unlock_key']);
        });

        DB::statement('ALTER TABLE guide_conversation_topics ADD CONSTRAINT guide_conversation_topics_choice_2_pair_check CHECK ((choice_2 IS NULL) = (reply_2 IS NULL))');
        DB::statement('ALTER TABLE guide_conversation_topics ADD CONSTRAINT guide_conversation_topics_choice_3_pair_check CHECK ((choice_3 IS NULL) = (reply_3 IS NULL))');

        Schema::create('secretary_guide_conversation_totals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->unique()->constrained('secretaries')->cascadeOnDelete();
            $table->unsignedBigInteger('topics_started')->default(0);
            $table->unsignedBigInteger('normal_replies')->default(0);
            $table->unsignedBigInteger('punch_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The v3.9.0 guide conversation migration is forward-only.');
    }
};
