<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryPersistenceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_current_secretary_initialization_uses_the_current_catalog(): void
    {
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
}
