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
        Schema::table('secretaries', function (Blueprint $table): void {
            $table->string('nickname', 6)->nullable();
            $table->string('portrait_preference', 16)->default('full_body');
        });
        DB::statement('ALTER TABLE secretaries ADD CONSTRAINT secretaries_nickname_check CHECK (nickname IS NULL OR (char_length(nickname) BETWEEN 1 AND 6))');
        DB::statement("ALTER TABLE secretaries ADD CONSTRAINT secretaries_portrait_preference_check CHECK (portrait_preference IN ('full_body', 'bust'))");
        Schema::create('secretary_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained('secretaries')->cascadeOnDelete();
            $table->string('slot', 32);
            $table->string('path', 80);
            $table->string('mime_type', 32);
            $table->string('creation_method', 32);
            $table->string('credit', 160);
            $table->timestamps();
            $table->unique(['secretary_id', 'slot']);
            $table->unique('path');
        });
        DB::statement("ALTER TABLE secretary_images ADD CONSTRAINT secretary_images_slot_check CHECK (slot IN ('icon', 'bust', 'full_body', 'awakening_icon', 'awakening_bust', 'awakening_full_body'))");
        DB::statement("ALTER TABLE secretary_images ADD CONSTRAINT secretary_images_path_check CHECK (path ~ '^[0-9a-f]{64}\\.(png|jpg|webp|gif)$')");
        DB::statement("ALTER TABLE secretary_images ADD CONSTRAINT secretary_images_mime_check CHECK (mime_type IN ('image/png', 'image/jpeg', 'image/webp', 'image/gif'))");
        DB::statement("ALTER TABLE secretary_images ADD CONSTRAINT secretary_images_creation_method_check CHECK (creation_method IN ('self_made', 'ai_generated', 'commissioned_or_permitted', 'other'))");
        DB::statement('ALTER TABLE secretary_images ADD CONSTRAINT secretary_images_credit_length_check CHECK (char_length(credit) BETWEEN 1 AND 160)');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.8.0 Secretary nickname and image-slot migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
