<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\RulesetVersion;
use App\Models\Secretary;
use App\Models\SecretaryImage;
use App\Models\SecretarySkill;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class Ver381MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const MIGRATION = '2026_09_09_020000_prepare_3_8_1_ui_and_bulk_skip';

    public function test_exact_380_upgrade_migrates_legacy_main_without_overwriting_full_body_or_inventing_credit(): void
    {
        $this->returnSchemaToExact380Source();
        $legacyOwner = User::factory()->create();
        $legacy = Secretary::query()->create([
            'user_id' => $legacyOwner->id,
            'name' => '旧画像のみ',
            'named_at' => now(),
            'main_image_path' => str_repeat('a', 64).'.webp',
            'main_image_mime_type' => 'image/webp',
            'main_image_creation_method' => 'self_made',
            'main_image_credit' => null,
            'main_image_updated_at' => now()->subDay(),
        ]);
        SecretaryImage::query()->create([
            'secretary_id' => $legacy->id,
            'slot' => 'bust',
            'path' => str_repeat('a', 64).'.webp',
            'mime_type' => 'image/webp',
            'creation_method' => 'self_made',
            'credit' => 'same-file-bust',
        ]);
        SecretarySkill::query()->create([
            'secretary_id' => $legacy->id,
            'skill_key' => 'ship_operations',
            'level' => 0,
            'experience' => 650,
        ]);
        $currentOwner = User::factory()->create();
        $current = Secretary::query()->create([
            'user_id' => $currentOwner->id,
            'name' => '両画像あり',
            'named_at' => now(),
            'main_image_path' => str_repeat('b', 64).'.webp',
            'main_image_mime_type' => 'image/webp',
            'main_image_creation_method' => 'other',
            'main_image_credit' => '旧作者',
            'main_image_updated_at' => now()->subDay(),
        ]);
        SecretaryImage::query()->create([
            'secretary_id' => $current->id,
            'slot' => 'full_body',
            'path' => str_repeat('c', 64).'.webp',
            'mime_type' => 'image/webp',
            'creation_method' => 'self_made',
            'credit' => '現作者',
        ]);

        $rulesetCount = DB::table('ruleset_versions')->count();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $migrated = SecretaryImage::query()->where('secretary_id', $legacy->id)->where('slot', 'full_body')->sole();
        $this->assertSame(str_repeat('a', 64).'.webp', $migrated->path);
        $this->assertSame('image/webp', $migrated->mime_type);
        $this->assertSame('self_made', $migrated->creation_method);
        $this->assertNull($migrated->credit);
        $this->assertSame(2, SecretaryImage::query()->where('secretary_id', $legacy->id)->where('path', $migrated->path)->count());
        $this->assertSame(str_repeat('c', 64).'.webp', SecretaryImage::query()->where('secretary_id', $current->id)->where('slot', 'full_body')->value('path'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'main_image_path'));
        $this->assertTrue(Schema::hasTable('underground_skip_batches'));
        $this->assertTrue(Schema::hasColumn('user_skip_ticket_ledger', 'underground_skip_batch_id'));
        $this->assertTrue(Schema::hasColumn('underground_owned_equipment', 'source_skip_batch_id'));
        $this->assertSame(3, $legacy->skills()->where('skill_key', 'ship_operations')->value('level'));
        $this->assertSame(50, $legacy->skills()->where('skill_key', 'ship_operations')->value('experience'));
        $this->assertSame($rulesetCount + 1, DB::table('ruleset_versions')->count());
        $this->assertDatabaseHas('ruleset_versions', [
            'key' => 'hakoniwa-2s-plus-v22',
            'version' => 22,
        ]);
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_exact_v21_world_upgrades_atomically_and_rebases_ship_operations_progression(): void
    {
        $world = $this->lightweightWorld();
        $this->returnSchemaToExact380Source();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world->fresh(), '移行島', '移行主');
        $skill = $owner->secretary()->firstOrFail()->skills()
            ->where('skill_key', 'ship_operations')->firstOrFail();
        $skill->update(['level' => 1, 'experience' => 550]);

        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame('hakoniwa-2s-plus-v22', $world->fresh()->rulesetVersion()->value('key'));
        $this->assertSame(3, $skill->fresh()->level);
        $this->assertSame(50, $skill->fresh()->experience);
        $this->assertDatabaseHas('audit_events', [
            'world_id' => $world->id,
            'event_type' => 'ruleset.v22_activated',
            'visibility' => 'admin',
        ]);
    }

    private function returnSchemaToExact380Source(): void
    {
        DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->dropForeign(['source_skip_batch_id']);
            $table->dropUnique('underground_equipment_source_skip_batch_reward_unique');
            $table->dropColumn('source_skip_batch_id');
        });
        DB::statement('ALTER TABLE underground_owned_equipment ALTER COLUMN source_reward_index TYPE smallint');
        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  ADD CONSTRAINT underground_owned_equipment_instance_check
  CHECK (
    (
      instance_kind = 'fixed'
      AND instance_identity IS NULL
      AND generator_identity IS NULL
      AND generated_payload IS NULL
      AND source_battle_id IS NULL
      AND source_skip_settlement_id IS NULL
      AND source_reward_index IS NULL
    )
    OR
    (
      instance_kind = 'generated'
      AND instance_identity IS NOT NULL
      AND generator_identity IS NOT NULL
      AND generated_payload IS NOT NULL
      AND grant_key IS NOT NULL
      AND (
        (
          source_battle_id IS NOT NULL
          AND source_skip_settlement_id IS NULL
          AND source_reward_index IS NULL
        )
        OR
        (
          source_battle_id IS NULL
          AND source_skip_settlement_id IS NOT NULL
          AND source_reward_index BETWEEN 1 AND 10
        )
      )
    )
  )
SQL);
        DB::statement('ALTER TABLE user_skip_ticket_ledger DROP CONSTRAINT user_skip_ticket_ledger_skip_source_check');
        Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
            $table->dropForeign(['underground_skip_batch_id']);
            $table->dropUnique('user_skip_ticket_ledger_skip_batch_unique');
            $table->dropColumn('underground_skip_batch_id');
        });
        Schema::drop('underground_skip_batches');
        DB::statement('ALTER TABLE secretary_images ALTER COLUMN credit SET NOT NULL');
        Schema::table('secretary_images', function (Blueprint $table): void {
            $table->unique('path');
        });
        $v21 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v21.php');
        config([
            'hakoniwa.ruleset' => $v21,
            'hakoniwa.published_rulesets' => [$v21['key'] => $v21],
        ]);
        $v21Row = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v21')->sole();
        $v22Row = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v22')->first();
        if ($v22Row instanceof RulesetVersion) {
            DB::table('worlds')->where('ruleset_version_id', $v22Row->id)->update([
                'ruleset_version_id' => $v21Row->id,
                'updated_at' => now(),
            ]);
            $v22Row->delete();
        }
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
    }
}
