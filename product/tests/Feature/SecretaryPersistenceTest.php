<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryPersistenceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

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
        $secretary = $user->secretary()->firstOrFail();
        $secretary->update(['name' => '継承名', 'named_at' => now()]);
        SecretarySkill::query()
            ->where('secretary_id', $secretary->id)
            ->where('skill_key', SecretarySkillCatalog::AGRICULTURAL_POLICY)
            ->update(['level' => 4, 'experience' => 7]);

        app(NationAbandonmentService::class)->abandon($user, $first, $first->name);
        $this->assertDatabaseHas('secretaries', ['id' => $secretary->id, 'user_id' => $user->id, 'name' => '継承名']);
        $second = $service->create($user, $world->fresh(), '二代目秘書島', '二代目島主');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($secretary->id, $user->secretary()->value('id'));
        $this->assertDatabaseHas('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::AGRICULTURAL_POLICY,
            'level' => 4,
            'experience' => 7,
        ]);
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
}
