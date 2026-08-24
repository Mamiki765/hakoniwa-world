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
use Illuminate\Http\UploadedFile;
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
        $this->assertSame(
            "全てが謎に包まれた、長耳の秘書。\n"
            ."かつては囚われの身になっていたが島主に救われ、後に才能を買われて秘書となった。\n"
            .'その身に不思議な力を宿している。',
            $secretary->profile_biography,
        );
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
            SecretarySkillCatalog::FINAL_DEFENSE_LINE => 6,
        ] as $skillKey => $level) {
            $secretary->skills()->where('skill_key', $skillKey)->update(['level' => $level]);
        }
        $secretary->update(['monster_experience' => 120]);

        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/profile', [
            'biography' => "海辺で出会った秘書。\n**この記号はMarkdownとして解釈しない。**",
        ])->assertOk()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.domestic_level', 18)
            ->assertJsonPath('data.secretary_level', 18)
            ->assertJsonPath('data.passive_level_total', 18)
            ->assertJsonPath('data.capacity_bonus_percent', 18)
            ->assertJsonPath('data.monster_experience', 120)
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
            ->assertJsonPath('data.domestic_level', 18)
            ->assertJsonPath('data.secretary_level', 18)
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

    public function test_main_image_reuses_safe_upload_boundary_replaces_the_old_file_and_honors_ai_suppression(): void
    {
        Storage::fake('secretary_images');
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        app(NationCreationService::class)->create($owner, $world, '画像秘書島', '画像島主');
        $this->actingAs($owner)->postJson('/api/v1/me/secretary/name', ['name' => '画像秘書'])->assertOk();
        $secretary = $owner->secretary()->firstOrFail();

        $this->actingAs($owner)->post('/api/v1/me/secretary/main-image', [
            'image' => UploadedFile::fake()->createWithContent(
                'dangerous.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            ),
            'creation_method' => 'self_made',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        Storage::disk('secretary_images')->assertDirectoryEmpty('/');

        $this->actingAs($owner)->post('/api/v1/me/secretary/main-image', [
            'image' => UploadedFile::fake()->createWithContent('first-original-name.png', $this->png()),
            'creation_method' => 'self_made',
            'credit' => 'Owner / all rights reserved',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.main_image.display', 'none')
            ->assertJsonPath('data.editable_image_metadata.creation_method', 'self_made');
        $firstPath = (string) $secretary->fresh()->main_image_path;
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\.png\z/', $firstPath);
        $this->assertStringNotContainsString('first-original-name', $firstPath);
        Storage::disk('secretary_images')->assertExists($firstPath);

        $this->actingAs($owner)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => false,
            'fallback' => 'silhouette',
        ])->assertOk();
        $this->actingAs($owner->refresh())->getJson("/api/v1/me/secretary?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath('data.profile.main_image.display', 'uploaded')
            ->assertJsonPath('data.profile.main_image.creation_method_label', '自作');

        $this->actingAs($owner)->post('/api/v1/me/secretary/main-image', [
            'image' => UploadedFile::fake()->createWithContent('second.png', $this->png()),
            'creation_method' => 'self_made',
            'credit' => 'Second owner image',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.main_image.display', 'uploaded')
            ->assertJsonPath('data.editable_image_metadata.creation_method', 'self_made');
        $secondPath = (string) $secretary->fresh()->main_image_path;
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

        $this->actingAs($owner)->post('/api/v1/me/secretary/main-image', [
            'image' => UploadedFile::fake()->createWithContent('third.png', $this->png()),
            'creation_method' => 'ai_generated',
            'credit' => 'Generated for this profile',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.main_image.display', 'none')
            ->assertJsonPath('data.editable_image_metadata.creation_method', 'ai_generated');
        $thirdPath = (string) $secretary->fresh()->main_image_path;
        $this->assertNotSame($secondPath, $thirdPath);
        $this->assertTrue($disk->exists($secondPath));
        $this->assertTrue($disk->exists($thirdPath));
        $this->assertCount(2, $disk->allFiles('/'));
        Log::shouldHaveReceived('error')->once()->with(
            'Secretary main image replacement left an orphaned previous file.',
            Mockery::on(fn (array $context): bool => $context['secretary_id'] === $secretary->id
                && $context['old_path'] === $secondPath
                && $context['current_path'] === $thirdPath
                && $context['exception_class'] === RuntimeException::class),
        );

        $this->actingAs($owner)->getJson("/api/v1/me/secretary?world_id={$world->id}")
            ->assertOk()
            ->assertJsonPath('data.profile.main_image.display', 'none')
            ->assertJsonPath('data.profile.main_image.url', null)
            ->assertJsonPath('data.profile.editable_image_metadata.creation_method', 'ai_generated');

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

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true) ?: '';
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
