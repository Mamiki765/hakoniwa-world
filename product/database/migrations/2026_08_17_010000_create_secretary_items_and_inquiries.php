<?php

use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryV1MigrationSafetyGuard;
use App\Models\Secretary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            app(SecretaryV1MigrationSafetyGuard::class)
                ->lockAndAssertNoUnresolvedNextTurnRun('ver 2.2.0 Secretary item/inquiry migration');

            $hasItems = Schema::hasTable('secretary_item_instances');
            $hasInquiries = Schema::hasTable('inquiries');
            if ($hasItems !== $hasInquiries) {
                throw new RuntimeException('ver 2.2.0 schema is only partially present; refusing an implicit repair.');
            }
            if (! $hasItems) {
                $this->createItemInstances();
                $this->createInquiries();
            }

            Secretary::query()->orderBy('id')->eachById(function (Secretary $secretary): void {
                $item = app(SecretaryItemGrantService::class)->grantStarterOldBow($secretary);
                if ($item === null) {
                    throw new RuntimeException("Secretary {$secretary->id} inventory is full; starter item backfill stopped.");
                }
            });
        });
    }

    private function createItemInstances(): void
    {
        Schema::create('secretary_item_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 64);
            $table->unsignedInteger('level');
            $table->unsignedSmallInteger('equipped_slot')->nullable();
            $table->string('grant_key', 128)->nullable();
            $table->timestampTz('obtained_at');
            $table->timestampsTz();
            $table->unique(['secretary_id', 'grant_key']);
            $table->index(['secretary_id', 'obtained_at', 'id']);
        });
        DB::statement(<<<'SQL'
ALTER TABLE secretary_item_instances
ADD CONSTRAINT secretary_item_instances_level_check CHECK (level >= 1),
ADD CONSTRAINT secretary_item_instances_equipped_slot_check CHECK (
    equipped_slot IS NULL OR equipped_slot BETWEEN 1 AND 5
)
SQL);
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX secretary_item_instances_equipped_slot_unique
ON secretary_item_instances (secretary_id, equipped_slot)
WHERE equipped_slot IS NOT NULL
SQL);
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX secretary_item_instances_old_bow_unique
ON secretary_item_instances (secretary_id)
WHERE item_key = 'old_bow'
SQL);
    }

    private function createInquiries(): void
    {
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('submission_key');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('world_id')->constrained()->restrictOnDelete();
            $table->foreignId('nation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('submitted_turn');
            $table->string('application_version', 32);
            $table->string('category', 32);
            $table->string('subject', 160);
            $table->text('body');
            $table->string('attachment_token', 64)->nullable()->unique();
            $table->string('attachment_path', 96)->nullable()->unique();
            $table->timestampsTz();
            $table->unique(['user_id', 'submission_key']);
            $table->index(['created_at', 'id']);
        });
        DB::statement(<<<'SQL'
ALTER TABLE inquiries
ADD CONSTRAINT inquiries_category_check CHECK (
    category IN ('bug', 'request', 'idea', 'secretary_fan_art', 'other')
),
ADD CONSTRAINT inquiries_attachment_pair_check CHECK (
    (attachment_token IS NULL AND attachment_path IS NULL)
    OR (attachment_token IS NOT NULL AND attachment_path IS NOT NULL)
)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The ver 2.2.0 Secretary item and inquiry migration is forward-only; restore through an explicit reviewed conversion.',
        );
    }
};
