<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\World\WorldMutationLock;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryPersistenceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const PROBE_CONNECTION = 'pgsql-secretary-migration-lock-probe';

    public function test_current_secretary_initialization_does_not_require_the_historical_v7_source(): void
    {
        $publishedRulesets = config('hakoniwa.published_rulesets');
        unset($publishedRulesets['hakoniwa-2s-plus-v7']);
        config(['hakoniwa.published_rulesets' => $publishedRulesets]);
        $world = $this->lightweightWorld();
        $user = User::factory()->create();

        app(NationCreationService::class)->create($user, $world, '現行秘書島', '現行島主');

        $skills = $user->secretary()->firstOrFail()->skills()->pluck('level', 'skill_key');
        $this->assertSame(0, $skills[SecretarySkillCatalog::AGRICULTURAL_POLICY]);
        $this->assertSame(0, $skills[SecretarySkillCatalog::SPECIALTY_DEVELOPMENT]);
        $this->assertSame(0, $skills[SecretarySkillCatalog::GOLD_VEIN_SURVEY]);
        $this->assertSame(1, $skills[SecretarySkillCatalog::FINAL_DEFENSE_LINE]);
    }

    public function test_first_successful_registration_creates_one_unnamed_secretary_and_replay_is_idempotent(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $requestKey = (string) Str::uuid();
        $service = app(NationCreationService::class);

        $this->assertNull($user->secretary()->first());
        $nation = $service->create($user, $world, '秘書作成島', '秘書作成島主', '', $requestKey);
        $secretary = $user->secretary()->with('skills')->sole();

        $this->assertNull($secretary->name);
        $this->assertNull($secretary->named_at);
        $skills = $secretary->skills->keyBy('skill_key');
        $this->assertSame(
            collect(SecretarySkillCatalog::KEYS)->sort()->values()->all(),
            $skills->keys()->sort()->values()->all(),
        );
        $this->assertSame(0, $skills[SecretarySkillCatalog::AGRICULTURAL_POLICY]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::SPECIALTY_DEVELOPMENT]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::GOLD_VEIN_SURVEY]->level);
        $this->assertSame(1, $skills[SecretarySkillCatalog::FINAL_DEFENSE_LINE]->level);
        $this->assertSame([0], $skills->pluck('experience')->unique()->values()->all());

        $replayed = $service->create($user, $world->fresh(), '別入力', '別入力', '', $requestKey);
        $this->assertSame($nation->id, $replayed->id);
        $this->assertSame(1, Secretary::query()->where('user_id', $user->id)->count());
        $this->assertSame(4, SecretarySkill::query()->where('secretary_id', $secretary->id)->count());
    }

    public function test_user_id_is_unique_and_different_users_may_choose_the_same_name_once(): void
    {
        $world = $this->lightweightWorld();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $service = app(NationCreationService::class);
        $service->create($first, $world, '第一秘書島', '第一島主');
        $service->create($second, $world->fresh(), '第二秘書島', '第二島主');

        foreach ([$first, $second] as $user) {
            $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => 'ペリドット'])
                ->assertOk()
                ->assertJsonPath('data.name', 'ペリドット')
                ->assertJsonPath('data.header_label', 'ペリドット')
                ->assertJsonPath('data.skills.0.effect', '小麦生産＋0.0%')
                ->assertJsonPath('data.skills.3.effect', '防衛されなかったミサイルを1ターンにつき1発まで迎撃')
                ->assertJsonCount(4, 'data.skills');
        }
        $this->assertSame(2, Secretary::query()->where('name', 'ペリドット')->count());

        $this->actingAs($first)->postJson('/api/v1/me/secretary/name', ['name' => '変更名'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Secretaryはすでに命名されています。');
        $this->assertSame('ペリドット', $first->secretary()->value('name'));

        $secretary = $first->secretary()->firstOrFail();
        $this->expectException(QueryException::class);
        Secretary::query()->create(['user_id' => $first->id, 'name' => null, 'named_at' => null]);
    }

    public function test_abandonment_and_reregistration_reuse_the_same_name_levels_and_experience(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $service = app(NationCreationService::class);
        $first = $service->create($user, $world, '初代秘書島', '初代島主');
        $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => '改名前'])
            ->assertOk();
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/name', ['name' => '継承名'])
            ->assertOk();
        $secretary = $user->secretary()->firstOrFail();
        SecretarySkill::query()
            ->where('secretary_id', $secretary->id)
            ->where('skill_key', SecretarySkillCatalog::AGRICULTURAL_POLICY)
            ->update(['level' => 4, 'experience' => 7]);
        $item = $secretary->itemInstances()->sole();

        app(NationAbandonmentService::class)->abandon($user, $first, $first->name);
        $this->assertDatabaseHas('secretaries', ['id' => $secretary->id, 'user_id' => $user->id, 'name' => '継承名']);
        $this->assertDatabaseHas('secretary_item_instances', [
            'id' => $item->id,
            'secretary_id' => $secretary->id,
            'item_key' => 'old_bow',
            'equipped_slot' => 1,
        ]);
        $second = $service->create($user, $world->fresh(), '二代目秘書島', '二代目島主');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($secretary->id, $user->secretary()->value('id'));
        $this->assertDatabaseHas('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::AGRICULTURAL_POLICY,
            'level' => 4,
            'experience' => 7,
        ]);
        $this->assertSame($item->id, $user->secretary()->firstOrFail()->itemInstances()->sole()->id);
    }

    public function test_named_secretary_can_be_renamed_repeatedly_without_creation_or_skill_changes(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $other = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '改名島', '改名島主');
        app(NationCreationService::class)->create($other, $world->fresh(), '同名島', '同名島主');

        $this->actingAs($user)->patchJson('/api/v1/me/secretary/name', ['name' => '未命名から改名'])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => 'ペリドット'])
            ->assertOk();
        $this->actingAs($other)->postJson('/api/v1/me/secretary/name', ['name' => 'エメラルド'])
            ->assertOk();

        $secretary = $user->secretary()->firstOrFail();
        $namedAt = $secretary->named_at;
        $skills = SecretarySkill::query()->where('secretary_id', $secretary->id)
            ->orderBy('skill_key')->get(['skill_key', 'level', 'experience'])->toArray();
        foreach (['エメラルド', 'サファイア'] as $name) {
            $this->actingAs($user)->patchJson('/api/v1/me/secretary/name', ['name' => $name])
                ->assertOk()
                ->assertJsonPath('data.name', $name);
        }

        $secretary->refresh();
        $this->assertSame('サファイア', $secretary->name);
        $this->assertTrue($namedAt?->equalTo($secretary->named_at));
        $this->assertSame(2, Secretary::query()->count());
        $this->assertSame($skills, SecretarySkill::query()->where('secretary_id', $secretary->id)
            ->orderBy('skill_key')->get(['skill_key', 'level', 'experience'])->toArray());
        $renames = DB::table('audit_events')->where('event_type', 'secretary.renamed')
            ->where('subject_id', $secretary->id)->orderBy('id')->get();
        $this->assertCount(2, $renames);
        $metadata = json_decode((string) $renames->last()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals([
            'secretary_id' => $secretary->id,
            'user_id' => $user->id,
            'old_name' => 'エメラルド',
            'new_name' => 'サファイア',
        ], array_intersect_key($metadata, array_flip(['secretary_id', 'user_id', 'old_name', 'new_name'])));
        $this->assertArrayHasKey('occurred_at', $metadata);
        $this->assertSame('private', $renames->last()->visibility);

        $this->actingAs(User::factory()->create())
            ->patchJson('/api/v1/me/secretary/name', ['name' => '勝手に作成'])
            ->assertUnprocessable();
        $this->assertSame(2, Secretary::query()->count());
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/name', ['name' => "改行\n名"])
            ->assertUnprocessable();
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/name', ['name' => '<b>秘書</b>'])
            ->assertUnprocessable();
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/name', ['name' => str_repeat('あ', 31)])
            ->assertUnprocessable();
        $this->assertSame('サファイア', $user->secretary()->value('name'));
    }

    public function test_backfill_is_idempotent_and_its_exact_user_id_set_excludes_users_without_history(): void
    {
        $world = $this->lightweightWorld();
        $activeUser = User::factory()->create();
        $abandonedUser = User::factory()->create();
        $noHistoryUser = User::factory()->create();
        $service = app(NationCreationService::class);
        $service->create($activeUser, $world, '現役履歴島', '現役島主');
        $abandoned = $service->create($abandonedUser, $world->fresh(), '破棄履歴島', '破棄島主');
        app(NationAbandonmentService::class)->abandon($abandonedUser, $abandoned, $abandoned->name);

        Schema::drop('secretary_item_instances');
        Schema::drop('secretary_skills');
        Schema::drop('secretaries');
        $migration = $this->secretaryMigration();
        $migration->up();
        $migration->up();

        $this->assertSame(
            [$activeUser->id, $abandonedUser->id],
            Secretary::query()->orderBy('user_id')->pluck('user_id')->all(),
        );
        $this->assertSame(0, Secretary::query()->where('user_id', $noHistoryUser->id)->count());
        $this->assertSame(8, SecretarySkill::query()->count());
    }

    public function test_backfill_rerun_fails_closed_on_an_unexpected_secretary_user_id(): void
    {
        $world = $this->lightweightWorld();
        $historyUser = User::factory()->create();
        $noHistoryUser = User::factory()->create();
        app(NationCreationService::class)->create($historyUser, $world, '集合検証島', '集合検証島主');
        Schema::drop('secretary_item_instances');
        Schema::drop('secretary_skills');
        Schema::drop('secretaries');
        $migration = $this->secretaryMigration();
        $migration->up();
        Secretary::query()->create([
            'user_id' => $noHistoryUser->id,
            'name' => null,
            'named_at' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Secretary backfill user_id set does not exactly match the Nation-history User set.',
        );
        $migration->up();
    }

    #[DataProvider('unresolvedTurnStatuses')]
    public function test_secretary_migration_rejects_unresolved_next_turn_before_schema_or_backfill(
        string $status,
    ): void {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, "020000拒否{$status}島", '拒否島主');
        $run = $this->turnRun($world, $status);
        $runBefore = $run->fresh()->getAttributes();
        Schema::drop('secretary_item_instances');
        Schema::drop('secretary_skills');
        Schema::drop('secretaries');

        try {
            $this->secretaryMigration()->up();
            $this->fail("Expected {$status} next TurnRun to block the Secretary migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('secretaries'));
        $this->assertFalse(Schema::hasTable('secretary_skills'));
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
    }

    public function test_secretary_migration_allows_a_resolved_next_turn(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '020000解決済島', '解決済島主');
        $run = $this->turnRun($world, TurnRun::STATUS_COMPLETED);
        $runBefore = $run->fresh()->getAttributes();
        Schema::drop('secretary_item_instances');
        Schema::drop('secretary_skills');
        Schema::drop('secretaries');

        $this->secretaryMigration()->up();

        $this->assertTrue(Schema::hasTable('secretaries'));
        $this->assertTrue(Schema::hasTable('secretary_skills'));
        $this->assertDatabaseHas('secretaries', ['user_id' => $user->id]);
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
    }

    public function test_secretary_migration_uses_the_existing_world_turn_lock_before_schema_creation(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '020000 lock島', 'lock島主');
        Schema::drop('secretary_item_instances');
        Schema::drop('secretary_skills');
        Schema::drop('secretaries');
        $primaryConnection = DB::getDefaultConnection();
        config([
            'database.connections.'.self::PROBE_CONNECTION => config(
                'database.connections.'.$primaryConnection,
            ),
        ]);
        $probe = DB::connection(self::PROBE_CONNECTION);
        $lockKey = app(WorldMutationLock::class)->key($world);
        $acquired = $probe->selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$lockKey],
        );
        $this->assertTrue($acquired->acquired);

        try {
            $this->secretaryMigration()->up();
            $this->fail('Expected the shared World turn lock to block the Secretary migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('turn operation holds its advisory lock', $exception->getMessage());
        } finally {
            $released = $probe->selectOne(
                'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
                [$lockKey],
            );
            $this->assertTrue($released->released);
            DB::purge(self::PROBE_CONNECTION);
        }

        $this->assertFalse(Schema::hasTable('secretaries'));
        $this->assertFalse(Schema::hasTable('secretary_skills'));
    }

    public function test_secretary_migration_allows_a_fresh_install_without_shared_world(): void
    {
        $this->assertFalse(DB::table('worlds')->where('key', 'shared-world')->exists());
        Schema::drop('secretary_item_instances');
        Schema::drop('secretary_skills');
        Schema::drop('secretaries');

        $this->secretaryMigration()->up();

        $this->assertTrue(Schema::hasTable('secretaries'));
        $this->assertTrue(Schema::hasTable('secretary_skills'));
        $this->assertDatabaseCount('secretaries', 0);
        $this->assertDatabaseCount('secretary_skills', 0);
    }

    public function test_naming_requires_a_secretary_and_safe_single_line_plain_text(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/me/secretary')
            ->assertOk()->assertJsonPath('data', null);
        $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => 'ペリドット'])
            ->assertUnprocessable();

        $world = $this->lightweightWorld();
        app(NationCreationService::class)->create($user, $world, '命名検証島', '命名島主');
        $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => "改行\n名"])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => '<b>秘書</b>'])
            ->assertUnprocessable();
        $this->assertNull($user->secretary()->value('name'));
    }

    private function secretaryMigration(): Migration
    {
        return require database_path('migrations/2026_08_16_020000_create_secretary_system.php');
    }

    private function turnRun(World $world, string $status): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('2', 64),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => ['preserve' => true],
        ]);
    }

    /** @return array<string, array{string}> */
    public static function unresolvedTurnStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }
}
