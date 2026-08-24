<?php

use App\Application\Ver250SecretaryProfileRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (! Schema::hasColumn('secretaries', 'profile_biography')) {
            Schema::table('secretaries', function (Blueprint $table): void {
                $table->text('profile_biography')->default(
                    "全てが謎に包まれた、長耳の秘書。\n"
                    ."かつては囚われの身になっていたが島主に救われ、後に才能を買われて秘書となった。\n"
                    .'その身に不思議な力を宿している。',
                );
                $table->string('main_image_path', 80)->nullable()->unique();
                $table->string('main_image_mime_type', 32)->nullable();
                $table->string('main_image_creation_method', 32)->nullable();
                $table->string('main_image_credit', 160)->nullable();
                $table->timestampTz('main_image_updated_at')->nullable();
            });
            DB::statement(<<<'SQL'
ALTER TABLE secretaries
  ADD CONSTRAINT secretaries_profile_biography_length_check
    CHECK (char_length(profile_biography) <= 1000),
  ADD CONSTRAINT secretaries_main_image_state_check
    CHECK (
      (main_image_path IS NULL AND main_image_mime_type IS NULL
        AND main_image_creation_method IS NULL AND main_image_credit IS NULL
        AND main_image_updated_at IS NULL)
      OR
      (main_image_path ~ '^[0-9a-f]{64}\.(png|jpg|webp|gif)$'
        AND main_image_mime_type IN ('image/png', 'image/jpeg', 'image/webp', 'image/gif')
        AND main_image_creation_method IN ('self_made', 'ai_generated', 'commissioned_or_permitted', 'other')
        AND (main_image_credit IS NULL OR char_length(main_image_credit) <= 160)
        AND main_image_updated_at IS NOT NULL)
    )
SQL);
        }

        if (! Schema::hasColumn('users', 'show_ai_generated_secretary_images')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('show_ai_generated_secretary_images')->nullable();
                $table->string('secretary_image_fallback', 16)->nullable();
            });
            DB::statement(<<<'SQL'
ALTER TABLE users
  ADD CONSTRAINT users_secretary_image_preferences_check
    CHECK (
      (show_ai_generated_secretary_images IS NULL AND secretary_image_fallback IS NULL)
      OR
      (show_ai_generated_secretary_images IS NOT NULL
        AND secretary_image_fallback IN ('silhouette', 'peridot'))
    )
SQL);
        }

        app(Ver250SecretaryProfileRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The v13 to v14 Secretary profile migration is forward-only; restore the exact supported v13 backup and re-upgrade.',
        );
    }
};
