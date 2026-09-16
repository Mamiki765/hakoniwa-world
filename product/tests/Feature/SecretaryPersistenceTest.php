<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Application\SecretaryImageRetentionService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryProfilePresenter;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Secretary;
use App\Models\SecretaryImage;
use App\Models\SecretarySkill;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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
        $this->assertSame(
            "全てが謎に包まれた、長耳の秘書。\n"
            ."かつては囚われの身になっていたが島主に救われ、後に才能を買われて秘書となった。\n"
            .'その身に不思議な力を宿している。',
            $secretary->profile_biography,
        );
        $skills = $secretary->skills->keyBy('skill_key');
        $this->assertSame(
            collect(SecretarySkillCatalog::V26_KEYS)->sort()->values()->all(),
            $skills->keys()->sort()->values()->all(),
        );
        $this->assertSame(0, $skills[SecretarySkillCatalog::AGRICULTURAL_POLICY]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::SPECIALTY_DEVELOPMENT]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::GOLD_VEIN_SURVEY]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::FOREST_MANAGEMENT]->level);
        $this->assertSame(1, $skills[SecretarySkillCatalog::FINAL_DEFENSE_LINE]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::INDOMITABLE]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::SHIP_OPERATIONS]->level);
        $this->assertSame(0, $skills[SecretarySkillCatalog::NAVY]->level);
        $this->assertSame([0], $skills->pluck('experience')->unique()->values()->all());
        $this->assertDatabaseHas('secretary_item_instances', [
            'secretary_id' => $secretary->id,
            'item_key' => SecretaryItemCatalog::OLD_BOW,
            'level' => 1,
            'equipped_slot' => 1,
            'grant_key' => SecretaryItemGrantService::STARTER_OLD_BOW_GRANT,
        ]);
        $this->assertSame(
            (int) DB::table('map_cells')->where('owner_nation_id', $nation->id)->sum('population'),
            (int) $nation->fresh()->population_high_water,
        );

        $replayed = $service->create($user, $world->fresh(), '別入力', '別入力', '', $requestKey);
        $this->assertSame($nation->id, $replayed->id);
        $this->assertSame(1, Secretary::query()->where('user_id', $user->id)->count());
        $this->assertSame(9, SecretarySkill::query()->where('secretary_id', $secretary->id)->count());
        $this->assertSame(1, $secretary->itemInstances()->count());
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
            $user->secretary()->firstOrFail()->skills()
                ->where('skill_key', SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY)
                ->update(['level' => 10, 'experience' => 460_000]);
            $user->secretary()->firstOrFail()->skills()
                ->where('skill_key', SecretarySkillCatalog::INDOMITABLE)
                ->update(['level' => 10, 'experience' => 0]);
            $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => 'ペリドット'])
                ->assertOk()
                ->assertJsonPath('data.name', 'ペリドット')
                ->assertJsonPath('data.header_label', 'ペリドット')
                ->assertJsonPath('data.skills.0.effect', '小麦生産＋0.0%')
                ->assertJsonPath('data.skills.3.effect', '伐採資金・森林増加＋0%')
                ->assertJsonPath('data.skills.4.effect', '防衛されなかったミサイルを1ターンにつき1発まで迎撃')
                ->assertJsonPath('data.skills.5.effect', '自然人口上限 +500人 / 誘致人口上限 +1,000人')
                ->assertJsonPath('data.skills.6.effect', '自然人口増加 +2.50%')
                ->assertJsonPath('data.skills.7.effect', '準備中')
                ->assertJsonPath('data.skills.8.effect', '効果なし')
                ->assertJsonCount(9, 'data.skills');
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
        $secretary->update(['monster_experience' => 42]);
        $item = $secretary->itemInstances()->sole();

        app(NationAbandonmentService::class)->abandon($user, $first, $first->name);
        $this->assertDatabaseHas('secretaries', [
            'id' => $secretary->id,
            'user_id' => $user->id,
            'name' => '継承名',
            'monster_experience' => 42,
        ]);
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
        $this->assertSame(42, (int) $user->secretary()->value('monster_experience'));
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

    public function test_public_profile_uses_canonical_level_equipment_and_owner_fallback_preferences(): void
    {
        $this->installSecretaryFallbackAssets('peridot.png', 'silhouette.png');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '公開秘書島', '公開島主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => 'ペリドット'])->assertOk();
        $secretary = $owner->secretary()->firstOrFail();
        foreach ([
            SecretarySkillCatalog::AGRICULTURAL_POLICY => 5,
            SecretarySkillCatalog::SPECIALTY_DEVELOPMENT => 4,
            SecretarySkillCatalog::GOLD_VEIN_SURVEY => 3,
            SecretarySkillCatalog::FOREST_MANAGEMENT => 2,
            SecretarySkillCatalog::FINAL_DEFENSE_LINE => 6,
        ] as $skillKey => $level) {
            $secretary->skills()->where('skill_key', $skillKey)->update(['level' => $level]);
        }
        $secretary->update(['monster_experience' => 120]);
        UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'combat_level' => 37,
        ]);

        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'biography' => "海辺で出会った秘書。\n**この記号はMarkdownとして解釈しない。**",
        ])->assertOk()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.domestic_level', 20)
            ->assertJsonPath('data.secretary_level', 20)
            ->assertJsonPath('data.passive_level_total', 20)
            ->assertJsonPath('data.capacity_bonus_percent', 20)
            ->assertJsonPath('data.monster_experience', 120)
            ->assertJsonPath('data.combat_level', 37)
            ->assertJsonCount(5, 'data.equipment.slots');

        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'biography' => '<b>HTMLは不可</b>',
        ])->assertUnprocessable()->assertJsonValidationErrors('biography');

        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'biography' => '',
        ])->assertOk()->assertJsonPath('data.biography', '');
        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'biography' => "海辺で出会った秘書。\n**この記号はMarkdownとして解釈しない。**",
        ])->assertOk();

        auth()->logout();
        $publicResponse = $this->getJson("/api/v1/secretaries/{$secretary->id}?world_id={$world->id}");
        $publicResponse->assertOk()
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.domestic_level', 20)
            ->assertJsonPath('data.secretary_level', 20)
            ->assertJsonPath('data.monster_experience', 120)
            ->assertJsonPath('data.biography', "海辺で出会った秘書。\n**この記号はMarkdownとして解釈しない。**")
            ->assertJsonPath('data.main_image.display', 'none')
            ->assertJsonPath('data.viewer_preferences.configured', false)
            ->assertJsonPath('data.viewer_preferences.can_update', false)
            ->assertJsonPath('data.equipment.slots.0.item.name', '古びた弓')
            ->assertJsonCount(5, 'data.equipment.slots');
        $this->assertStringContainsString('private', (string) $publicResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $publicResponse->headers->get('Cache-Control'));

        $nation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => $world->current_turn,
        ]);
        $this->getJson("/api/v1/secretaries/{$secretary->id}?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.equipment.slots.0.item.effect_text',
                '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            );

        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => true,
            'own_secretary_fallback' => 'silhouette',
        ])->assertOk()
            ->assertJsonPath('data.own_secretary_fallback', 'silhouette');

        $viewer = User::factory()->create();
        $this->actingAs($viewer)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => true,
            'fallback' => 'peridot',
        ])->assertOk();
        $fallbackResponse = $this->actingAs($viewer->refresh())
            ->getJson("/api/v1/secretaries/{$secretary->id}?world_id={$world->id}");
        $fallbackResponse
            ->assertOk()
            ->assertJsonPath('data.main_image.display', 'silhouette')
            ->assertJsonPath('data.viewer_preferences.configured', true)
            ->assertJsonPath('data.viewer_preferences.own_secretary_fallback', 'peridot')
            ->assertJsonPath('data.viewer_preferences.fallback', 'peridot');
        $this->assertStringContainsString(
            '/assets/hakoniwa-tiles/peridot/silhouette.png?v=',
            (string) $fallbackResponse->json('data.main_image.url'),
        );

        $this->actingAs($viewer)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => false,
            'fallback' => 'silhouette',
        ])->assertOk();
        $this->actingAs($viewer->refresh())
            ->getJson("/api/v1/secretaries/{$secretary->id}?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath('data.main_image.display', 'none')
            ->assertJsonPath('data.main_image.url', null);
    }

    public function test_full_body_slot_reuses_safe_upload_boundary_replaces_the_old_file_and_honors_ai_suppression(): void
    {
        Storage::fake('secretary_images');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, '画像秘書島', '画像島主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => '画像秘書'])->assertOk();
        $secretary = $owner->secretary()->firstOrFail();

        $this->actingAs($owner)->post('/api/v1/me/secretary/images/full_body', [
            'image' => UploadedFile::fake()->createWithContent(
                'dangerous.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            ),
            'creation_method' => 'self_made',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        Storage::disk('secretary_images')->assertDirectoryEmpty('/');

        $this->actingAs($owner)->post('/api/v1/me/secretary/images/full_body', [
            'image' => UploadedFile::fake()->createWithContent('first-original-name.png', $this->portraitPng()),
            'creation_method' => 'self_made',
            'credit' => 'Owner / all rights reserved',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.main_image.display', 'uploaded')
            ->assertJsonPath('data.images.full_body.editable_metadata.creation_method', 'self_made');
        $firstPath = (string) SecretaryImage::query()->where('secretary_id', $secretary->id)->where('slot', 'full_body')->value('path');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\.png\z/', $firstPath);
        $this->assertStringNotContainsString('first-original-name', $firstPath);
        Storage::disk('secretary_images')->assertExists($firstPath);

        auth()->logout();
        $this->getJson("/api/v1/secretaries/{$secretary->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer_preferences.configured', false)
            ->assertJsonPath('data.main_image.display', 'uploaded')
            ->assertJsonPath('data.main_image.creation_method_label', '自作');
        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/images/full_body', [
            'creation_method' => 'commissioned_or_permitted',
            'credit' => 'Commissioned artist',
        ])->assertOk()
            ->assertJsonPath('data.main_image.display', 'uploaded');
        auth()->logout();
        $this->getJson("/api/v1/secretaries/{$secretary->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer_preferences.configured', false)
            ->assertJsonPath('data.main_image.display', 'uploaded')
            ->assertJsonPath('data.main_image.creation_method_label', '依頼・使用許諾済み');

        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => false,
            'fallback' => 'silhouette',
        ])->assertOk();
        $this->actingAs($owner->refresh())->getJson("/api/v1/me/secretary?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath('data.profile.main_image.display', 'uploaded')
            ->assertJsonPath('data.profile.main_image.creation_method_label', '依頼・使用許諾済み');

        $this->actingAs($owner)->post('/api/v1/me/secretary/images/full_body', [
            'image' => UploadedFile::fake()->createWithContent('second.png', $this->portraitPng()),
            'creation_method' => 'self_made',
            'credit' => 'Second owner image',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.main_image.display', 'uploaded')
            ->assertJsonPath('data.images.full_body.editable_metadata.creation_method', 'self_made');
        $secondPath = (string) SecretaryImage::query()->where('secretary_id', $secretary->id)->where('slot', 'full_body')->value('path');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('secretary_images')->assertMissing($firstPath);
        Storage::disk('secretary_images')->assertExists($secondPath);
        $this->assertCount(1, Storage::disk('secretary_images')->allFiles('/'));

        $disk = Storage::disk('secretary_images');
        $failingDisk = Mockery::mock($disk)->makePartial();
        $failingDisk->shouldReceive('delete')->once()->with($secondPath)
            ->andThrow(new RuntimeException('forced cleanup failure'));
        Storage::shouldReceive('disk')->with('secretary_images')->andReturn($failingDisk);
        Log::spy();

        $this->actingAs($owner)->post('/api/v1/me/secretary/images/full_body', [
            'image' => UploadedFile::fake()->createWithContent('third.png', $this->portraitPng()),
            'creation_method' => 'ai_generated',
            'credit' => 'Generated for this profile',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.main_image.display', 'none')
            ->assertJsonPath('data.images.full_body.editable_metadata.creation_method', 'ai_generated');
        $thirdPath = (string) SecretaryImage::query()->where('secretary_id', $secretary->id)->where('slot', 'full_body')->value('path');
        $this->assertNotSame($secondPath, $thirdPath);
        $this->assertTrue($disk->exists($secondPath));
        $this->assertTrue($disk->exists($thirdPath));
        $this->assertCount(2, $disk->allFiles('/'));
        Log::shouldHaveReceived('error')->once()->with(
            'Secretary image replacement left an orphaned previous file.',
            Mockery::on(fn (array $context): bool => $context['secretary_id'] === $secretary->id
                && $context['old_path'] === $secondPath
                && $context['current_path'] === $thirdPath
                && $context['slot'] === 'full_body'
                && $context['exception_class'] === RuntimeException::class),
        );

        $this->actingAs($owner)->getJson("/api/v1/me/secretary?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath('data.profile.main_image.display', 'none')
            ->assertJsonPath('data.profile.main_image.url', null)
            ->assertJsonPath('data.profile.images.full_body.editable_metadata.creation_method', 'ai_generated');

        $viewer = User::factory()->create();
        $this->actingAs($viewer)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => true,
            'fallback' => 'silhouette',
        ])->assertOk();
        $this->actingAs($viewer->refresh())->getJson("/api/v1/secretaries/{$secretary->id}?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath('data.main_image.display', 'uploaded')
            ->assertJsonPath('data.main_image.creation_method_label', 'AI生成')
            ->assertJsonPath('data.main_image.credit', 'Generated for this profile');

        $this->actingAs($viewer)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => false,
            'fallback' => 'peridot',
        ])->assertOk();
        $this->actingAs($viewer->refresh())->getJson("/api/v1/secretaries/{$secretary->id}?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath('data.main_image.display', 'none')
            ->assertJsonPath('data.main_image.url', null);
    }

    public function test_saved_battle_image_lease_survives_replacement_until_retention_expiry_and_preserves_credit(): void
    {
        Storage::fake('secretary_images');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, '画像lease島', '画像lease島主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => '画像lease秘書'])->assertOk();

        $this->actingAs($owner)->post('/api/v1/me/secretary/images/full_body', [
            'image' => UploadedFile::fake()->createWithContent('lease-old.png', $this->portraitPng()),
            'creation_method' => 'self_made',
            'credit' => 'Lease credit',
        ], ['Accept' => 'application/json'])->assertOk();
        $secretary = $owner->secretary()->firstOrFail()->fresh(['images', 'user', 'undergroundProfile']);
        $oldPath = (string) $secretary->images->firstWhere('slot', 'full_body')?->path;
        $savedReference = app(SecretaryProfilePresenter::class)->resolveLargeImage($secretary, $owner);
        $this->assertSame('Lease credit', $savedReference['credit']);
        Storage::disk('secretary_images')->assertExists($oldPath);

        $profile = $secretary->undergroundProfile
            ?? UndergroundProfile::query()->firstOrCreate(['secretary_id' => $secretary->id]);
        $expiresAt = Carbon::now()->addHour();
        $requestId = (string) Str::uuid();
        $retention = app(SecretaryImageRetentionService::class);
        $reservationKey = $retention->reservationKey($profile->id, $requestId);
        $snapshot = ['player_image_references' => ['normal' => $savedReference]];
        $this->assertNotSame($reservationKey, $retention->reservationKey($profile->id + 1, $requestId));
        $this->assertSame(1, $retention->reserveSnapshotImages($reservationKey, $snapshot, $expiresAt));
        $this->assertTrue($retention->isRetained($oldPath));
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'secretary-image-retention-test',
            'activity_type' => UndergroundBattle::ACTIVITY_EXPLORATION,
            'activity_key' => 'shallow_caves',
            'encounter_key' => 'giant_rat',
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'xp_awarded' => 1,
            'shard_delta' => 0,
            'combat_level_before' => 1,
            'combat_level_after' => 1,
            'combat_xp_before' => 0,
            'combat_xp_after' => 0,
            'shard_balance_before' => 0,
            'shard_balance_after' => 0,
            'private_seed' => 1,
            'snapshot' => [
                'player_image_references' => ['normal' => $savedReference],
            ],
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => [],
            'expires_at' => $expiresAt,
        ]);
        $retention = app(SecretaryImageRetentionService::class);
        $this->assertSame(1, $retention->retainSnapshotImages($battle, $battle->snapshot));
        $this->assertDatabaseHas('underground_battle_image_references', [
            'reference_key' => $reservationKey, 'underground_battle_id' => $battle->id, 'path' => $oldPath,
        ]);
        $this->assertSame(0, $retention->reserveSnapshotImages($reservationKey, $snapshot, $expiresAt));
        $this->assertTrue($retention->isRetained($oldPath, $expiresAt->copy()->subSecond()));

        $this->actingAs($owner)->post('/api/v1/me/secretary/images/full_body', [
            'image' => UploadedFile::fake()->createWithContent('lease-new.png', $this->portraitPng()),
            'creation_method' => 'self_made',
            'credit' => 'New lease credit',
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('secretary_images')->assertExists($oldPath);
        $this->assertSame('Lease credit', $battle->fresh()->snapshot['player_image_references']['normal']['credit']);

        $this->assertSame(1, $retention->pruneExpired(1000, $expiresAt->copy()->addSecond()));
        $this->assertFalse($retention->isRetained($oldPath, $expiresAt->copy()->addSecond()));
        Storage::disk('secretary_images')->assertMissing($oldPath);
    }

    public function test_saved_ai_image_keeps_credit_but_uses_current_viewer_visibility_on_history(): void
    {
        Storage::fake('secretary_images');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, '画像history島', '画像history島主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => '画像history秘書'])->assertOk();
        $owner->forceFill([
            'show_ai_generated_secretary_images' => true,
            'secretary_image_fallback' => 'silhouette',
        ])->save();
        $this->actingAs($owner->refresh())->post('/api/v1/me/secretary/images/full_body', [
            'image' => UploadedFile::fake()->createWithContent('history-ai.png', $this->portraitPng()),
            'creation_method' => 'ai_generated',
            'credit' => 'History AI credit',
        ], ['Accept' => 'application/json'])->assertOk();

        $secretary = $owner->secretary()->firstOrFail()->fresh(['images', 'user']);
        $presenter = app(SecretaryProfilePresenter::class);
        $savedReference = $presenter->resolveLargeImage($secretary, $owner);
        $this->assertSame('uploaded', $savedReference['display']);
        $this->assertSame('History AI credit', $savedReference['credit']);
        $snapshot = [
            'player_image_references' => ['normal' => $savedReference],
            'party' => [
                'members' => [
                    'secretary:'.$secretary->id => ['image_references' => ['normal' => $savedReference]],
                ],
            ],
            'portrait_events' => [[
                'image_ref' => $savedReference,
                'image_refs' => ['normal' => $savedReference],
            ]],
        ];

        $visible = $presenter->filterSavedBattleImages($snapshot, $owner->refresh());
        $this->assertSame('uploaded', $visible['player_image_references']['normal']['display']);
        $this->assertSame('History AI credit', $visible['player_image_references']['normal']['credit']);

        $owner->forceFill(['show_ai_generated_secretary_images' => false])->save();
        $hidden = $presenter->filterSavedBattleImages($snapshot, $owner->refresh());
        $this->assertSame('none', $hidden['player_image_references']['normal']['display']);
        $this->assertNull($hidden['player_image_references']['normal']['url']);
        $this->assertSame('none', $hidden['party']['members']['secretary:'.$secretary->id]['image_references']['normal']['display']);
        $this->assertSame('none', $hidden['portrait_events'][0]['image_ref']['display']);
        $this->assertSame('History AI credit', $snapshot['player_image_references']['normal']['credit']);
    }

    public function test_nickname_is_limited_and_profile_presenter_exposes_canonical_compact_name(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, '愛称島', '愛称主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => '正式名称七文字'])->assertOk();
        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'biography' => '設定タブから愛称を変えても残る経歴',
        ])->assertOk();
        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'nickname' => '1234567',
        ])->assertUnprocessable()->assertJsonValidationErrors('nickname');
        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'nickname' => '123456',
        ])->assertOk()
            ->assertJsonPath('data.nickname', '123456')
            ->assertJsonPath('data.battle_display_name', '123456')
            ->assertJsonPath('data.biography', '設定タブから愛称を変えても残る経歴');
        $this->actingAs($owner)->getJson('/api/v1/me/secretary')->assertOk()
            ->assertJsonPath('data.header_label', '123456');
        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'nickname' => null,
        ])->assertOk()
            ->assertJsonPath('data.battle_display_name', '正式名称七…')
            ->assertJsonPath('data.biography', '設定タブから愛称を変えても残る経歴');
        $this->actingAs($owner)->getJson('/api/v1/me/secretary')->assertOk()
            ->assertJsonPath('data.header_label', '正式名称七…');
    }

    public function test_secretary_image_slots_have_independent_credit_and_aspect_contract(): void
    {
        Storage::fake('secretary_images');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, '画像slot島', '画像slot主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => '画像slot秘書'])->assertOk();

        $slots = [
            'icon' => 'icon-credit',
            'bust' => 'bust-credit',
            'full_body' => 'full-credit',
            'awakening_icon' => 'awakening-icon-credit',
            'awakening_bust' => 'awakening-bust-credit',
            'awakening_full_body' => 'awakening-full-credit',
        ];
        foreach ($slots as $slot => $credit) {
            $square = str_ends_with($slot, 'icon');
            $this->actingAs($owner)->post('/api/v1/me/secretary/images/'.$slot, [
                'image' => UploadedFile::fake()->createWithContent($slot.'.png', $square ? $this->png() : $this->portraitPng()),
                'creation_method' => 'self_made', 'credit' => $credit,
            ], ['Accept' => 'application/json'])->assertOk()
                ->assertJsonPath('data.images.'.$slot.'.credit', $credit);
        }
        $this->actingAs($owner)->post('/api/v1/me/secretary/images/bust', [
            'image' => UploadedFile::fake()->createWithContent('square.png', $this->png()),
            'creation_method' => 'self_made', 'credit' => 'bad-ratio',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->actingAs($owner)->post('/api/v1/me/secretary/images/icon', [
            'image' => UploadedFile::fake()->createWithContent('missing-credit.png', $this->png()),
            'creation_method' => 'self_made', 'credit' => '',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('credit');
        $secretary = $owner->secretary()->firstOrFail();
        $this->assertSame(array_keys($slots), SecretaryImage::query()
            ->where('secretary_id', $secretary->id)
            ->orderByRaw("array_position(ARRAY['icon','bust','full_body','awakening_icon','awakening_bust','awakening_full_body']::varchar[], slot)")
            ->pluck('slot')->all());
        $this->assertSame('icon-credit', SecretaryImage::query()->where('secretary_id', $secretary->id)->where('slot', 'icon')->value('credit'));
        $this->assertSame('awakening-icon-credit', SecretaryImage::query()->where('secretary_id', $secretary->id)->where('slot', 'awakening_icon')->value('credit'));
        $path = (string) SecretaryImage::query()
            ->where('secretary_id', $secretary->id)
            ->where('slot', 'icon')
            ->value('path');

        $this->actingAs($owner)->deleteJson('/api/v1/me/secretary/images/icon')
            ->assertOk()
            ->assertJsonPath('data.images.icon.display', 'none')
            ->assertJsonPath('data.images.icon.url', null);

        $this->assertDatabaseMissing('secretary_images', ['secretary_id' => $secretary->id, 'slot' => 'icon']);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $owner->id,
            'event_type' => 'secretary.image_slot_deleted',
            'subject_id' => $secretary->id,
        ]);
        Storage::disk('secretary_images')->assertMissing($path);
    }

    public function test_owner_can_edit_hidden_ai_slot_metadata_without_exposing_edit_fields_to_public_viewers(): void
    {
        Storage::fake('secretary_images');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, '非表示画像島', '非表示画像主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => '非表示画像秘書'])->assertOk();
        $owner->forceFill([
            'show_ai_generated_secretary_images' => false,
            'secretary_image_fallback' => 'silhouette',
        ])->save();

        $this->actingAs($owner->refresh())->post('/api/v1/me/secretary/images/icon', [
            'image' => UploadedFile::fake()->createWithContent('hidden-icon.png', $this->png()),
            'creation_method' => 'ai_generated',
            'credit' => 'Hidden icon credit',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.images.icon.display', 'none')
            ->assertJsonPath('data.images.icon.editable_metadata.creation_method', 'ai_generated')
            ->assertJsonPath('data.images.icon.editable_metadata.credit', 'Hidden icon credit')
            ->assertJsonPath('data.images.icon.source', 'slot');

        $this->actingAs($owner->refresh())->patchJson('/api/v1/me/secretary/images/icon', [
            'creation_method' => 'commissioned_or_permitted',
            'credit' => 'Updated hidden icon credit',
        ])->assertOk()
            ->assertJsonPath('data.images.icon.display', 'uploaded')
            ->assertJsonPath('data.images.icon.editable_metadata.creation_method', 'commissioned_or_permitted')
            ->assertJsonPath('data.images.icon.editable_metadata.credit', 'Updated hidden icon credit');

        auth()->logout();
        $secretary = $owner->secretary()->firstOrFail();
        $this->getJson('/api/v1/secretaries/'.$secretary->id.'?world_id='.$world->id)
            ->assertOk()
            ->assertJsonPath('data.images.icon.display', 'uploaded')
            ->assertJsonMissingPath('data.images.icon.editable_metadata');
    }

    public function test_portrait_preference_and_awakening_resolvers_fallback_to_registered_bust(): void
    {
        Storage::fake('secretary_images');
        $this->installSecretaryFallbackAssets('silhouette.png');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, 'portrait島', 'portrait主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => 'portrait秘書'])->assertOk();
        $owner->forceFill([
            'show_ai_generated_secretary_images' => true,
            'secretary_image_fallback' => 'silhouette',
        ])->save();
        $secretary = $owner->secretary()->firstOrFail()->fresh(['images', 'user']);
        $presenter = app(SecretaryProfilePresenter::class);
        $this->assertSame('silhouette', $presenter->resolveLargeImage($secretary, $owner)['display']);
        $this->actingAs($owner)->post('/api/v1/me/secretary/images/bust', [
            'image' => UploadedFile::fake()->createWithContent('bust.png', $this->portraitPng()),
            'creation_method' => 'self_made', 'credit' => 'bust-credit',
        ], ['Accept' => 'application/json'])->assertOk();
        $secretary = $owner->secretary()->firstOrFail()->fresh(['images', 'user']);
        $normal = $presenter->resolveLargeImage($secretary, $owner);
        $awakening = $presenter->resolveLargeImage($secretary, $owner, true);
        $this->assertSame('uploaded', $normal['display']);
        $this->assertSame('bust', $normal['slot']);
        $this->assertSame($normal['url'], $awakening['url']);
        $this->assertSame('bust-credit', $awakening['credit']);
        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/portrait-preference', ['portrait_preference' => 'bust'])
            ->assertOk()->assertJsonPath('data.portrait_preference', 'bust');
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true) ?: '';
    }

    private function portraitPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAAECAYAAABLLYUHAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAQSURBVBhXY2BgYPjPQBoAADAEAQBsu4qiAAAAAElFTkSuQmCC', true) ?: '';
    }

    private function installSecretaryFallbackAssets(string ...$filenames): void
    {
        $assetDirectory = storage_path('framework/testing/assets-'.Str::uuid());
        $peridotDirectory = $assetDirectory.DIRECTORY_SEPARATOR.'peridot';
        mkdir($peridotDirectory, 0777, true);
        foreach ($filenames as $filename) {
            file_put_contents($peridotDirectory.DIRECTORY_SEPARATOR.$filename, $this->png());
        }
        config([
            'hakoniwa.assets.path' => $assetDirectory,
            'hakoniwa.assets.base_url' => '/assets/hakoniwa-tiles',
            'hakoniwa.assets.themes.peridot' => 'peridot',
        ]);

        $this->beforeApplicationDestroyed(function () use ($assetDirectory, $peridotDirectory, $filenames): void {
            foreach ($filenames as $filename) {
                @unlink($peridotDirectory.DIRECTORY_SEPARATOR.$filename);
            }
            @rmdir($peridotDirectory);
            @rmdir($assetDirectory);
        });
    }
}
